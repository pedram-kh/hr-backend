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

**Structured Reference Knowledge (Sprint 7b-1, ADR-0021) — the manual path.** A
third knowledge class: non-vectorized scoped `reference_facts`, generalizing the
salary pattern. Reads open to any admin; **writes gated by `knowledge.edit`**.
- `POST /admin/documents/upload` with `as_reference=1` — ingest a non-salary
  `.docx`/`.xlsx` as a **`reference_source`** document (the deliberate routing
  tag): reads content via hr-ai `/read-structured`, stores display
  `document_pages`, **never embeds**, **never** touches the salary path.
- `GET /admin/reference-facts` · `GET /admin/reference-facts/{uuid}` — list / card
  (value, raw_values, derived scope, source link + locator, topic, validity, the
  authority lock, the append-only provenance timeline).
- `GET /admin/reference-facts/sources` · `GET /admin/reference-sources/{uuid}/content`
  — the reference-source picker + its extracted content (for manual fact entry).
- `POST /admin/reference-facts` — create a scoped fact → lands `needs_review`
  (`knowledge.edit`). **INVARIANT 1**: `authority_level` accepted as
  `structured_reference` only — anything higher is **422** (and the column can't
  store it). Territory/sector are derived (prohibited from the request).
- `PATCH /admin/reference-facts/{uuid}` — bounded edit; a scope-affecting change
  (convenio/job_category/validity) needs `confirm_scope_change` else **409**.
- `POST /admin/reference-facts/{uuid}/verify` — `needs_review → verified` (the
  ADR-0020 spine; `knowledge.edit`, server-gated). *Carried gap: the document
  `confirm` route above is NOT yet server-gated — see `sprint-07b-1/review.md`.*

**The AI segmentation agent (Sprint 7b-2, ADR-0022) — the `ai_agent` lane, lit.**
Reuses 7a's propose-pattern verbatim; writes only inert `ai_agent`/`needs_review`
facts. The agent **never** verifies, **never** writes a salary row, **never** mints
vocabulary.
- A `reference_source` ingest **auto-dispatches** the queued `SegmentReferenceSource`
  job → hr-ai `POST /segment-facts` → `ReferenceFactProposalService` (the only
  writer): upserts on the **extended logical key**
  `(convenio_id, topic_id, job_category_id, group_label, validity_start, validity_end)`
  — `group_label` added (additive) so per-group facts don't collide on null
  `job_category_id`; idempotent re-runs. Appends `ai_agent` `tag_events`; derives
  validity from the source `documents` row (not from prose); flags obvious
  duplicates via `duplicate_of_id` + `uncertainty.field='version'` (**signal, not
  resolution — 7d**).
- `POST /admin/reference-sources/{uuid}/segment` — manually re-run segmentation
  (idempotent; `knowledge.edit`). Used by the eval to re-segment after prompt iteration.
- `POST /admin/reference-facts/{uuid}/reject` — `needs_review → rejected` (an
  auditable, queue-excluded discard of an AI proposal — no deletion; `knowledge.edit`).
- `GET /admin/reference-facts?source=ai_agent&queue=true` — the **uncertain-first**
  review queue (uncertainty set, then ascending confidence); rows carry
  `group_label`, `confidence`, `uncertainty`, `source_excerpt`, `is_ai_proposed`,
  `is_possible_duplicate`. **The deliverable** is the measured accuracy report in
  `hr-docs/sprints/sprint-07b-2/review.md` (eval harness in `sprint-07b-2/eval/`).

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
  fires *before* the router and any `hr-ai` call) → a deterministic **reference-fact
  pre-check** (`ReferenceFactRouter`, 7c — non-salary questions only, fail-safe
  fall-through) → **`RouterService`** (ADR-0016) → branch:
  - **salary** → **`SalaryAnswerService`** (SQL over `salary_tables` /
    `salary_table_rows`, exact + year-aligned per ADR-0006): category from profile
    or a **constrained single-turn pick** (`needs_category`, FK-validated,
    unverified, shown "según tu indicación"); coverage gap → escalate
    `salary_coverage_gap`. Skips synthesis/grounding (SQL-grounded by construction).
  - **reference fact** (7c, ADR-0023) → **`ReferenceFactAnswerService`** (the salary
    sibling): when a `verified`, in-scope, in-validity `reference_facts` row matches
    the question's topic, quote the exact `value`/`raw_values`, cite the source with
    `chunk_id = null` at `structured_reference`, **skip `/ground`** (Phase 1); no
    usable verified fact → escalate `reference_fact_coverage_gap` (only verified
    answers; scope is most-specific-else-escalate; `path:"reference_fact"`).
    **Composition (Phase 2):** if governing convenio prose on the topic is also
    present (clears Check A), the fact is handed to `/synthesise`+`/ground` as one
    more typed source (`source_type=reference_fact`, `chunk_id=null`,
    `structured_reference` below `official_convenio`); the **generated** answer
    **must `/ground`**; a same-point conflict escalates (`conflict`) before synthesis,
    never blends (`path:"reference_fact_composition"`).
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

