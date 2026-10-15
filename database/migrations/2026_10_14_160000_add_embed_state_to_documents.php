<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 12c. Embed progress lives on the document, not in tag_events.
 * tag_events.facet is the document-vocabulary provenance log; the
 * escalation_events migration (Q-E) already ruled out overloading a
 * provenance table with activity. These three columns are the card status.
 *
 * embed_state: pending | searchable | failed | null
 * embed_error_code: timed_out | service_unreachable | failed | null
 *   (`not_finished` is computed at read time from a stale pending row; it is
 *   not stored.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('embed_state', 16)->nullable()->after('tagging_confidence');
            $table->string('embed_error_code', 32)->nullable()->after('embed_state');
            $table->timestamp('embed_state_at')->nullable()->after('embed_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['embed_state', 'embed_error_code', 'embed_state_at']);
        });
    }
};
