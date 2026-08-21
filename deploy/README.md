# AEP Mission Control deployment

Target: `https://aep.anavasis.tech`

Version: `1.0.1`

## Stack

- Caddy as an **internal HTTP** edge (SPA + `/api` reverse proxy) — does **not** bind host 80/443
- Mission Control API (PHP) on Docker network port `8080`
- Static React SPA
- Persistent volume for missions, projects, runs, artifacts, auth

## Deployment modes

### A. Standalone (future)

Intended for hosts where Docker may own public HTTP(S) directly.

Not the supported production path on shared Nginx VPS hosts. When re-enabled later, TLS would be terminated at an edge that publishes 80/443 (host Nginx or a dedicated proxy), not by reclaiming those ports from an existing production Nginx.

Current compose always publishes the proxy as loopback HTTP only (`127.0.0.1:8088` by default).

### B. Production behind existing Nginx + Certbot (supported)

Use when the host already runs Nginx on ports 80/443 for other sites.

1. Copy env and set a strong bootstrap password:

```bash
cp deploy/.env.example deploy/.env
# edit bootstrap password
```

2. Start AEP (from `deploy/`):

```bash
cd deploy
docker compose up -d --build
```

3. Confirm Docker does **not** own host 80/443. The proxy listens only on:

`127.0.0.1:8088` → container `:80`

Override with `AEP_PROXY_PORT` in `deploy/.env` if needed.

4. Point DNS `aep.anavasis.tech` at the host. Configure host Nginx + Certbot for that server name, for example:

```nginx
server {
    server_name aep.anavasis.tech;

    location / {
        proxy_pass http://127.0.0.1:8088;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

Then enable the site and obtain/renew certificates with Certbot as usual for other vhosts.

5. Traffic path:

```
Internet → Nginx(:80/:443, Certbot) → 127.0.0.1:8088 → Caddy(:80)
  /api/*  → api:8080  (Docker network, unchanged)
  /*      → SPA (/srv/ui, index.html fallback)
```

Existing websites on the same Nginx remain unaffected as long as only an additive AEP server block is added.

## Data layout

```
$AEP_DATA_ROOT/
  missions/
  projects/
  runs/
  artifacts/
  auth/
    users.json
    sessions/
```

## Local development (without Docker)

```bash
# API
AEP_DATA_ROOT=./var/data AEP_BOOTSTRAP_ADMIN_PASSWORD=changeme \
  php -S 127.0.0.1:8080 -t apps/mission-control-api/public

# UI
cd apps/mission-control-ui && npm install && npm run dev
```

Open `http://127.0.0.1:5173` (Vite proxies `/api` to `:8080`).
