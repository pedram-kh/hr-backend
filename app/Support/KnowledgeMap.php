<?php

namespace App\Support;

use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;

/**
 * Read-only query helpers for the Sprint-3 Knowledge Center: chunk health and
 * coverage-gap detection. Everything here is a DERIVATION over data Sprint
 * 1/2a/2c already wrote — no scan, no pipeline (spec §3). hr-backend reads
 * `document_chunks` on its own (full) connection; the scoped `hr_ai` role is
 * only for hr-ai's writes.
 */
class KnowledgeMap
{
    /** Prose / retrievable document types (data-model §5 embedding-eligibility set). */
    public const PROSE_TYPE_CODES = ['convenio_text', 'national_law', 'partial_agreement'];

    /** @return list<int> the document_type ids for the prose-eligible set (cached per request). */
    public static function proseTypeIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = DocumentType::whereIn('code', self::PROSE_TYPE_CODES)->pluck('id')->all();
        }

        return $ids;
    }

    /**
     * Chunk-health summary for one document, straight from `document_chunks`.
     *
     * NOTE (resolved Q5): the es/eu split is intentionally NOT reported — the
     * chunker tags language internally but `document_chunks` has no `language`
     * column (data-model §5), so it is not derivable here. We report count /
     * token total / page span / embed-presence / the 0-chunk flag instead.
     *
     * @return array<string,mixed>
     */
    public static function chunkHealth(int $documentId): array
    {
        $row = DB::table('document_chunks')
            ->where('document_id', $documentId)
            ->selectRaw('count(*) as chunk_count')
            ->selectRaw('coalesce(sum(token_count),0) as token_total')
            ->selectRaw('min(page_from) as first_page')
            ->selectRaw('max(page_to) as last_page')
            ->selectRaw('bool_or(embedding is not null) as has_embeddings')
            ->first();

        $count = (int) ($row->chunk_count ?? 0);

        return [
            'chunk_count' => $count,
            'token_total' => (int) ($row->token_total ?? 0),
            'first_page' => $row->first_page !== null ? (int) $row->first_page : null,
            'last_page' => $row->last_page !== null ? (int) $row->last_page : null,
            'has_embeddings' => (bool) ($row->has_embeddings ?? false),
            'zero_chunks' => $count === 0,
            // es/eu split is not stored on document_chunks (data-model §5); omitted by design.
            'language_split_available' => false,
            'note' => 'Language split (es/eu) is not stored on document_chunks (data-model §5); omitted by design.',
        ];
    }

    /**
     * The coverage-gap set as flagged nodes (spec §3), all derived from existing
     * data. Four distinct kinds — kept distinct so the map never labels an
     * answerable scope as a hole:
     *   - `unanswerable`        active prose doc with 0 chunks (scanned / under_review)
     *   - `expired_no_successor` a convenio with only historical prose, no active prose
     *   - `suspected_mistag`    convenio_text + active + title/filename says "tabla"
     *   - `date_expired_active` active prose doc whose validity_end is past (STALENESS,
     *                           not a hole — the scope is still answerable)
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function coverageGaps(): array
    {
        $proseIds = self::proseTypeIds();
        $proseList = implode(',', array_map('intval', $proseIds)) ?: '0';

        // 3.1 — active but 0-chunk (scanned / unanswerable).
        $unanswerable = DB::select("
            select d.id, d.uuid, d.title, d.retrieval_status, d.tagging_status,
                   c.numero, t.name as territory, s.name as sector
            from documents d
            left join convenios c on c.id = d.convenio_id
            left join territories t on t.id = c.territory_id
            left join sectors s on s.id = c.sector_id
            where d.retrieval_status = 'active'
              and d.document_type_id in ($proseList)
              and not exists (select 1 from document_chunks ch where ch.document_id = d.id)
            order by d.id
        ");

        // 3.2 — expired with no active successor (a real coverage HOLE, per convenio cell).
        $expired = DB::select("
            select cv.id as convenio_id, cv.numero, t.name as territory, s.name as sector
            from convenios cv
            left join territories t on t.id = cv.territory_id
            left join sectors s on s.id = cv.sector_id
            where exists (select 1 from documents d where d.convenio_id = cv.id
                          and d.document_type_id in ($proseList) and d.retrieval_status = 'historical')
              and not exists (select 1 from documents d where d.convenio_id = cv.id
                          and d.document_type_id in ($proseList) and d.retrieval_status = 'active')
            order by cv.id
        ");

        // 3.3 — suspected salary-table mistag (convenio_text + active + says "tabla").
        $convenioTextId = DocumentType::where('code', 'convenio_text')->value('id');
        $mistag = DB::select('
            select d.id, d.uuid, d.title, d.source_filename, d.retrieval_status, d.tagging_status,
                   c.numero, t.name as territory, s.name as sector
            from documents d
            left join convenios c on c.id = d.convenio_id
            left join territories t on t.id = c.territory_id
            left join sectors s on s.id = c.sector_id
            where d.document_type_id = ?
              and d.retrieval_status = \'active\'
              and (d.title ilike \'%tabla%\' or d.source_filename ilike \'%tabla%\')
            order by d.id
        ', [$convenioTextId]);

        // Staleness — active prose doc whose validity_end is past. DISTINCT from a
        // hole: the scope is still answerable, so this is --neutral, never a gap
        // the map should treat as "no document". (Empty in today's corpus, but the
        // query is trivial and future-proofs the distinction — resolved sanity check.)
        $stale = DB::select("
            select d.id, d.uuid, d.title, d.validity_end, d.retrieval_status, d.tagging_status,
                   c.numero, t.name as territory, s.name as sector
            from documents d
            left join convenios c on c.id = d.convenio_id
            left join territories t on t.id = c.territory_id
            left join sectors s on s.id = c.sector_id
            where d.retrieval_status = 'active'
              and d.document_type_id in ($proseList)
              and d.validity_end is not null and d.validity_end < current_date
            order by d.id
        ");

        return [
            'unanswerable' => array_map(fn ($r) => (array) $r, $unanswerable),
            'expired_no_successor' => array_map(fn ($r) => (array) $r, $expired),
            'suspected_mistag' => array_map(fn ($r) => (array) $r, $mistag),
            'date_expired_active' => array_map(fn ($r) => (array) $r, $stale),
        ];
    }
}
