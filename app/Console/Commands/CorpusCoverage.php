<?php

namespace App\Console\Commands;

use App\Support\CorpusCoverageService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sprint 8, Step 2 (plan.md §5.6) — the export. A thin CLI wrapper:
 * `CorpusCoverageService::grid()` → render as markdown → write the file.
 *
 * The admin screen's "Export snapshot" button (Step 7) calls the SAME
 * `CorpusCoverageService::grid()`/`toMarkdown()` methods via an authenticated
 * endpoint — literally the same code path, which is what makes
 * `CorpusCoverageAgreementTest` trivial rather than aspirational.
 */
class CorpusCoverage extends Command
{
    protected $signature = 'corpus:coverage {--out=hr-docs/sprints/sprint-08/coverage-grid.md : output path, relative to the repo\'s sibling directory (hr-docs is a sibling of hr-backend)} {--print : print to stdout instead of writing a file}';

    protected $description = 'Export the convenio-level coverage grid (prose/salary/facts/rulings × every registry convenio) as markdown.';

    public function handle(CorpusCoverageService $service): int
    {
        $asOf = Carbon::today();
        $grid = $service->grid($asOf);
        $noRegistry = $service->noRegistryConvenioRows();
        $markdown = $service->toMarkdown($grid, $noRegistry, $asOf);

        if ($this->option('print')) {
            $this->line($markdown);

            return self::SUCCESS;
        }

        $out = $this->option('out');
        $path = str_starts_with($out, '/') ? $out : base_path('../'.$out);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $markdown);

        $this->info("Coverage grid written to {$path} (".count($grid).' convenios, '.count($noRegistry).' no-registry rows)');

        return self::SUCCESS;
    }
}
