<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7g Item 1 (ADR-0029) — the escalation explanation, stored ON the card
 * at creation. Five additive, nullable columns; no existing column reshaped, no
 * backfill in the migration itself (the backfill for pre-7g cards is its own
 * command, `escalations:backfill-explanations` — a migration is schema, not a
 * data-population job, and backfilling here would silently swallow a partial
 * failure with no retry/report).
 *
 *  - `explanation_facts` (jsonb) — the DETERMINISTIC output of `EscalationExplainer`
 *    (reason + trace -> structured facts: what was asked, what was found, why it
 *    stopped, the fix). Pure function of data already on the trace; computed
 *    SYNCHRONOUSLY at card creation (cheap, no I/O).
 *  - `explanation_text` (text) — the AI-WRITTEN paragraph over those facts only
 *    (a cheap Haiku/ROUTER_MODEL call, reusing hr-ai's existing `/synthesise`
 *    path — no new hr-ai endpoint). Nullable BY DESIGN: on a provider failure or
 *    a failed no-new-claims check, this stays null and the card renders the
 *    structured facts as sentences instead (`EscalationExplainer::factsToSentences`).
 *    Computed ASYNCHRONOUSLY (queued, after the creating transaction commits) —
 *    mirrors `ProposeDocumentTags`/`SegmentReferenceSource`; a card is never held
 *    open waiting on a provider call.
 *  - `fix_action` / `fix_surface` / `fix_link` — the STRUCTURED fix (never
 *    AI-generated, per the sprint constraint). `fix_link` is a `#doc=`/`#fact=`/
 *    `#emp=`-shaped hr-frontend deep link (`#fact=`/`#emp=` are new in this same
 *    sprint, Item 2) so "Corregir" always opens the exact surface a human needs.
 *
 * Every existing card (pre-7g) reads all five columns null until the backfill
 * command runs. Nothing here changes an escalation DECISION, gate, or the
 * employee-facing text (that is a `ChatService` change, not a schema change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escalation_cards', function (Blueprint $table) {
            $table->jsonb('explanation_facts')->nullable()->after('topic_id');
            $table->text('explanation_text')->nullable()->after('explanation_facts');
            $table->string('fix_action')->nullable()->after('explanation_text');
            $table->string('fix_surface')->nullable()->after('fix_action');
            $table->string('fix_link')->nullable()->after('fix_surface');
        });
    }

    public function down(): void
    {
        Schema::table('escalation_cards', function (Blueprint $table) {
            $table->dropColumn(['explanation_facts', 'explanation_text', 'fix_action', 'fix_surface', 'fix_link']);
        });
    }
};