## User & directory management + role-scoped history (Sprint 5, ADR-0018)

`hr-backend` owns every write; the directory, admin/role management, and the
full-history browser are all **server-enforced** by ability (`EnsureCan`) — the
UI only hides, the server is the boundary.

**Employee directory** — behind **`directory.manage`** (super_admin + hr_agent;
reads included). FK pickers into existing vocabulary only (ADR-0011). Every
change writes `employee_audit_log` (one row per field, same transaction —
`EmployeeAuditLogger`).

- `GET /admin/employees` — search (`q`) / filter (`convenio_id`, `territory_id`,
  `sector_id`, `status`), paginated.
- `GET /admin/employees/{uuid}` — detail + the audit-log timeline.
- `POST /admin/employees` — create (writes a `*`=`created` baseline audit).
- `PATCH /admin/employees/{uuid}` — edit. Changing `email` returns **409
  `email_change_confirmation_required`** unless `confirm_email_change=true`
  (email is the login key). Editing does **not** bump `profile_last_reviewed_at`.
- `POST /admin/employees/{uuid}/mark-reviewed` — explicit review attestation.
- `POST /admin/employees/import/validate` — CSV **dry run**: a per-row pass/fail
  report, writes nothing.
- `POST /admin/employees/import` — CSV **apply**: imports the valid rows, each in
  its own transaction with its audit (not whole-file-atomic; bad rows reported,
  never dropped). Headers (by name): `email`, `full_name`, `convenio_numero`
  (required); `territory_code`, `job_category`, `employment_type`,
  `work_location`, `employee_external_id`, `start_date` (optional).
- `GET /admin/job-categories?convenio_id=` — convenio-scoped category picker.

**Admin & role management** — behind **`admin.manage`** (super_admin only — the
most privileged action, it can grant `history.view_all`).

- `GET /admin/admins` — list admins (roles + abilities) + the assignable roles.
- `POST /admin/admins` — create (with optional roles).
- `PATCH /admin/admins/{uuid}` — edit name/status; **deactivation revokes the
  admin's Sanctum tokens**.
- `PUT /admin/admins/{uuid}/roles` — assign the four roles (spatie `syncRoles`).

**Status enforcement** — both OTP paths refuse an inactive account (admin *or*
employee), and `EnsureActiveAccount` (alias `active`, on `/me`, `/chat/*`, and
`/admin/*`) refuses any authenticated request from an inactive account. So a
deactivated employee can't chat, and a deactivated admin loses access at once.

**Full-history browser + search** — behind **`history.view_all`** (super_admin +
auditor only). Read-only over existing chat data. EVERY access writes
`conversation_access_log` (incl. super_admin — no role is exempt).

- `GET /admin/history/conversations` — list/filter all sessions
  (`employee_uuid`, `convenio_id`, `territory_id`, `from`/`to` on
  `last_activity_at`, `reason`, `outcome=answered|escalated`).
- `GET /admin/history/conversations/{sessionUuid}` — open a conversation (writes
  a `conversation_view` row).
- `GET /admin/history/employees/{employeeUuid}` — one employee's sessions.
- `GET /admin/history/search?q=` — content search; brief snippets (writes one
  `history_search` row, not per-employee).

