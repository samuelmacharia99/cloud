# Laravel + Next.js sidecar stack

When a Laravel application hosting service has a Next.js app under `/app/frontend`, Talksasa provisions **one Compose project** with connected containers:

| Service   | Role                                      | Public?        |
|-----------|-------------------------------------------|----------------|
| `edge`    | Path router (`/api` → Laravel, else Next) | Yes (assigned port) |
| `backend` | Laravel API (`container_name` unchanged)  | Internal only  |
| `frontend`| Next.js                                   | Internal only  |
| `db`      | Database sidecar (if selected)            | Internal only  |

Browser traffic stays same-origin on your custom domain. Internal calls use `http://backend:8000`.

## Git pull (recommended workflow)

One git sync onto the **shared host volume** (`/opt/talksasa/containers/.../app`), then work splits by container:

1. **sync** — clone/pull the monorepo (backend + frontend) onto the shared volume  
2. **composer / environment / migrations** — run inside the **PHP backend** container  
3. **frontend** — `npm install` + `next build` inside the **Node frontend** sidecar  
4. **restart frontend (+ edge)** — PHP backend stays up; new UI is picked up  

Domains (bind / SSL) stay on **this Talksasa service**. Nginx points at the service `assigned_port`, which is published by **edge** (not a second product). You manage domains in the Domains tab of the PHP app service; you do not bind domains separately to the Node container.

```text
Git tab Pull
    → shared /app volume
    → backend: composer + migrate
    → frontend: npm build (Node sidecar)
    → restart frontend + edge
    → domains unchanged (same service / assigned_port)
```

## Redeploying an existing app (e.g. service 163)

1. Deploy this Talksasa platform release to the control plane.
2. On the service → **Redeploy stack** (keep database).
3. Wait for frontend npm install/build, then Compose rewrite to sidecars.
4. Confirm `docker compose ps` shows `backend`, `frontend`, `edge`, and `db`.
5. Set public URLs if missing: `FRONTEND_URL`, `APP_URL`, `NEXT_PUBLIC_APP_URL`, `NEXT_PUBLIC_API_URL` (Environment tab or Files).
6. Hard-refresh the browser / unregister any old service worker.

## Environment keys

- **Public:** `APP_URL`, `FRONTEND_URL`, `NEXT_PUBLIC_APP_URL`, `NEXT_PUBLIC_API_URL`, `API_URL`
- **Internal:** `INTERNAL_API_URL=http://backend:8000`, `BACKEND_URL=http://backend:8000`
