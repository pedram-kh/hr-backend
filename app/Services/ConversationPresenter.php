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
 *  - 'admin'    — the card-scoped board view. A human reply shows the authoring
 *    admin's name (internal attribution); the trace explains why it escalated.
 *
 * Used by both the employee `GET /chat/session` and the card-detail read, so the
 * frontend reuses the Sprint-2/3 chat components (CitationList, TracePanel).
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

            return [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
                'author_label' => $this->authorLabel($m->role, $m->author?->full_name, $audience),
                'outcome' => $isAssistant ? ($floor['outcome'] ?? 'answer') : null,
                'escalated' => $isAssistant ? (($floor['outcome'] ?? null) === 'escalate') : false,
                'authority_used' => $isAssistant ? ($floor['authority_used'] ?? []) : [],
                'citations' => $isAssistant ? $this->citations($citations->get($m->id), $snippets) : [],
                'trace' => $trace,
            ];
        })->values()->all();
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
