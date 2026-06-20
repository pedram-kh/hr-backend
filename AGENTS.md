# hr-backend — agent instructions

Laravel backend for the HR knowledge platform. **System of record:** auth, user directory, knowledge management, escalations, chat history, and the API for `hr-frontend`. **This repo owns the entire relational schema and all migrations.**

## Read before building
The canonical specs live in the `hr-docs` repo (cloned beside this one in the `hr-platform/` workspace):
- `hr-docs/architecture/architecture.md` — the whole picture
- `hr-docs/architecture/data-model.md` — the schema you implement as migrations
- `hr-docs/architecture/decisions/` — ADRs (the *why*; do not reverse these)
- `hr-docs/glossary.md` — use these terms
Read the relevant doc before writing code. For the current task, read the active sprint spec in `hr-docs/sprints/`.

## Non-negotiable rules
- **Controlled vocabulary:** scoping facets (province, sector, convenio, job_category, validity) are foreign keys into vocabulary tables — NEVER free-text strings. Invalid tags must be structurally impossible.
- **Scope is deterministic:** resolving which documents apply to an employee is plain SQL from their profile + question date. NEVER an LLM call — it carries legal weight.
- **Salary tables are structured:** store and query them relationally. NEVER send them to be embedded/vectorized.
- **Database ownership:** this repo owns all migrations. `hr-ai` reads scope/registry tables and reads-writes `document_chunks` only; it never migrates.
- **The hr-ai contract:** backend resolves scope, calls `hr-ai` with `{query, scope_filters}`, receives `{answer, citations, confidence, trace}`, and persists message + citations + trace. Keep this contract in sync with `hr-ai/AGENTS.md`.
- **Provenance:** every tag change writes an append-only row to `tag_events`; every employee profile change writes to `employee_audit_log`.

## Stack & conventions
- Laravel (latest stable), PHP 8.3+. PostgreSQL + pgvector.
- Auth: Sanctum bearer tokens, ~24h TTL (daily sessions). Email OTP only — no passwords, no SSO.
- Roles/permissions: `spatie/laravel-permission`.
- Email: Postmark.
- snake_case columns; follow the table/column names in `data-model.md` exactly. Migrations are the source of schema truth and must match it.

## Workflow
For any sprint: read the spec, then write `plan.md` and STOP for review before building. When given a correction, apply it AND record it in the named doc as instructed.
