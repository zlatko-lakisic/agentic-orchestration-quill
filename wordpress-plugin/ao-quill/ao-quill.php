<?php
/**
 * Plugin Name:       AO Quill
 * Description:       Control plane for the AO Quill worker: settings, schedule, run log, and every write to the site.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Network:           true
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       ao-quill
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

define( 'AOQ_NAME', 'AO Quill' );
define( 'AOQ_SLUG', 'ao-quill' );
define( 'AOQ_VERSION', '0.1.0' );
define( 'AOQ_DB_VERSION', '3' );
define( 'AOQ_FILE', __FILE__ );
define( 'AOQ_DIR', __DIR__ );

require_once AOQ_DIR . '/includes/class-aoq-schema.php';
require_once AOQ_DIR . '/includes/class-aoq-capabilities.php';
require_once AOQ_DIR . '/includes/adapters/interface-aoq-translation-adapter.php';
require_once AOQ_DIR . '/includes/adapters/class-aoq-multisite-adapter.php';
require_once AOQ_DIR . '/includes/adapters/interface-aoq-seo-adapter.php';
require_once AOQ_DIR . '/includes/adapters/class-aoq-null-seo-adapter.php';
require_once AOQ_DIR . '/includes/adapters/class-aoq-aioseo-adapter.php';
require_once AOQ_DIR . '/includes/class-aoq-install.php';
require_once AOQ_DIR . '/includes/class-aoq-settings.php';
require_once AOQ_DIR . '/includes/class-aoq-runs.php';
require_once AOQ_DIR . '/includes/class-aoq-worker.php';
require_once AOQ_DIR . '/includes/class-aoq-steps.php';
require_once AOQ_DIR . '/includes/class-aoq-ao-connection.php';
require_once AOQ_DIR . '/includes/class-aoq-profiles.php';
require_once AOQ_DIR . '/includes/class-aoq-marking.php';
require_once AOQ_DIR . '/includes/class-aoq-inventory.php';
require_once AOQ_DIR . '/includes/class-aoq-media.php';
require_once AOQ_DIR . '/includes/class-aoq-commit.php';
require_once AOQ_DIR . '/includes/class-aoq-abilities.php';
require_once AOQ_DIR . '/includes/class-aoq-instagram.php';
require_once AOQ_DIR . '/includes/class-aoq-language-links.php';
require_once AOQ_DIR . '/includes/class-aoq-plugin.php';

register_activation_hook( __FILE__, array( 'AOQ_Install', 'activate' ) );

AOQ_Plugin::boot();
