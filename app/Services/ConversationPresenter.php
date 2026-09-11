<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\MessageCitation;
use App\Models\MessageTrace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Serialize a chat session's messages into the SAME shape the live chat turn
 * returns (answer/citations/trace), plus the Sprint-4 `hr_agent` human turn.
 *
 * Two audiences:
 *  - 'employee' — the employee hydrating their own chat. A human reply is
 *    attributed as "Recursos Humanos" ONLY — never the admin's name/email/PII.
 *    Sprint 10a (Correction-01/E3): also gets no `trace` key and no citation
 *    excerpts — `source_labels` (document display names only) instead.
 *  - 'admin'    — the card-scoped board view. A human reply shows the authoring
 *    admin's name (internal attribution); the trace explains why it escalated.
 *    Unaffected by Correction-01 — full `trace` + citation excerpts, as always.
 *
 * Used by both the employee `GET /chat/session` and the card-detail read. The
 * admin surfaces reuse the Sprint-2/3 chat components (CitationList,
 * TracePanel) on the ADMIN branch's output; the employee's own `ChatScreen`
 * renders the EMPLOYEE branch's `source_labels` line instead (never those two
 * components).
 */
class ConversationPresenter
{
    public const AUDIENCE_EMPLOYEE = 'employee';

    public const AUDIENCE_ADMIN = 'admin';

    /** Human-reply label shown to the employee (attribution without PII). */
    public const HR_LABEL = 'Recursos Humanos';

    /**
     * @return list<array<string,mixed>>
     */
    public function present(ChatSession $session, string $audience): array
    {
        $messages = $session->messages()->with('author:id,full_name')->get();

        $assistantIds = $messages->where('role', 'assistant')->pluck('id')->all();
        $traces = MessageTrace::whereIn('message_id', $assistantIds)->get()->keyBy('message_id');
        $citations = MessageCitation::whereIn('message_id', $assistantIds)
            ->with('document:id,uuid,title,authority_level')
            ->get()
            ->groupBy('message_id');

        $chunkIds = $citations->flatten(1)->pluck('chunk_id')->filter()->unique()->all();
        $snippets = $chunkIds === []
            ? collect()
            : DB::table('document_chunks')->whereIn('id', $chunkIds)->pluck('content', 'id');

        return $messages->map(function ($m) use ($audience, $traces, $citations, $snippets) {
            $isAssistant = $m->role === 'assistant';
            $trace = $isAssistant ? ($traces->get($m->id)?->trace) : null;
            $floor = $trace['floor_decision'] ?? [];
            $resolvedCitations = $isAssistant ? $this->citations($citations->get($m->id), $snippets) : [];

            $row = [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
                'author_label' => $this->authorLabel($m->role, $m->author?->full_name, $audience),
                'outcome' => $isAssistant ? ($floor['outcome'] ?? 'answer') : null,
                'escalated' => $isAssistant ? (($floor['outcome'] ?? null) === 'escalate') : false,
                'authority_used' => $isAssistant ? ($floor['authority_used'] ?? []) : [],
            ];

            // Sprint 10a — Correction-01 (E3, ADR-0018 spirit: the server is the
            // boundary). `trace` (router confidence, model name, chunk counts —
            // "Cómo llegué a esto") and citation EXCERPTS (the FUENTES snippet
            // block) are admin material. The admin board/history/quality-queue
            // audience keeps both, byte for byte — this branch never runs for
            // them. The employee audience — hydrating their OWN chat here — gets
            // neither: `trace` is OMITTED from the array (absent from the JSON,
            // not null: a live poll must not even carry the key), `citations` is
            // `[]`, and `source_labels` (document display names only, no
            // chunk_id/page/snippet/authority_level) is the one thing derived
            // from them for display. This is not CSS-hiding: the data never
            // leaves this method for that audience.
            if ($audience === self::AUDIENCE_ADMIN) {
                $row['citations'] = $resolvedCitations;
                $row['trace'] = $trace;
            } else {
                $row['citations'] = [];
                $row['source_labels'] = self::sourceLabels($resolvedCitations);
            }

            return $row;
        })->values()->all();
    }

    /**
     * Document display names only — no chunk_id, page, snippet or
     * authority_level — deduped and in citation order (Sprint 10a,
     * Correction-01/E3). Shared by both employee-facing surfaces: this
     * presenter's own EMPLOYEE branch (session hydration) and
     * `ChatController::message()` (the live turn), so the "one source line"
     * rule is computed in exactly one place.
     *
     * @param  list<array<string,mixed>>  $resolvedCitations
     * @return list<string>
     */
    public static function sourceLabels(array $resolvedCitations): array
    {
        $labels = [];
        foreach ($resolvedCitations as $c) {
            $title = $c['document_title'] ?? null;
            if ($title !== null && $title !== '' && ! in_array($title, $labels, true)) {
                $labels[] = $title;
            }
        }

        return $labels;
    }

    private function authorLabel(string $role, ?string $adminName, string $audience): ?string
    {
        if ($role !== 'hr_agent') {
            return null;
        }

        // The employee never sees the admin's identity; the admin board does.
        return $audience === self::AUDIENCE_EMPLOYEE
            ? self::HR_LABEL
            : ($adminName ?? self::HR_LABEL);
    }

    /**
     * @param  Collection<int, MessageCitation>|null  $rows
     * @param  Collection<int, string>  $snippets
     * @return list<array<string,mixed>>
     */
    private function citations($rows, $snippets): array
    {
        if ($rows === null) {
            return [];
        }

        return $rows->map(function (MessageCitation $c) use ($snippets) {
            $content = $c->chunk_id !== null ? (string) $snippets->get($c->chunk_id, '') : '';

            return [
                'chunk_id' => $c->chunk_id,
                'document_id' => $c->document_id,
                'document_uuid' => $c->document?->uuid,
                'document_title' => $c->document?->title,
                'authority_level' => $c->document?->authority_level,
                'page_from' => $c->page_number,
                'page_to' => $c->page_number,
                'page_number' => $c->page_number,
                'snippet' => $content === '' ? '' : trim(mb_substr((string) preg_replace('/\s+/', ' ', $content), 0, 160)),
            ];
        })->values()->all();
    }
}
