<?php

namespace App\Console\Commands;

use App\Models\Convenio;
use App\Services\FactResolutionService;
use Illuminate\Console\Command;

/**
 * Sprint 7d (ADR-0024) — the EXTENDED duplicate-flag pass over EXISTING facts.
 *
 * 7b-2's detector runs only during AI segmentation, and keys on the EXACT
 * normalized `group_label` — which is why it missed the documented Navarra
 * Acción e Intervención Social version pair ("Grupos 1 y 2" = 6 meses vs
 * "Grupo 2" = 4 meses). This command re-examines facts already in the database
 * using digit-token overlap (`App\Support\GroupLabel`), so the miss is caught
 * without re-running the LLM over anything.
 *
 * FLAG ONLY. It writes `duplicate_of_id` + `uncertainty` + an append-only
 * `tag_events` row, and never merges, retires, resolves or picks a winner. A
 * pair a human already resolved (`resolution IS NOT NULL`) is never re-flagged.
 *
 * `--dry-run` prints the pairs it would flag and writes nothing — the safe way to
 * see what a corpus contains before touching it.
 */
class FactsScanDuplicates extends Command
{
    protected $signature = 'facts:scan-duplicates
                            {--convenio= : limit to one convenio numero}
                            {--dry-run : list the pairs, write no flag}';

    protected $description = 'Flag reference-fact version pairs whose group labels OVERLAP (e.g. "Grupos 1 y 2" vs "Grupo 2") — flag only, never merges.';

    public function handle(FactResolutionService $resolver): int
    {
        $convenioId = null;
        if ($numero = $this->option('convenio')) {
            $convenioId = Convenio::where('numero', $numero)->value('id');
            if ($convenioId === null) {
                $this->error("No convenio with numero {$numero}.");

                return self::FAILURE;
            }
        }

        $result = $resolver->scanForOverlappingGroupDuplicates($convenioId, (bool) $this->option('dry-run'));

        if ($result['pairs'] === []) {
            $this->info("Scanned {$result['scanned']} fact(s); no overlapping-group version pairs found.");

            return self::SUCCESS;
        }

        $this->table(
            ['fact', 'looks like a version of', 'group A', 'group B', 'value A', 'value B'],
            array_map(fn ($p) => [
                $p['fact_id'], $p['duplicate_of_id'],
                mb_substr((string) $p['group_a'], 0, 22), mb_substr((string) $p['group_b'], 0, 22),
                mb_substr((string) $p['value_a'], 0, 24), mb_substr((string) $p['value_b'], 0, 24),
            ], $result['pairs']),
        );

        $verb = $result['dry_run'] ? 'would flag' : 'flagged';
        $this->info("Scanned {$result['scanned']} fact(s); {$verb} {$result['flagged']} pair(s) for human resolution. "
            .'Nothing was merged, retired or resolved.');

        if ($result['dry_run']) {
            $this->comment('Dry run — no flag written. Re-run without --dry-run to write them.');
        }

        return self::SUCCESS;
    }
}
