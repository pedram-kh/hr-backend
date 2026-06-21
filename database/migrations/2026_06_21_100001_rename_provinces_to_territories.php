<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 1 — territories restructure (step 1 of 3).
 *
 * Rename `provinces` → `territories`. Postgres FK constraints follow the table
 * by identity, so the existing convenios/employees FKs that referenced
 * `provinces(id)` remain valid against `territories(id)`. The local FK *column*
 * renames happen in step 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('provinces', 'territories');
    }

    public function down(): void
    {
        Schema::rename('territories', 'provinces');
    }
};
