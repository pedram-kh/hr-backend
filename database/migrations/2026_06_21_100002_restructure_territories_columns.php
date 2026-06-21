<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 1 — territories restructure (step 2 of 3).
 *
 * Replace the flat, scope-conflating shape with a proper territorial model:
 *  - `level enum(national|regional|provincial)` replaces `is_national`
 *  - `parent_territory_id` self-ref (populated where obvious; NO precedence
 *    logic this sprint — precedence is deferred to the scoping/RAG sprint)
 *  - `code` relaxed from char(2) to varchar(8) NULL (regions/national may lack
 *    a 2-digit code)
 *
 * Backfill maps the Sprint 0 data: is_national → national; the Andalucía
 * placeholder (code 'AN') → regional; everything else → provincial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('territories', function (Blueprint $table) {
            $table->enum('level', ['national', 'regional', 'provincial'])->nullable()->after('name');
            $table->foreignId('parent_territory_id')->nullable()->after('level')
                ->constrained('territories')->nullOnDelete();
        });

        // Relax `code` to varchar(8) NULL (keeps the existing UNIQUE index).
        $table = 'territories';
        Schema::table($table, function (Blueprint $t) {
            $t->string('code', 8)->nullable()->change();
        });

        // Backfill `level` from the Sprint 0 columns/data.
        DB::statement("UPDATE territories SET level = 'national' WHERE is_national = true");
        DB::statement("UPDATE territories SET level = 'regional' WHERE code = 'AN'");
        DB::statement("UPDATE territories SET level = 'provincial' WHERE level IS NULL");

        // Now that every row has a value, make it NOT NULL.
        DB::statement('ALTER TABLE territories ALTER COLUMN level SET NOT NULL');

        Schema::table('territories', function (Blueprint $table) {
            $table->dropColumn('is_national');
        });
    }

    public function down(): void
    {
        Schema::table('territories', function (Blueprint $table) {
            $table->boolean('is_national')->default(false)->after('name');
        });

        DB::statement("UPDATE territories SET is_national = true WHERE level = 'national'");

        Schema::table('territories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_territory_id');
            $table->dropColumn('level');
        });

        Schema::table('territories', function (Blueprint $table) {
            $table->char('code', 2)->nullable(false)->change();
        });
    }
};
