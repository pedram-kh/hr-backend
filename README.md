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

# 4) Import the convenio registry (territories / sectors / convenios)
php artisan registry:import        # reads data/01_listado_convenios.xlsx (idempotent)

# 5) Run the API (host, port 8000)
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
  the raw profile facets (convenio, territory, job category, employment type). No
  computed scope/eligibility this sprint.

Try it locally: request a code, open MailHog at <http://localhost:8025>, copy the
code, verify it, then call `/me` with the returned token.

## Registry import (Sprint 1)

```bash
php artisan registry:import [path]
```

Imports `data/01_listado_convenios.xlsx` (sheet `LABOUR AGREEMENTS`) into the
controlled vocabulary + registry, parsing **by header name**
(`NUMERO`, `CONVENIO`, `PROVINCIA`, `HORAS ANUALES`, `HORAS SEMANA`, `NUMERO A3`,
`COMPLEMENTO IT`). Idempotent (keyed on `numero`). It:

- classifies each territory `level` from the `PROVINCIA` column
  (`ESTATAL`→national, `ANDALUCIA`→regional, else provincial — Andalucía COEAS
  resolves to a **regional** territory, code `71`);
- populates Basque/Spanish territory `aliases` (`Bizkaia`/`Vizcaya`,
  `Gipuzkoa`/`Guipúzcoa`, `Araba`/`Álava`, plus the sheet's spelling) so the
  filename parser never false-conflicts;
- preserves multi-value headline cells (`1742 (1698)`, `39/35`) verbatim in
  `convenios.notes` (typed numeric columns only for single clean values);
- supersedes the Sprint 0 DEV FIXTURE rows once a real convenio exists.

> `CONVENIOS 2026.xls` is **not** a registry (it's a free-text human status note,
> no numbers/structure) and is intentionally **not** imported this sprint.

## Document ingestion (Sprint 1/2a — admin only)

Admin-only API (Sanctum bearer + admin guard). **PDF prose + salary `.xlsx`**
(ADR-0014); `.doc/.docx` and `.xls` remain out of scope.

- `POST /admin/documents/upload` — multipart `files[]` + `relative_paths[]`
  (folder upload). For each **PDF** it hashes the bytes (sha256 idempotency key),
  stores the original to S3, calls **hr-ai `/extract`** (ADR-0010) for per-page
  text + page images, then writes `documents` + `document_pages`, the
  `tag_events` provenance, and any `document_review_tasks`. A **salary `.xlsx`**
  (Sprint 2a) is stored to S3 and typed `salary_tables` with **no** `/extract`
  call and **no** pages (its rows are imported separately by `salary:import`);
  because most salary filenames lack a `numero` it lands `under_review` for
  deliberate convenio assignment (ADR-0014). `.docx`/`.xls` are skipped.
- `GET /admin/documents` — verification table; filter by
  `tagging_status`/`territory_id`/`sector_id`/`convenio_id`/`document_type_id`
  and `conflicts_only`. Flags conflicts and **empty-text** (scanned) PDFs.
- `GET /admin/documents/{uuid}` — detail: tags, provenance timeline, review
  tasks (with `reason` + raw unmatched values), source pages.
- `POST /admin/documents/{uuid}/confirm` — mark `verified`, resolve open tasks.
- `PATCH /admin/documents/{uuid}/facets/{facet}` — re-assign `convenio` or
  `document_type` from the controlled vocabulary (writes provenance).
- `GET /admin/documents/{uuid}/pages/{page}/image` — temporary S3 URL.
- `GET /admin/vocabulary/{territories|sectors|convenios|document_types}`.

The shared `X-Internal-Token` (`HR_AI_INTERNAL_TOKEN`) guards the hr-backend ↔
hr-ai call. The deterministic filename parser handles both validity formats
(`YYYYYYYY` and `YYYY_YYYY`), the `Antiguo` subfolder (→ `historical`), national
law (`ESTATUTO…` → `national_law`, no numero is not a conflict), and conflict
detection (territory/sector/convenio disagreements → `under_review`).

## Retrieval substrate (Sprint 2a — ingestion → vectors)

`hr-backend` owns scope resolution + all DB writes; `hr-ai` writes only
`document_chunks` (via a dedicated, scoped `hr_ai` Postgres role created by
migration `2026_06_22_100001_create_hr_ai_role_and_chunk_indexes` — ADR-0007 at
the database). New CLI commands (not UI):

```bash
# Bulk-ingest the province-foldered corpus (reuses the Sprint-1 ingestor;
# ignores __MACOSX/, CONVENIOS 2026.xls, .doc/.docx). PDFs + salary .xlsx.
php artisan documents:ingest-folder [path]      # default data/all-files

# Chunk + embed in-scope PROSE docs → document_chunks (hr-ai writes; this app
# resolves + passes each document's scope). Selection: document_type ∈
# {convenio_text, national_law, partial_agreement}, retrieval_status ∈
# {active, historical}, tagging_status ≠ under_review (ADR-0013). Run the hr-ai
# BGE-M3 sanity test FIRST and eyeball the stress gates before a bulk run.
php artisan chunks:embed [--document=<uuid>] [--dry-run]

# Import salary from .xlsx (hr-ai /extract-salary returns rows; this app writes
# salary_tables/_rows + convenio_job_categories). Deliberate, logged, idempotent.
# Lists pending-convenio docs (ADR-0014, catch 4) and the coverage gaps.
php artisan salary:import [--document=<uuid>]

# Verification harness: resolved scope + eligible prose chunks (scores + source)
# + eligible salary rows, for a profile + question + date. Asserts full recall.
php artisan retrieval:probe --convenio=<numero> --question="..." [--date=YYYY-MM-DD] \
    [--job-category="..."] [--mode=both|prose|salary] [--include-historical] [--k=8]
php artisan retrieval:probe --email=<employee-email> --question="..."
```

The salary→convenio association rides the Sprint-1 tagging path; a numero-less
salary `.xlsx` is assigned a convenio via
`PATCH /admin/documents/{uuid}/facets/convenio` before `salary:import` populates
its rows. Salary is relational/SQL, never embedded (ADR-0006); a convenio whose
salary is PDF-only surfaces as a **coverage gap**, not a blank (ADR-0014).

## Scoped RAG chat — the answer vertical (Sprint 2b-1, completed in 2b-2)

The first employee surface. `hr-backend` resolves scope (deterministic, no LLM),
owns the **answer-or-escalate decision**, and owns **all** DB writes
(`chat_sessions`, `chat_messages`, `message_citations`, `message_traces`,
`escalation_cards`, `answer_model_settings`). `hr-ai` only routes, retrieves,
synthesises, and grounds (ADR-0007/0015/0016).

- `POST /chat/message` (employee) — `{ question, session_uuid?,
  selected_job_category_id? }` → a scoped **cited** answer, a **salary** answer, a
  single-turn **category pick**, or an honest **escalation**. The full path
  (`ChatService`): scope resolve → **`GuardrailService`** (hardcoded baseline;
  fires *before* the router and any `hr-ai` call) → **`RouterService`** (ADR-0016)
  → branch:
  - **salary** → **`SalaryAnswerService`** (SQL over `salary_tables` /
    `salary_table_rows`, exact + year-aligned per ADR-0006): category from profile
    or a **constrained single-turn pick** (`needs_category`, FK-validated,
    unverified, shown "según tu indicación"); coverage gap → escalate
    `salary_coverage_gap`. Skips synthesis/grounding (SQL-grounded by construction).
  - **prose** → recall-hardened `/retrieve` (sub-query union + national-law pass) →
    pre-synthesis floor (Check A) → **`hr-ai /synthesise`** → Check B → figure-guard
    pre-check → **`GroundingService` (`hr-ai /ground`)** per-claim entailment gate →
    answer-or-escalate.
  - **off_domain** → escalate `off_domain`.
- **Router** (`RouterService`, ADR-0016): a deterministic salary pre-classifier
  (the patterns moved out of `GuardrailService`) short-circuits obvious salary with
  no LLM call; otherwise `/route` (small `HR_AI_ROUTER_MODEL`). **Fail-safe**: low
  confidence (`HR_ROUTER_CONFIDENCE_FLOOR`) / error / no key → the safe prose path.
  The decision lands in `trace.router_decision`.
- **Authority precedence** (ADR-0015): `ChatService` orders convenio chunks before
  `national_law` and labels each with `authority_level`; the synthesis prompt makes
  the convenio govern where it speaks and the Estatuto the gap-filling baseline. The
  trace records `authority_used`.
- **The gate** (`config/hr.php`, named + conservative, Sprint-6-exposable but
  additive-only): prose answers must pass **Check A** `RETRIEVAL_SCORE_FLOOR` ∧
  **Check B** citations-present-and-in-set ∧ the **figure-guard pre-check** ∧ the
  **per-claim entailment** check (`/ground`, the real gate — demotes 2b-1's
  figure-guard to a pre-check). **Check C** (`ANSWER_CONFIDENCE_FLOOR`) is a
  **tiebreaker only**. Any failure → escalate (`low_confidence`), never guess.

### Answer-model key handling (ADR-0015, super_admin)

- `GET /admin/answer-model/status` — `{ configured, masked_key (••••1234), provider,
  configured_at }`. **Never** returns the raw key.
- `POST /admin/answer-model` `{ api_key }` — set/rotate; encrypted at rest
  (`Crypt`, app-key) in `answer_model_settings`, last-4 stored for masking.
- `DELETE /admin/answer-model/key` — de-configure.

The key is decrypted only in `ChatService` for the turn and passed to `hr-ai` in
the request **body** (never a header) for `/route`, `/synthesise`, and `/ground`,
never logged or persisted. The browser never sees it. The non-secret
model/endpoints live in `config/services.php` (`HR_AI_ANSWER_MODEL` /
`HR_AI_ANSWER_ENDPOINT`, and `HR_AI_ROUTER_MODEL` / `HR_AI_ROUTER_ENDPOINT` — the
router endpoint defaults to the answer endpoint) and **must** target an
EU-available endpoint at go-live (deploy.md §1).

> Dev test profiles: `ChatTestUserSeeder` seeds employees bound to real convenios
> (`test-gipuzkoa@…` salary coverage-gap, `test-navarra@…` prose gold tests,
> `test-andalucia@…` salary-answerable with a salaried category,
> `test-andalucia-nocat@…` the constrained category pick, `test-any@…`) — run
> `registry:import` + `chunks:embed` + `salary:import` first. The super_admin for
> the key screen is `TestUserSeeder` (`admin@…`). Dev-only; never committed data.

## Escalation board + the flywheel (Sprint 4)

`hr-backend` owns every write; `EscalationController` is the board API. **Reads** are
open to any admin (auditor included); **writes** require the new **`escalation.work`**
ability (super_admin + hr_agent). Every status move / assignment / reply / resolution
/ blocked-publish is appended to **`escalation_events`** (append-only audit).

- `GET /admin/escalations` — list cards by `status` / `reason` / `assignee` (`mine`),
  grouped for the Kanban columns.
- `GET /admin/escalations/{uuid}` — card detail: the **card-scoped** conversation
  (`card.chat_session_id` only — no caller-supplied session/employee param; that
  keying *is* the access guard this sprint) + the escalating turn's trace + the event
  log. **No** full-history browser (Sprint 5).
- `PATCH /admin/escalations/{uuid}` — assign + status move (server-validated against
  the legal-transition map; audited).
- `POST /admin/escalations/{uuid}/reply` — write a human **`hr_agent`** message into
  the employee's session (no trace, no citations; audited `replied`).
- `POST /admin/escalations/{uuid}/resolve` — `{ resolution_text, convert?, topic_id?,
  confirm_scope_change? }`. Writes the **`escalation_resolutions`** row, then on
  `convert`: the **scope-confirm 409** gate (reuse the Sprint-3 bounded-edit confirm)
  → the **no-silent-override conflict 409** gate (conservative scope+topic block; an
  active `official_convenio` sharing the ruling's topic blocks publish, falling back
  to scope-only with no topic). On block → nothing published, `publish_blocked` event,
  a `document_review_tasks` `type='conflict'`, card back to **In Progress**. On pass →
  ruling published `active`, A1 render+embed, card **Resolved** (`resolved_at`).

Employee side of the two-way surface:

- `GET /chat/session` (employee-auth, **self-scoped** to the caller's own most-recent
  session) — ordered messages incl. `hr_agent` ones, attributed as **"Recursos
  Humanos"** (never the admin's name/email). The chat UI hydrates on mount + polls.

### A1 — publishing a ruling into the existing retrieval path (hr-ai untouched)

`RulingPublisher` renders the agent's `resolution_text` to a clean, deterministic PDF
(`RulingPdf` — single column, no header/footer, WinAnsi encoding, widened word-spacing,
no end-of-line hyphenation, engineered so the 2a de-spacing/furniture/two-column
heuristics cannot mangle it), stores it to S3 (`storage_path`), then runs the **same**
`/extract` + `chunks:embed --document={uuid}` path every prose doc uses.
`internal_hr_ruling` was added to **`ChunksEmbed::IN_SCOPE_TYPES`** (a code change, not a
migration). Publish verifies the round-trip is **lossless** (embedded chunk text ==
typed text, whitespace-normalised); `hr-ai` is **not** modified.

### Additive migrations (Sprint 4)

1. `chat_messages.role += 'hr_agent'` (introspect-drop-readd CHECK idiom) + nullable
   `author_admin_id` FK → `admins`.
2. `escalation_events` (append-only card activity log).

> Dev seed: `TestUserSeeder` seeds an `hr_agent` test user
> (`SEED_HR_AGENT_EMAIL`, default `agent@example.com`) with the `escalation.work`
> ability; `RoleSeeder` grants `escalation.work` to `super_admin` + `hr_agent`.

## Mail transport

Selected by `MAIL_MAILER` with no code change:

- **Local dev:** `MAIL_MAILER=smtp`, `MAIL_HOST=localhost`, `MAIL_PORT=1025` (MailHog).
- **Production:** `MAIL_MAILER=postmark` + `POSTMARK_TOKEN`.

## Schema

Every table in `hr-docs/architecture/data-model.md` is implemented as a migration
in `database/migrations`. `document_chunks.embedding` is `vector(1024)` (pgvector,
BGE-M3) with an HNSW index; that table is migrated here but read/written at runtime
by `hr-ai` only — through a dedicated, **scoped `hr_ai` Postgres role** (SELECT on
registry/scope tables + INSERT/UPDATE/DELETE on `document_chunks` only, no DDL),
created by the Sprint-2a migration `2026_06_22_100001_create_hr_ai_role_and_chunk_indexes`
(which also adds the `validity_start`/`validity_end` btree indexes). The role
password comes from `HR_AI_DB_PASSWORD` (dev default `hr_ai_secret`); `hr-ai`'s
`DATABASE_URL` must use this role.

Enums are implemented as `varchar` + `CHECK` constraints (via Laravel's `enum()`
column), per the Sprint 0 plan.
