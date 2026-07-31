# AEP Mission Control deployment

Target: `https://aep.anavasis.tech`

## Stack

- Caddy reverse proxy (TLS)
- PHP-FPM Mission Control API
- Static React SPA
- Persistent volume for missions, projects, runs, artifacts, auth

## Quick start

```bash
cp deploy/.env.example deploy/.env
# edit bootstrap password
cd deploy
docker compose up -d --build
```

Point DNS `aep.anavasis.tech` at the host. Caddy obtains certificates automatically.

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
