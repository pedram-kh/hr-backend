<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenios', function (Blueprint $table) {
            // Name variants for a single convenio (e.g. duplicate-numero rows in the
            // registry under a formal + colloquial title). Consistent with
            // territories.aliases / sectors.aliases. ADR-0011 managed growth.
            $table->jsonb('aliases')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('convenios', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
