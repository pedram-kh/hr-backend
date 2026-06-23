<?php

namespace App\Services;

/**
 * The per-claim entailment grounding check (Sprint 2b-2 §5) — the REAL grounding
 * gate for a synthesized PROSE answer, replacing 2b-1's citation-coverage +
 * figure-guard PROXY as the gate. (Salary answers are SQL-grounded by
 * construction and SKIP this check.)
 *
 * Calls hr-ai /ground with the CAPABLE answer model (entailment is subtle; never
 * the cheap router model — resolved open question §5). For each claim in the
 * answer it asks whether a CITED chunk directly ENTAILS it. Table-aware: a chunk
 * that looks tabular is flagged so the prompt does not treat digit-presence as
 * entailment (Q5's lesson).
 *
 * Gate (resolved §9 B): ANY ungrounded claim → the caller escalates the whole
 * turn (low_confidence), conservative/audit-first — never drop-and-re-evaluate,
 * never a silent edit. A provider/transport failure is treated as NOT grounded.
 */
class GroundingService
{
    public function __construct(private readonly ExtractionClient $ai) {}

    /**
     * @param  list<array{chunk_id:int, content:string, authority_level:?string}>  $citedChunks
     * @param  array{provider:string,model:string,endpoint:?string}  $providerConfig
     * @return array{grounded:bool, claims:list<array<string,mixed>>, ungrounded:list<string>, error:?string, trace_fragment:array<string,mixed>}
     */
    public function check(string $question, string $answer, array $citedChunks, string $decryptedKey, array $providerConfig): array
    {
        $payload = array_map(fn ($c) => [
            'chunk_id' => (int) $c['chunk_id'],
            'content' => (string) $c['content'],
            'authority_level' => $c['authority_level'] ?? null,
            'is_tabular' => $this->looksTabular((string) $c['content']),
        ], $citedChunks);

        $resp = $this->ai->ground($question, $answer, $payload, $decryptedKey, $providerConfig);

        if (isset($resp['error'])) {
            // Provider/transport failure → NOT grounded (caller escalates; never
            // surfaces an unverified answer).
            return [
                'grounded' => false,
                'claims' => [],
                'ungrounded' => ['<grounding check unavailable>'],
                'error' => $resp['error'],
                'trace_fragment' => [],
            ];
        }

        return [
            'grounded' => (bool) ($resp['grounded'] ?? false),
            'claims' => $resp['claims'] ?? [],
            'ungrounded' => $resp['ungrounded'] ?? [],
            'error' => null,
            'trace_fragment' => $resp['trace_fragment'] ?? [],
        ];
    }

    /**
     * Cheap heuristic to flag a likely tabular/wage-table chunk so /ground is
     * table-aware. The salary path is SQL, but the parked 2a wage-table prose
     * chunks still exist in the index; a prose answer that cites one must not be
     * ruled "grounded" on digit-presence. Conservative: errs toward flagging
     * (the prompt then demands row/column/context support, not a bare digit).
     */
    private function looksTabular(string $content): bool
    {
        $len = max(1, mb_strlen($content));
        $digits = (int) preg_match_all('/\d/u', $content);
        $digitRatio = $digits / $len;
        $moneyish = (int) preg_match_all('/\d[\d.,]*\s*(€|euros?)/iu', $content);

        return $digitRatio > 0.12 || $moneyish >= 4;
    }
}
