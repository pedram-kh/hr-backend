<?php

namespace App\Support;

/**
 * The admin deep-link scheme (ADR-0029), as a shared set of builders.
 *
 * Extracted from `EscalationExplainer::registry()` (Sprint 7g) during Sprint 8
 * (plan.md §5.4) specifically so `CorpusCoverageService`'s reason-code
 * unblocking links point at the SAME hash-link builders an escalation card's
 * `fix_link` does, rather than a second, independently-drifting copy of the
 * same five link shapes. `EscalationExplainer` was refactored to call these
 * too (behaviourally identical — same strings, same hash scheme), so there is
 * now exactly one place that knows the `#view=…` scheme `AdminShell.tsx`
 * reads on mount.
 */
final class AdminLinks
{
    public static function groups(?int $convenioId): string
    {
        return $convenioId !== null
            ? "#view=review&tab=groups&convenio={$convenioId}"
            : '#view=review&tab=groups';
    }

    public static function fact(?string $uuid): string
    {
        return $uuid !== null
            ? "#view=review&tab=reference-facts&fact={$uuid}"
            : '#view=review&tab=reference-facts';
    }

    public static function employee(?string $uuid): string
    {
        return $uuid !== null
            ? "#view=directory&emp={$uuid}"
            : '#view=directory';
    }

    public static function vocabulary(): string
    {
        return '#view=review&tab=vocabulary';
    }

    public static function tagging(): string
    {
        return '#view=review&tab=tagging';
    }

    /**
     * @param  int|null  $convenioId  Sprint 8 follow-up (found live, eyes-on
     *   2026-09-10): a coverage-lens gap leaf with no underlying document at
     *   all still needs a fix surface to open — Documents pre-filtered to the
     *   convenio in question (`DocumentController::index`'s existing
     *   `convenio_id` filter, `DocumentsPage`'s new `initialConvenioId` prop).
     */
    public static function documents(?int $convenioId = null): string
    {
        return $convenioId !== null
            ? "#view=documents&convenio={$convenioId}"
            : '#view=documents';
    }

    public static function guardrails(): string
    {
        return '#view=guardrails';
    }

    public static function settings(): string
    {
        return '#view=settings';
    }

    /** Sprint 8 — coverage-gap deep link (new §view, wired in Step 7). */
    public static function coverage(?int $convenioId): string
    {
        return $convenioId !== null
            ? "#view=coverage&convenio={$convenioId}"
            : '#view=coverage';
    }
}
