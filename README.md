# tag-api

## Dev (local)
Build and run with local Postgres (main/main/main):

```bash
docker compose -f docker-compose.dev.yml up -d --build
```

API will be available at:

```
http://localhost:8000
```

## Prod (GHCR deploy)
This repo is built and pushed to GHCR via GitHub Actions, then deployed on the server.
Required GitHub Secrets:

- `SSH_HOST`
- `SSH_USER`
- `SSH_PORT`
- `SSH_PRIVATE_KEY`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`

Optional (only if GHCR is private):

- `GHCR_USER`
- `GHCR_TOKEN`

## Nginx
Proxy `/api` to the container:

```nginx
location /api/ {
    proxy_pass http://127.0.0.1:8000/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```
