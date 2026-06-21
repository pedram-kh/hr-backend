<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 1 — territories restructure (step 3 of 3).
 *
 * Rename the FK columns that referenced the old `provinces` table:
 *  - convenios.province_id        → territory_id
 *  - employees.province_id        → territory_id
 *  - document_chunks.province_id  → territory_id (denormalized scope filter;
 *    unused this sprint — no chunking — but kept consistent for the RAG sprint)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenios', function (Blueprint $table) {
            $table->renameColumn('province_id', 'territory_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('province_id', 'territory_id');
        });

        Schema::table('document_chunks', function (Blueprint $table) {
            $table->renameColumn('province_id', 'territory_id');
        });
    }

    public function down(): void
    {
        Schema::table('convenios', function (Blueprint $table) {
            $table->renameColumn('territory_id', 'province_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('territory_id', 'province_id');
        });

        Schema::table('document_chunks', function (Blueprint $table) {
            $table->renameColumn('territory_id', 'province_id');
        });
    }
};