The **Sprint-4 card-scoped boundary is unchanged** (keyed to
`card.chat_session_id`). Sprint 5 tightens only the `knowledge_editor` read:
`GET /admin/escalations/{uuid}` now withholds the **conversation payload** unless
the caller holds `escalation.work` OR `history.view_all` (`conversation_restricted`
in the response).

### Ability → role (spatie, seeded idempotently)

`RoleSeeder` and the data migration `2026_06_25_100002_seed_sprint5_permissions`
are kept in lockstep (so prod `migrate` and a fresh seed both land the abilities):
`directory.manage` → super_admin + hr_agent; `history.view_all` → super_admin +
auditor; `admin.manage` → super_admin.

### Additive migrations (Sprint 5)

1. `conversation_access_log` (append-only — who viewed whose conversation, when).
2. `seed_sprint5_permissions` (idempotent data migration for the three abilities).

No `employees`/`admins` column changes (confirmed — the schema already had every
field).

### Tests (the acceptance proof)

`tests/Feature/Sprint5AccessMatrixTest.php` proves the access matrix server-side
by hitting endpoints directly: hr_agent → 403 on every `history.view_all`
endpoint but 200 on its own card; knowledge_editor → 403 on all conversation
reads (incl. the tightened card payload); auditor + super_admin → 200 on history
with a `conversation_access_log` row written (incl. super_admin's own read); a
deactivated admin → refused. Run against a Postgres test DB (the schema is
Postgres-specific — pgvector/enums), configured in `phpunit.xml`
(`hr_platform_test`); `php artisan test`.

## Guardrails configuration (Sprint 6, ADR-0019)

The admin layer **on top of** the hardcoded `GuardrailService` baseline + the
`config/hr.php` floors — **additive, raise-only, server-enforced**. The baseline
stays code (uneditable); the admin layer is data that can only *tighten*. The
engine reads `stricter_of(baseline, admin)` from one read-model, **`GuardrailPolicy`**
(cached, busted on every write) — `ChatService` / `RouterService` /
`EscalationService` receive the already-combined value, never the raw admin one.

**Five knobs** (all additive):
- **Thresholds** — `retrieval_score_floor` (Check A), `answer_confidence_floor`
  (Check C, the *tiebreaker*), `router_confidence_floor` (secondary): each
  `max(hardcoded floor, admin)`. A below-floor write is **rejected (422), not
  clamped** (`StoreGuardrailConfigRequest` + `floorViolation`).
- **Blocked topics / off-domain** — an **add-only union** over the baseline,
  checked at the **same pre-router point** (so an admin-blocked question never
  reaches hr-ai), matched as a normalized, accent-insensitive, **word-boundary
  literal** — never raw regex.
- **Off-domain message** — display copy only; changes no gate.
- **Tone** — injected into a **synthesis-local** string only (never into the
  question used for `/ground`, the router, or Check B). Structurally cannot bypass
  a gate; a write-time sanitizer also rejects override phrasing.
- **Convert-by-reason** — an **intersection** with a hardcoded baseline allow-set
  (restrict-only); `sensitive_topic` is never convertible.

**Endpoints** — read open to any admin (auditor read-only); writes gated by the
new **`guardrails.manage`** ability (`super_admin` only):
- `GET /admin/guardrails` — full console state (each knob's admin value + inline
  hardcoded floor + effective value, the add-only lists, the convert policy, the
  change history, `can_manage`).
- `POST /admin/guardrails` — write the scalar config (thresholds, off-domain
  message, tone, convert-by-reason). Below-floor → **422** `threshold_below_floor`;
  tone override phrasing → **422** `tone_override_rejected`.
- `POST /admin/guardrails/blocked-topics` `{ pattern, kind }` — add a trigger.
- `DELETE /admin/guardrails/blocked-topics/{id}` — soft-disable (never hard delete).

Every accepted change is appended to **`guardrail_config_events`** (who, when,
from→to); a rejected below-floor write writes **no** row.

### Additive migrations (Sprint 6)

1. `guardrail_config` (single global row — typed thresholds + message + tone +
   convert allow-set).
2. `guardrail_blocked_topics` (admin add-only blocked-topic / off-domain list).
3. `guardrail_config_events` (append-only audit).
4. `seed_guardrails_manage_permission` (idempotent data migration; `RoleSeeder`
   grants `guardrails.manage` to `super_admin` in lockstep).

No `config/hr.php` change (floors stay the floor-of-the-floor), no hr-ai migration,
no new hr-ai endpoint (tone rides the existing `/synthesise` question argument).

### Tests (the acceptance proof)

`tests/Feature/Sprint6GuardrailInvariantTest.php` proves the raise-only invariant
server-side by hitting endpoints directly: below-floor POST → 422 (rejected, not
clamped, no write, no audit row); a raised floor flips a previously-answered
question to an escalation (before/after trace); an admin blocked topic escalates
while the baseline still fires first; tone is **synthesis-local** and `/ground`
receives the **raw** question; a hostile-tone phrase is rejected and an ungrounded
answer still escalates with a benign tone set; convert-by-reason is restrict-only
(`sensitive_topic` never; adding it is a no-op); and the ability matrix (auditor
read-only, super_admin write, others 403). `php artisan test`.

## Messy-tail document intelligence (Sprint 7a, ADR-0020)

The LLM tagging tier + propose-new-vocabulary + the expiry/lineage write-side —
all **AI proposes → human confirms**, no answer-loop change.

- **Auto-tagging (queued).** A `reason = unresolved` ingest dispatches
  `ProposeDocumentTags` (after the DB commit, never blocking ingest — the same
  background posture as embed). The job calls `ExtractionClient::proposeTags()` →
  hr-ai `POST /propose-tags`; `TagProposalService` persists the result as
  `ai_agent` `tag_events` + **unverified** `ai_agent` `document_topics` +
  `tagging_confidence`, and merges variant hints into the task's
  `raw_unmatched_values`. A manual `POST /admin/documents/{uuid}/resuggest`
  re-runs it (`knowledge.edit`).
- **The two safety invariants (enforced + tested).** (1) the AI leaves
  `tagging_status = under_review` — never `auto_proposed`/`verified` — so the
  embedding gate (`tagging_status != under_review`) keeps the doc unretrievable
  (0 chunks) until a human verifies via the unchanged Sprint-3 `confirm()`; (2)
  the AI writes **only** `ai_agent` provenance, never the authoritative scope FKs
  (`convenio_id`/`document_type_id`/validity/`retrieval_status`).
- **Propose-new-vocabulary.** `VocabularyProposalService` + controller: propose
  (`knowledge.edit`) / approve · reject (`vocabulary.approve`, super_admin may
  propose-and-approve). Variant→alias is the default (deterministic
  similarity, no model dependency); creating a new value is deliberate; convenios
  are registry-only. Approval writes the alias/new value with provenance and
  resolves the originating doc's `raw_unmatched_value`.
- **Reference-fact segmentation (Sprint 7b-2, ADR-0022).** The `ai_agent` lane of
  `reference_facts`, lit. A `reference_source` ingest auto-dispatches
  `SegmentReferenceSource` → `ExtractionClient::segmentFacts()` → hr-ai
  `POST /segment-facts`; `ReferenceFactProposalService` (the only writer) upserts
  per-scope facts on the extended logical key (incl. `group_label`) as
  `ai_agent`/`needs_review`, appends `ai_agent` provenance, and flags obvious
  duplicates (signal only — 7d). Re-proven invariants
  (`Sprint7b2SegmentationInvariantTest`): inert lane, authority floor, **zero
  salary rows**, idempotent re-runs, agent-never-verifies, duplicate-flag-without-merge.
- **Expiry queue + lineage write-side.** `php artisan reviews:scan-expiry
  [--days=90] [--dry-run]` materializes `expiry` `document_review_tasks` for
  active prose within 90 days of `validity_end` (or already past, incl. the
  Sprint-3 `date_expired_active` staleness docs). The succession handoff
  (`POST /admin/review/expiry/{taskId}/resolve`) writes `predecessor_document_id`
  on human confirmation — **same-convenio candidates only, never auto-retire**.
  (AI *suggestion* of succession candidates arrived in Sprint 7d, below.)

## Semantic comparison: the fence, fact versions, succession (Sprint 7d, ADR-0024)

One read-only primitive (`ExtractionClient::compareScope()` → hr-ai
`POST /compare-scope`) and three **human-adjudicated** surfaces. Nothing here
auto-resolves, auto-links, auto-retires or auto-publishes.

- **(A) The semantic publish fence** — `SemanticFenceService`, consulted from
  `EscalationService::resolve()`. The combined fence is
  **`existing_block OR semantic_block`, structurally**: `detectConflicts()` is
  unchanged and still the first term, and the semantic pass runs **only when it
  returns empty**, so it can turn ALLOW→BLOCK and never BLOCK→ALLOW (and costs
  nothing on the already-blocked path). The draft has no chunks yet, so
  `resolution_text` is the query — **multi-probe** (paragraph-split, because the
  embedder truncates a long text silently and would leave the tail uncompared),
  **max over probes**. Two bands: `≥ semantic_conflict_threshold` → 409
  `publish_blocked` / `semantic_overlap` (a `conflict` task opens on the draft, the
  card returns to In Progress, `escalation_events.detail` records the chunk ids +
  scores + thresholds); `≥ semantic_review_band` → 409
  `publish_requires_acknowledgement` (the draft is untouched; re-POST with
  `acknowledge_semantic_overlap = true` publishes and records
  `publish_acknowledged_overlap`). **A failed comparison takes the acknowledgement
  path, never a clean publish** — a failure is not evidence of absence.
- **Thresholds are code config only** (`config/hr.php`) and deliberately **not** in
  the Sprint-6 guardrails UI: a *lower* block threshold blocks *more*, so
  ADR-0019's `max(floor, admin)` would let an admin loosen the fence. Any future
  exposure must use `min(baseline, admin)`. **The shipped values are PROVISIONAL** —
  run `php artisan fence:calibrate-semantic [--json]` (read-only; real published-
  ruling distribution **plus** the labeled synthetic anchors in
  `hr-docs/sprints/sprint-07d/eval/anchors.json`) against the corpus and set the
  block threshold **below** the lowest score any known-true-overlap anchor scored.
- **§8.5 reverse re-check (flag-only).** An `official_convenio` becoming `active`
  (ingest or admin activation) dispatches `RecheckRulingsForConvenio` →
  `SemanticRecheckService`, which opens a `conflict` review task on any overlapping
  published ruling. It **never** changes `retrieval_status`. Backfill:
  `php artisan rulings:scan-semantic-conflicts [--dry-run]`.
- **(B) Fact version resolution.** `php artisan facts:scan-duplicates [--dry-run]`
  flags same-convenio+topic facts whose `group_label` **digit tokens overlap**
  (`Grupos 1 y 2` ∩ `Grupo 2`) — deterministic, no embeddings, no threshold.
  `GET /admin/reference-facts/{uuid}/duplicate-pair` serves the side-by-side, and
  `POST …/resolve-duplicate` (`knowledge.edit`) applies one of three verdicts via
  `FactResolutionService`: **supersede** (the older fact's `validity_end` closes at
  `newer.validity_start − 1 day`, both stay `verified`, lineage recorded — **never a
  delete**; refused if the dates don't support the direction), **coexist**, or
  **reject**. Resolution reaches chat **through the data only** — the 7c answer rule
  is untouched.
- **(C) Succession proposal (no LLM).** `reviews:scan-expiry` now queues
  `ProposeSuccession` per new task (`--no-propose` to skip);
  `POST /admin/review/expiry/{taskId}/propose-succession` re-runs it. The relationship
  is deterministic: **`successor` requires overlap ≥ threshold AND a strictly-later
  `validity_start`** — a conjunction, because a confidently-wrong successor is the
  one output that would tempt a human to retire a live document. The proposal writes
  **only** `ai_proposal`, `ai_proposal_status`, `ai_proposed_at` on the task and no
  `documents` column at all; confirming runs the **unchanged** 7a write-side, and
  `POST …/reject-proposal` writes a verdict + audit row and leaves the task open.
  Measure it with `php artisan succession:gold-eval [--discover] [--json]`
  (read-only; the failure metric is **confidently-wrong successor**, which must be 0).

### Additive migrations (Sprint 7d)

1. `add_detail_to_escalation_events` — nullable `jsonb`: the machine-readable
   evidence (chunk ids, scores, thresholds) behind a semantic block/acknowledgement.
2. `add_resolution_fields_to_reference_facts` — nullable `resolution`,
   `superseded_by_id`, `resolved_by`, `resolved_at`.
3. `add_succession_proposal_to_document_review_tasks` — nullable `ai_proposal`,
   `ai_proposal_status`, `ai_proposed_at`.

No CHECK-enum rewrite: the new event types ride a free-string column, and the
reverse re-check reuses the existing `conflict` task type with a `kind`
discriminator inside `raw_unmatched_values`. No hr-ai migration (ADR-0007).

### Tests (the acceptance proof)

`Sprint7dFenceNeverOpensTest` — the fence can only get stricter: all four
`Sprint5Correction01FenceTest` cases through the combined fence with the semantic
fake at `0.0` behave exactly as before; case 4 blocks at `0.95`; a 2×2 matrix
asserts `blocked == (existing || semantic)`; the band → acknowledge → publishes; a
throwing comparison reaches the acknowledgement path and never a clean publish;
threshold monotonicity; and the comparison is **never called** when
`detectConflicts` is non-empty. `Sprint7dCalibrationTest` — no block threshold is
recommended without labeled evidence, and the recommendation sits strictly below the
weakest true overlap. `Sprint7dFactResolutionTest` — the Navarra pair is flagged;
supersede closes validity and deletes nothing; the unchanged 7c rule stops
escalating once the data is corrected. `Sprint7dSuccessionProposalTest` — the
conjunction, the three-columns-only invariant (every document column snapshotted),
confirm-through-7a, reject-writes-nothing, and the gold-eval harness itself.

### Additive migrations (Sprint 7a)

1. `create_vocabulary_proposals_table` (the propose→approve record).
2. `seed_vocabulary_approve_permission` (idempotent data migration; `RoleSeeder`
   grants `vocabulary.approve` to `super_admin` in lockstep).

No migration for the `ai_agent` `tag_events` lane (enum value existed),
`predecessor_document_id` (column existed — 7a adds the writer), or the `expiry`
task type (enum existed — 7a adds the writer). No hr-ai migration (ADR-0007).

### Tests (the acceptance proof)

`tests/Feature/Sprint7aTagProposalInvariantTest.php` proves both invariants by
hitting the service/endpoints directly: after the AI proposes, the doc is still
`under_review` with **0 `document_chunks`** (genuinely unretrievable) and its
scope FKs are **unchanged** from their pre-proposal state; a human verify flips it
to embeddable. Plus: `knowledge_editor` can propose but not approve vocabulary;
`super_admin` propose-and-approve writes a new sector; variant→alias is offered
and folds into the existing value; a confirmed succession writes
`predecessor_document_id` (same-convenio) and never auto-retires; a cross-convenio
succession is rejected. `php artisan test`.

## OCR fallback for scanned/text-less pages (Sprint 7e, ADR-0026)

A **format** fix, not a tagging or answer-loop change: some ingested PDFs are
image-only scans with **no text layer**, so they produce 0 chunks and are
unanswerable. Engine + model were chosen by a measured eval against
human-corrected gold transcriptions (`sprints/sprint-07e/eval/`, `score_ocr.py`)
— **`claude-opus-5`** (config'd as its own `OCR_MODEL`, decoupled from
`ANSWER_MODEL` so an answer-quality change can never silently retarget OCR).

- **`/extract` marks, never OCRs inline.** `DocumentIngestor::ingest()` passes
  `ocr`/`ocrPageCap` through to hr-ai `/extract`; a text-less page comes back
  `extraction_source = ocr_pending` (within the cap) and is persisted as such on
  `document_pages` via the **unchanged** write path. If any page is
  `ocr_pending`, `OcrDocumentPages` is dispatched once per document (after the
  transaction commits).
- **The queued job split (mirrors 7a's `ProposeDocumentTags` pattern).**
  `OcrDocumentPages` fans out one `OcrPage` job per pending page (never OCRs
  itself); each `OcrPage` calls **`OcrService::ocrOnePage()`**, which decrypts
  the configured provider key, calls `ExtractionClient::ocrPage()` → hr-ai
  `POST /ocr-page`, and on success writes `document_pages.text` +
  `extraction_source = ocr` + `ocr_quality` (deterministic score — `_es_ratio`
  function-word density, a garbage-character ratio, text-length-vs-page-area;
  no second LLM call) + `ocr_engine` (`claude-opus-5`) + `ocr_cost_usd` +
  `ocr_bilingual`. On failure the page is logged and **left `ocr_pending`** for
  retry — never silently dropped. The **last** `OcrPage` for a document
  re-dispatches `ProposeDocumentTags` if the document is still `under_review`,
  since text just became available for the 7a tagger to read.
- **Reaching `/embed` — the S3 sidecar.** `/ocr-page` also writes
  `documents/{uuid}/ocr/{page:04d}.json` to S3 (hr-ai's write, not hr-backend's)
  — the column-split, language-tagged units + table rows that
  `extract_language_streams`/`build_chunks` append into the `es`/`eu`
  accumulators **only** when a page has zero native blocks (Option B).
  `document_pages.text` alone unblocks the viewer + the 7a tagger's read, but
  **not** chunking — `/embed` re-extracts from the original PDF and never reads
  that column.
- **Bilingual pages get a normal verify, not a special gate.** With
  `claude-opus-5` measuring eu WER < 1% (ADR-0026), both language streams of a
  bilingual OCR'd page ride the identical `under_review → confirm → embed`
  path — no held-back `eu` stream, no second approval step. `ocr_bilingual`
  drives only a reviewer-guidance note ("check the eu column against the es
  column"), shown in the Knowledge Center viewer.
- **Inert until verified.** OCR'd documents are/stay `under_review`; the
  existing embedding gate (`tagging_status != under_review`) holds them at 0
  chunks until the Sprint-3 `confirm()` — same invariant as 7a, re-proven here
  (`Sprint7eOcrInvariantTest`).
- **Provenance + UI.** Additive migration on `document_pages`:
  `extraction_source` (`text_layer` default | `ocr_pending` | `ocr`),
  `ocr_quality`, `ocr_engine`, `ocr_cost_usd`, `ocr_bilingual`. Document-level
  derived `ocr_pages_count` (`DocumentController::index`/`show`) drives a
  Knowledge-Center "OCR'd (N)" badge; the per-page viewer shows "texto obtenido
  por OCR" + quality + the bilingual note where `extraction_source = 'ocr'`.
- **Opt-in ingest + backfill (CLI, no UI this sprint).**

```bash
# Fresh corpus ingest, OCR opted in (default off) + a per-document page cap:
php artisan documents:ingest-folder --ocr [--ocr-page-cap=60]

# Back-fill documents ingested before this feature existed: finds text-less
# docs (pages > 0 AND pages_with_text = 0), OCRs them (respecting the cap),
# then re-runs the 7a tag-proposal now that text exists. Reports per document:
# pages OCR'd, mean/min quality, cost. Leaves every document under_review.
php artisan documents:ocr-backfill [--document=<uuid>] [--page-cap=] [--dry-run]
```

### Additive migration (Sprint 7e)

1. `add_ocr_provenance_to_document_pages` — `extraction_source` (3-valued:
   `text_layer` | `ocr_pending` | `ocr`), `ocr_quality`, `ocr_engine`,
   `ocr_cost_usd`, `ocr_bilingual`. No hr-ai migration (ADR-0007 — hr-ai's only
   new write for this feature is the S3 sidecar, not a DB row).

### Tests (the acceptance proof)

`tests/Feature/Sprint7eOcrInvariantTest.php`: the **no-op invariant** (a
text-layer PDF ingests with `extraction_source = text_layer` on every page and
zero OCR jobs dispatched); the positive control (a text-less page, opted in,
*does* dispatch `OcrDocumentPages`); the fan-out (`OcrDocumentPages` → one
`OcrPage` per pending page); and the **`under_review`/0-chunks gate** (an OCR'd
document stays `under_review` with 0 chunks until confirmed, then embeds).
`Sprint7cAdditivityRegressionTest` stays green (the golden trace is untouched).
`php artisan test`.

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
