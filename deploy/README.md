# Deploying the worker

The worker runs on any home lab host with outbound access to WordPress (HTTPS) and the AO engine. It needs no GPU, no volumes and no inbound ports. Each start polls WordPress once: if no run is due it exits within seconds.

## Image

Build for both architectures from the repository root:

```bash
docker buildx build --platform linux/amd64,linux/arm64 -f deploy/Dockerfile -t registry.example.internal/ao-quill-worker:0.1.0 --push .
```

## k3s

```bash
cp deploy/k8s/secret.example.yaml secret.yaml   # fill in, keep out of git
kubectl apply -f secret.yaml -f deploy/k8s/cronjob.yaml
```

`concurrencyPolicy: Forbid` keeps one pod at a time, and the plugin's lease keeps one run per day even if two pods ever overlap.

## systemd timer

```bash
sudo install -D -m 600 deploy/worker.env.example /etc/ao-quill/worker.env   # then edit it
sudo cp deploy/systemd/ao-quill-worker.service deploy/systemd/ao-quill-worker.timer /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now ao-quill-worker.timer
```

## Plain cron

```cron
*/15 * * * * docker run --rm --env-file /etc/ao-quill/worker.env registry.example.internal/ao-quill-worker:0.1.0 run >> /var/log/ao-quill-worker.log 2>&1
```

## Environment

| Variable | Required | Meaning |
|---|---|---|
| `AOQ_WP_URL` | yes | WordPress main site URL, https |
| `AOQ_WP_USER` | yes | `aoq-worker` |
| `AOQ_WP_APP_PASSWORD` | yes | Application Password of the worker user |
| `AOQ_AO_URL` | no | AO engine base URL. Normally set in WordPress (AO connection, D16); this overrides it |
| `AOQ_WP_CA_PEM` or `AOQ_WP_CA_FILE` | no | CA for a self-signed WordPress certificate |
| `AOQ_AO_MTLS_CERT_PEM`, `AOQ_AO_MTLS_KEY_PEM`, `AOQ_AO_MTLS_CA_PEM` or `AOQ_AO_MTLS_DIR` | no | Client certificate for AO. Normally stored in WordPress; these override it |
| `AOQ_AO_HEADERS_JSON` | no | Extra headers for AO |
| `AOQ_OVERLAY_DIR` | no | Overlay directory (set in the image) |
| `AOQ_MTLS_CACHE_DIR` | no | Where the certificate from WordPress is written for AO Reach (default `~/.cache/aoq/mtls`, mode 700/600) |
| `AOQ_HTTP_TIMEOUT` | no | WordPress request timeout in seconds (default 120) |
| `AOQ_SEARXNG_URL` | no (WordPress) | SearXNG base URL (JSON format on) behind the worker's `client.aoq_search` MCP |
| `AOQ_SEARXNG_CA_FILE` | no | CA for a SearXNG certificate signed by a private CA |
| `aoq-worker enroll [--ca-file ca.pem]` | | One-time enrollment: reads the AO enroll token from a hidden prompt, verifies AO against its CA, stores the certificate in WordPress |
| `AOQ_AO_IPV4_ONLY` | no (WordPress) | `1` resolves the AO host to IPv4 only (for a stale dual-stack DNS answer on the worker machine) |
| `AOQ_HTTP_USER_AGENT` | no (WordPress) | User-Agent for page fetches and image APIs; include a contact URL or email (Wikimedia refuses requests without one) |

## Network access

The worker serves search, fetch and image search to AO through the Reach tunnel (`docs/decisions.md` D14). Besides WordPress and AO it needs outbound HTTPS to SearXNG, to the public pages it researches, and to `api.openverse.org`, `commons.wikimedia.org` and `upload.wikimedia.org`. It never listens on a non-loopback interface; AO reaches its tools over the worker's own WebSocket.
