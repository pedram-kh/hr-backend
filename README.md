# hr-backend

Laravel backend for the HR knowledge platform — the **system of record**. Owns the
entire relational schema and all migrations, email-OTP auth, and the API for
`hr-frontend`. See `AGENTS.md` and the canonical specs in `hr-docs`.

This repo also hosts the local-dev **`docker-compose.yml`** (Postgres+pgvector,
MinIO, MailHog) — Sprint 0 review decision C1/C2.

## Requirements

- PHP 8.3+ (developed on 8.4), Composer
- Docker + Docker Compose (for the infra services)

## Setup (from a clean checkout)

```bash
# 1) Bring up the infra (Postgres+pgvector, MinIO, MailHog)
docker compose up -d
#    Postgres  -> localhost:5432
#    MinIO     -> localhost:9000 (console localhost:9001)
#    MailHog   -> SMTP localhost:1025, web UI http://localhost:8025

# 2) Install dependencies and configure env
composer install
cp .env.example .env
php artisan key:generate
#    Set SEED_ADMIN_EMAIL / SEED_EMPLOYEE_EMAIL to real inboxes you control
#    (for local dev the codes are visible in MailHog, so any address works).

# 3) Build the schema and seed
php artisan migrate
php artisan db:seed

# 4) Run the API (host, port 8000)
php artisan serve --port=8000
```

> Note: the app services run on the **host** in dev (review C1); only the three
> infra services live in `docker-compose.yml`. App containerization is out of
> scope for Sprint 0.

## Auth (email OTP — no passwords, no SSO)

- `POST /auth/request-code` `{ "email": "..." }` → always `200` (generic message).
  Generates a 6-digit code, stores only its bcrypt hash, invalidates prior codes,
  and emails it (synchronously, so it appears in MailHog immediately). Rate-limited
  per email (≈1/min, 5/hour).
- `POST /auth/verify-code` `{ "email": "...", "code": "123456" }` → on success
  returns `{ token, token_type, identity }`. Single-use, 10-min TTL, attempt-capped,
  rate-limited per email+IP. The token is a Sanctum bearer token with a ~24h TTL.
- `GET /me` (requires `Authorization: Bearer <token>`) → identity; for employees,
  the raw profile facets (convenio, province, job category, employment type). No
  computed scope/eligibility this sprint.

Try it locally: request a code, open MailHog at <http://localhost:8025>, copy the
code, verify it, then call `/me` with the returned token.

## Mail transport

Selected by `MAIL_MAILER` with no code change:

- **Local dev:** `MAIL_MAILER=smtp`, `MAIL_HOST=localhost`, `MAIL_PORT=1025` (MailHog).
- **Production:** `MAIL_MAILER=postmark` + `POSTMARK_TOKEN`.

## Schema

Every table in `hr-docs/architecture/data-model.md` is implemented as a migration
in `database/migrations`. `document_chunks.embedding` is `vector(1024)` (pgvector,
BGE-M3) with an HNSW index; that table is migrated here but read/written at runtime
by `hr-ai` only.

Enums are implemented as `varchar` + `CHECK` constraints (via Laravel's `enum()`
column), per the Sprint 0 plan.
