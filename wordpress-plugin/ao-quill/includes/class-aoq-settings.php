<?php
/**
 * Network settings, validation, and the config handed to the worker.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Settings {

	const OPTION         = 'aoq_settings';
	const CATALOG_OPTION = 'aoq_catalog';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$agents = array(
			'site_profile'    => 'client.aoq_analyst',
			'style_profile'   => 'client.aoq_analyst',
			'topics'          => 'client.aoq_analyst',
			'novelty'         => 'client.aoq_analyst',
			'research'        => 'client.aoq_analyst',
			'structurer'      => 'client.aoq_structurer',
			'outline'         => 'client.aoq_analyst',
			'draft'           => 'client.aoq_writer',
			'taxonomy'        => 'client.aoq_analyst',
			'image_shortlist' => 'client.aoq_analyst',
			'image_vision'    => 'client.aoq_vision',
			'qa_grammar'      => 'client.aoq_editor',
			'qa_facts'        => 'client.aoq_editor',
			'qa_style'        => 'client.aoq_editor',
			'qa_seo'          => 'client.aoq_editor',
			'translate'       => 'client.aoq_writer',
			'back_translate'  => 'client.aoq_editor',
			'translation_qa'  => 'client.aoq_editor',
			'quote_verifier'  => 'aoq_quote_verifier',
			'text_checks'     => 'aoq_text_checks',
		);
		return array(
			'site_brief'                => '',
			// Search interest (Google Trends and Google suggestions, no account needed).
			'trend_seeds'               => array( 'Mostar', 'Herzegovina', 'Blagaj', 'Kravice', 'Pocitelj', 'Medjugorje', 'Neretva', 'Bosnian food' ),
			'trend_geos'                => array( 'BA', 'HR', 'DE', 'AT', 'GB', 'US' ),
			'schedule_time'             => '07:00',
			'timezone'                  => 'UTC',
			'publish_mode'              => 'pending',
			'excluded_blog_ids'         => array(),
			'author_user_id'            => 0,
			'agents'                    => $agents,
			'mcp'                       => array(
				// Served by the worker over the AO Reach tunnel (docs/decisions.md D14).
				'search'       => 'client.aoq_search',
				'fetch'        => 'client.aoq_fetch',
				'stock_images' => 'client.aoq_images',
			),
			'thresholds'                => array(
				'grammar'              => 0.8,
				'facts'                => 0.9,
				'style'                => 0.7,
				'seo'                  => 0.7,
				'structure'            => 0.8,
				'translation_grammar'  => 0.8,
				'translation_fidelity' => 0.8,
				'translation_style'    => 0.7,
			),
			'originality_max_overlap'   => 0.15,
			'max_revision_loops'        => 3,
			'min_words'                 => 300,
			'min_verified_claims'       => 5,
			'min_human_posts_for_style' => 5,
			'inline_image_count'        => 1,
			'image_sources'             => array(
				array( 'id' => 'media_library', 'enabled' => true, 'order' => 1 ),
				array( 'id' => 'stock', 'enabled' => false, 'order' => 2 ),
				array( 'id' => 'web_search', 'enabled' => false, 'order' => 3 ),
				array( 'id' => 'ai_generated', 'enabled' => false, 'order' => 4 ),
			),
			'media_candidate_limit'     => 40,
			'image_min_score'           => 6,
			'featured_min_score'        => 8,
			'fallback_image_id'         => 0,
			'ao_priority'               => 'background',
			'stage_timeout_seconds'     => 3600,
			'lease_minutes'             => 720,
		);
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored   = get_site_option( self::OPTION, array() );
		$defaults = self::defaults();
		$out      = array_merge( $defaults, is_array( $stored ) ? $stored : array() );
		foreach ( array( 'agents', 'mcp', 'thresholds' ) as $group ) {
			$out[ $group ] = array_merge( $defaults[ $group ], is_array( $out[ $group ] ) ? $out[ $group ] : array() );
		}
		$out['excluded_blog_ids'] = array_values( array_map( 'intval', (array) $out['excluded_blog_ids'] ) );
		// Image sources added in later versions join the stored list, after the existing ones.
		$have = wp_list_pluck( (array) $out['image_sources'], 'id' );
		$last = $have ? max( wp_list_pluck( (array) $out['image_sources'], 'order' ) ) : 0;
		foreach ( $defaults['image_sources'] as $src ) {
			if ( ! in_array( $src['id'], $have, true ) ) {
				$src['order']           = min( 10, ++$last );
				$out['image_sources'][] = $src;
			}
		}
		return $out;
	}

	/**
	 * Sanitize and validate submitted settings. Invalid agent choices are rejected
	 * and the previous value is kept.
	 *
	 * @param array $input Raw input.
	 * @return array{settings: array, errors: string[]}
	 */
	public static function sanitize( array $input ) {
		$current = self::get();
		$out     = $current;
		$errors  = array();

		$out['site_brief'] = isset( $input['site_brief'] ) ? sanitize_textarea_field( wp_unslash( $input['site_brief'] ) ) : $current['site_brief'];
		foreach ( array( 'trend_seeds' => 30, 'trend_geos' => 10 ) as $key => $max ) {
			if ( isset( $input[ $key ] ) ) {
				$items = array_filter( array_map( 'trim', preg_split( '/[,\n]+/', sanitize_textarea_field( wp_unslash( $input[ $key ] ) ) ) ) );
				if ( 'trend_geos' === $key ) {
					$items = array_filter( array_map( 'strtoupper', $items ), function ( $g ) { return (bool) preg_match( '/^[A-Z]{2}$/', $g ); } );
				}
				$out[ $key ] = array_values( array_slice( array_unique( array_map( function ( $v ) { return substr( $v, 0, 80 ); }, $items ) ), 0, $max ) );
			}
		}

		$time = isset( $input['schedule_time'] ) ? sanitize_text_field( wp_unslash( $input['schedule_time'] ) ) : $current['schedule_time'];
		if ( preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time ) ) {
			$out['schedule_time'] = $time;
		} else {
			$errors[] = __( 'Run time must be HH:MM.', 'ao-quill' );
		}

		$tz = isset( $input['timezone'] ) ? sanitize_text_field( wp_unslash( $input['timezone'] ) ) : $current['timezone'];
		if ( in_array( $tz, timezone_identifiers_list(), true ) ) {
			$out['timezone'] = $tz;
		} else {
			$errors[] = __( 'Unknown timezone.', 'ao-quill' );
		}

		if ( isset( $input['publish_mode'] ) && in_array( $input['publish_mode'], array( 'pending', 'publish' ), true ) ) {
			$out['publish_mode'] = $input['publish_mode'];
		}

		if ( array_key_exists( 'excluded_blog_ids', $input ) || isset( $input['languages_submitted'] ) ) {
			$out['excluded_blog_ids'] = array_values( array_map( 'intval', (array) ( $input['excluded_blog_ids'] ?? array() ) ) );
		}

		if ( isset( $input['author_user_id'] ) ) {
			$author = (int) $input['author_user_id'];
			if ( $author > 0 && get_userdata( $author ) && $author !== AOQ_Install::worker_user_id() ) {
				$out['author_user_id'] = $author;
			} else {
				$errors[] = __( 'Post author must be an existing user other than the worker user.', 'ao-quill' );
			}
		}

		foreach ( array_keys( $current['thresholds'] ) as $key ) {
			if ( isset( $input['thresholds'][ $key ] ) ) {
				$out['thresholds'][ $key ] = max( 0.0, min( 1.0, (float) $input['thresholds'][ $key ] ) );
			}
		}
		$ints = array(
			'max_revision_loops'        => array( 1, 10 ),
			'min_words'                 => array( 50, 5000 ),
			'min_verified_claims'       => array( 1, 50 ),
			'min_human_posts_for_style' => array( 1, 100 ),
			'inline_image_count'        => array( 0, 6 ),
			'media_candidate_limit'     => array( 10, 200 ),
			'image_min_score'           => array( 0, 10 ),
			'featured_min_score'        => array( 0, 10 ),
			'fallback_image_id'         => array( 0, PHP_INT_MAX ),
			'stage_timeout_seconds'     => array( 30, 14400 ),
			'lease_minutes'             => array( 30, 1440 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['originality_max_overlap'] ) ) {
			$out['originality_max_overlap'] = max( 0.0, min( 1.0, (float) $input['originality_max_overlap'] ) );
		}
		if ( isset( $input['ao_priority'] ) && in_array( $input['ao_priority'], array( 'background', 'low', 'normal' ), true ) ) {
			$out['ao_priority'] = $input['ao_priority'];
		}

		if ( isset( $input['image_sources'] ) && is_array( $input['image_sources'] ) ) {
			$sources = array();
			foreach ( $current['image_sources'] as $src ) {
				$id      = $src['id'];
				$posted  = $input['image_sources'][ $id ] ?? array();
				$enabled = ! empty( $posted['enabled'] );
				$cap     = AOQ_Capabilities::get( 'images_' . $id );
				if ( $enabled && ( ! $cap || 'shipped' !== $cap['status'] ) ) {
					$enabled  = false;
					$errors[] = sprintf(
						/* translators: %s: image source */
						__( 'Image source "%s" is not available yet and stays disabled.', 'ao-quill' ),
						$id
					);
				}
				$sources[] = array(
					'id'      => $id,
					'enabled' => $enabled,
					'order'   => max( 1, min( 10, (int) ( $posted['order'] ?? $src['order'] ) ) ),
				);
			}
			usort(
				$sources,
				static function ( $a, $b ) {
					return $a['order'] <=> $b['order'];
				}
			);
			$out['image_sources'] = $sources;
		}

		if ( isset( $input['mcp'] ) && is_array( $input['mcp'] ) ) {
			foreach ( array( 'search', 'fetch', 'stock_images' ) as $key ) {
				if ( isset( $input['mcp'][ $key ] ) ) {
					$value              = sanitize_text_field( wp_unslash( $input['mcp'][ $key ] ) );
					$out['mcp'][ $key ] = ( 'stock_images' === $key && '' === $value ) ? null : $value;
				}
			}
		}

		if ( isset( $input['agents'] ) && is_array( $input['agents'] ) ) {
			$proposed = $out['agents'];
			foreach ( array_keys( $current['agents'] ) as $role ) {
				if ( isset( $input['agents'][ $role ] ) ) {
					$proposed[ $role ] = sanitize_text_field( wp_unslash( $input['agents'][ $role ] ) );
				}
			}
			$agent_errors = self::agent_problems( $proposed );
			if ( $agent_errors ) {
				$errors = array_merge( $errors, $agent_errors );
				$errors[] = __( 'Agent choices were not saved.', 'ao-quill' );
			} else {
				$out['agents'] = $proposed;
			}
		}

		return array(
			'settings' => $out,
			'errors'   => $errors,
		);
	}

	/**
	 * Save settings.
	 *
	 * @param array $settings Sanitized settings.
	 */
	public static function save( array $settings ) {
		update_site_option( self::OPTION, $settings );
		AOQ_Plugin::translation_adapter()->reset();
	}

	/**
	 * Last catalog the worker reported.
	 *
	 * @return array{agents: array, mcps: array, generated_at: ?string, reported_at: ?string}
	 */
	public static function catalog() {
		$cat = get_site_option( self::CATALOG_OPTION, array() );
		return array_merge(
			array(
				'agents'       => array(),
				'mcps'         => array(),
				'generated_at' => null,
				'reported_at'  => null,
			),
			is_array( $cat ) ? $cat : array()
		);
	}

	/**
	 * Catalog agent by ID.
	 *
	 * @param string $id Agent provider ID.
	 * @return array|null
	 */
	public static function catalog_agent( $id ) {
		foreach ( self::catalog()['agents'] as $agent ) {
			if ( $agent['id'] === $id ) {
				return $agent;
			}
		}
		return null;
	}

	/**
	 * Validate agent choices against the reported catalog.
	 *
	 * @param array $agents Role => agent ID.
	 * @return string[]
	 */
	public static function agent_problems( array $agents ) {
		$problems = array();
		$catalog  = self::catalog();
		if ( empty( $catalog['agents'] ) ) {
			return array( __( 'The worker has not reported the AO catalog yet, so agent choices cannot be checked.', 'ao-quill' ) );
		}
		$enums         = AOQ_Schema::enums();
		$deterministic = $enums['deterministic_roles'];
		foreach ( $enums['agent_roles'] as $role ) {
			$id = $agents[ $role ] ?? '';
			if ( '' === $id ) {
				/* translators: %s: pipeline role */
				$problems[] = sprintf( __( 'No agent chosen for %s.', 'ao-quill' ), $role );
				continue;
			}
			$agent = self::catalog_agent( $id );
			if ( ! $agent ) {
				/* translators: 1: agent ID, 2: role */
				$problems[] = sprintf( __( 'Agent %1$s (%2$s) is not in the AO catalog.', 'ao-quill' ), $id, $role );
				continue;
			}
			$is_det = in_array( $role, $deterministic, true );
			if ( $is_det !== (bool) $agent['deterministic'] ) {
				$problems[] = $is_det
					/* translators: %s: role */
					? sprintf( __( '%s must be a deterministic AO agent.', 'ao-quill' ), $role )
					/* translators: %s: role */
					: sprintf( __( '%s must be a model agent, not a deterministic one.', 'ao-quill' ), $role );
			}
			if ( 'image_vision' === $role && empty( $agent['vision'] ) ) {
				$problems[] = __( 'The image vision agent must use a vision model.', 'ao-quill' );
			}
		}
		$draft = self::catalog_agent( $agents['draft'] ?? '' );
		if ( $draft ) {
			foreach ( $enums['qa_roles'] as $role ) {
				$qa = self::catalog_agent( $agents[ $role ] ?? '' );
				if ( $qa && $qa['family'] === $draft['family'] ) {
					/* translators: 1: role, 2: provider family */
					$problems[] = sprintf( __( '%1$s uses the same provider family (%2$s) as the drafting agent. QA must use a different family.', 'ao-quill' ), $role, $draft['family'] );
				}
			}
		}
		return $problems;
	}

	/**
	 * Everything that blocks a run. Empty when a run can proceed.
	 *
	 * @return string[]
	 */
	public static function problems() {
		$s        = self::get();
		$problems = self::agent_problems( $s['agents'] );
		$catalog  = self::catalog();
		$mcp_keys = array( 'search', 'fetch' );
		foreach ( $s['image_sources'] as $src ) {
			if ( 'stock' === $src['id'] && $src['enabled'] ) {
				$mcp_keys[] = 'stock_images';
			}
		}
		foreach ( $mcp_keys as $key ) {
			if ( empty( $s['mcp'][ $key ] ) || ! in_array( $s['mcp'][ $key ], $catalog['mcps'], true ) ) {
				/* translators: 1: purpose, 2: MCP provider ID */
				$problems[] = sprintf( __( 'The %1$s MCP provider "%2$s" is not in the AO catalog.', 'ao-quill' ), $key, $s['mcp'][ $key ] );
			}
		}
		if ( ! $s['author_user_id'] || ! get_userdata( $s['author_user_id'] ) ) {
			$problems[] = __( 'Choose a post author.', 'ao-quill' );
		}
		if ( ! AOQ_Install::worker_user_id() ) {
			$problems[] = __( 'The worker user is missing. Deactivate and network activate the plugin again.', 'ao-quill' );
		}
		$shipped = false;
		foreach ( $s['image_sources'] as $src ) {
			$cap = AOQ_Capabilities::get( 'images_' . $src['id'] );
			if ( $src['enabled'] && $cap && 'shipped' === $cap['status'] ) {
				$shipped = true;
			}
		}
		if ( ! $shipped ) {
			$problems[] = __( 'No shipped image source is enabled. Enable media library reuse or stock images.', 'ao-quill' );
		}
		return array_merge( $problems, AOQ_AO_Connection::problems(), AOQ_Plugin::translation_adapter()->problems() );
	}

	/**
	 * Config for the worker, shaped by contracts/abilities/get-config.output.json.
	 *
	 * @param string|null $run_id Run ID or null for a dry run.
	 * @return array
	 */
	public static function worker_config( $run_id ) {
		$s       = self::get();
		$adapter = AOQ_Plugin::translation_adapter();
		$langs   = array();
		foreach ( $adapter->languages() as $lang ) {
			$langs[] = $lang;
		}
		return array(
			'run_id'                    => $run_id,
			'site_brief'                => (string) $s['site_brief'],
			'trend_seeds'               => array_values( (array) $s['trend_seeds'] ),
			'trend_geos'                => array_values( (array) $s['trend_geos'] ),
			'publish_mode'              => $s['publish_mode'],
			'timezone'                  => $s['timezone'],
			'languages'                 => $langs,
			'primary_language'          => $adapter->primary_language(),
			'agents'                    => $s['agents'],
			'mcp'                       => array(
				'search'       => (string) $s['mcp']['search'],
				'fetch'        => (string) $s['mcp']['fetch'],
				'stock_images' => $s['mcp']['stock_images'],
			),
			'thresholds'                => array_map( 'floatval', $s['thresholds'] ),
			'originality_max_overlap'   => (float) $s['originality_max_overlap'],
			'max_revision_loops'        => (int) $s['max_revision_loops'],
			'min_words'                 => (int) $s['min_words'],
			'min_verified_claims'       => (int) $s['min_verified_claims'],
			'min_human_posts_for_style' => (int) $s['min_human_posts_for_style'],
			'inline_image_count'        => (int) $s['inline_image_count'],
			'image_sources'             => $s['image_sources'],
			'media_candidate_limit'     => (int) $s['media_candidate_limit'],
			'image_min_score'           => (float) $s['image_min_score'],
			'featured_min_score'        => (float) $s['featured_min_score'],
			'fallback_image'            => self::fallback_image_key( (int) $s['fallback_image_id'] ),
			'ao_priority'               => $s['ao_priority'],
			'stage_timeout_seconds'     => (int) $s['stage_timeout_seconds'],
			'instagram'                 => AOQ_Instagram::worker_config(),
		);
	}

	/**
	 * The fallback image as a media key ("blog:id") on the main site, or null when unset or not an image.
	 *
	 * @param int $id Attachment ID on the main site.
	 * @return string|null
	 */
	public static function fallback_image_key( $id ) {
		if ( $id <= 0 ) {
			return null;
		}
		$blog = get_main_site_id();
		switch_to_blog( $blog );
		$ok = wp_attachment_is_image( $id );
		restore_current_blog();
		return $ok ? $blog . ':' . $id : null;
	}
}
