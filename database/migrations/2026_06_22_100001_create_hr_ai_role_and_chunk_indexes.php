<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 2a — enforce ADR-0007 at the database, and complete the scope indexes.
 *
 * 1) A dedicated, SCOPED Postgres role for hr-ai (plan §9 Q6): it may SELECT the
 *    registry/scope tables and INSERT/UPDATE/DELETE `document_chunks` ONLY — no
 *    other write, no DDL. hr-ai connects as this role for the chunk-write path,
 *    so "hr-ai never writes any table but document_chunks, never migrates" is a
 *    database guarantee, not just a convention.
 *
 * 2) Additive btree indexes on the chunk validity scope columns (Q4). The HNSW
 *    index on `embedding` and the convenio/territory/sector/retrieval_status/
 *    authority_level btrees already exist from Sprint 0; validity_start/end were
 *    missing. (Retrieval forces an exact flat scan for full-recall correctness —
 *    catch 2 — so these indexes serve scope-only queries, not the ANN order.)
 *
 * The role password comes from env HR_AI_DB_PASSWORD (dev default below). All DDL
 * stays in hr-backend; hr-ai still never migrates.
 */
return new class extends Migration
{
    private function role(): string
    {
        return 'hr_ai';
    }

    private function password(): string
    {
        return (string) env('HR_AI_DB_PASSWORD', 'hr_ai_secret');
    }

    /** Tables hr-ai may READ (registry/scope + document metadata). */
    private function readTables(): array
    {
        return [
            'convenios', 'territories', 'sectors', 'convenio_job_categories',
            'document_types', 'documents', 'document_pages', 'document_topics',
            'topics', 'employees', 'salary_tables', 'salary_table_rows',
        ];
    }

    public function up(): void
    {
        $role = $this->role();
        $password = $this->password();
        $database = DB::getDatabaseName();

        // Idempotent role creation (Postgres has no CREATE ROLE IF NOT EXISTS).
        DB::statement(
            "DO \$\$ BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN
                    CREATE ROLE {$role} LOGIN PASSWORD '{$password}';
                ELSE
                    ALTER ROLE {$role} LOGIN PASSWORD '{$password}';
                END IF;
            END \$\$;"
        );

        DB::statement("GRANT CONNECT ON DATABASE \"{$database}\" TO {$role}");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");

        // Read-only on registry/scope + document metadata.
        foreach ($this->readTables() as $table) {
            DB::statement("GRANT SELECT ON {$table} TO {$role}");
        }

        // The ONE writable table for hr-ai: document_chunks (+ its sequence).
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON document_chunks TO {$role}");
        DB::statement("GRANT USAGE, SELECT ON SEQUENCE document_chunks_id_seq TO {$role}");

        // Additive validity btree indexes (Q4).
        DB::statement('CREATE INDEX IF NOT EXISTS document_chunks_validity_start_index ON document_chunks (validity_start)');
        DB::statement('CREATE INDEX IF NOT EXISTS document_chunks_validity_end_index ON document_chunks (validity_end)');
    }

    public function down(): void
    {
        $role = $this->role();
        $database = DB::getDatabaseName();

        DB::statement('DROP INDEX IF EXISTS document_chunks_validity_start_index');
        DB::statement('DROP INDEX IF EXISTS document_chunks_validity_end_index');

        // Revoke everything granted, then drop the role (safe: it owns nothing).
        foreach ($this->readTables() as $table) {
            DB::statement("REVOKE ALL ON {$table} FROM {$role}");
        }
        DB::statement("REVOKE ALL ON document_chunks FROM {$role}");
        DB::statement("REVOKE ALL ON SEQUENCE document_chunks_id_seq FROM {$role}");
        DB::statement("REVOKE USAGE ON SCHEMA public FROM {$role}");
        DB::statement("REVOKE CONNECT ON DATABASE \"{$database}\" FROM {$role}");
        DB::statement("DROP ROLE IF EXISTS {$role}");
    }
};
