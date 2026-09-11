<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\Employee;
use App\Services\ChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 10a (ADR-0032) — the Estatuto fallback gold eval, plan §D.10.1.
 *
 * Two sets, run from one fixture, because the feature is a SPLIT and only the
 * pair proves it:
 *
 *   POSITIVE (`--profile=positive`, a `never_ingested` convenio) — the 13
 *   answerable questions must answer, grounded, from the Estatuto, each with the
 *   caveat persisted on `chat_messages.content`; the 2 salary questions must
 *   escalate with NO `floor_decision.fallback` key.
 *
 *   NEGATIVE (`--profile=negative`, an `expired_only` convenio) — the SAME 13
 *   questions must ALL escalate `estatuto_fallback_gap`, also with no fallback
 *   key. Under ultraactividad (ET 86.4) the expired convenio still governs, so
 *   the statutory minimum answering in its place is the failure this whole
 *   sprint exists to prevent. Per the plan this is the most important row in the
 *   eval, and it is the run's exit code.
 *
 * NOT read-only, unlike `succession:gold-eval` — a chat turn is the unit under
 * test, so each question persists a session, two messages, citations and a
 * trace exactly as an employee asking it would. It therefore runs against the
 * seeded test accounts only and refuses any other address.
 */
class EstatutoGoldEval extends Command
{
    protected $signature = 'estatuto:gold-eval
                            {--profile=positive : positive|negative|second-negative}
                            {--email= : override the profile\'s account (must be a test-*@example.com address)}
                            {--file= : gold fixture path (default hr-docs/sprints/sprint-10a/eval/fallback-gold.json)}
                            {--only= : run one question id}
                            {--json : machine-readable output}';

    protected $description = 'Run the Estatuto fallback gold set (positive) or the trigger-split negative set. Persists chat turns; test accounts only.';

    private const PROFILES = [
        'positive' => 'test-fullgap@example.com',
        'negative' => 'test-andalucia@example.com',
        'second-negative' => 'test-midingest@example.com',
    ];

    public function handle(ChatService $chat): int
    {
        $profile = (string) $this->option('profile');
        if (! isset(self::PROFILES[$profile])) {
            $this->error("Unknown --profile={$profile}. One of: ".implode(', ', array_keys(self::PROFILES)));

            return self::FAILURE;
        }

        $email = (string) ($this->option('email') ?: self::PROFILES[$profile]);
        // This command writes chat history. A typo that pointed it at a real
        // employee would put machine-generated turns in that person's own
        // conversation, so the address shape is a hard gate, not a convention.
        if (! str_starts_with($email, 'test-') || ! str_ends_with($email, '@example.com')) {
            $this->error("Refusing to run against {$email}: this command persists chat turns and is for seeded test-*@example.com accounts only.");

            return self::FAILURE;
        }

        $employee = Employee::where('email', $email)->first();
        if ($employee === null) {
            $this->error("Employee {$email} not found — run `staging:seed-test-users --chat-profiles` first.");

            return self::FAILURE;
        }

        $path = $this->option('file') ?: base_path('../hr-docs/sprints/sprint-10a/eval/fallback-gold.json');
        if (! is_file($path)) {
            $this->error("Gold fixture not found: {$path}");

            return self::FAILURE;
        }

        /** @var array{questions: list<array<string,mixed>>} $gold */
        $gold = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $questions = $gold['questions'] ?? [];
        if ($only = $this->option('only')) {
            $questions = array_values(array_filter($questions, fn ($q) => (string) $q['id'] === (string) $only));
        }

        $negative = $profile !== 'positive';
        $rows = [];
        foreach ($questions as $q) {
            $rows[] = $this->runQuestion($chat, $employee, $q, $negative);
        }

        return $this->option('json') ? $this->reportJson($rows, $profile, $email) : $this->report($rows, $profile, $email, $negative);
    }

    /**
     * One turn, plus everything the metrics need read back from what was PERSISTED
     * rather than from the return payload — spec §2.3(c) is a claim about
     * `chat_messages.content`, and the caveat is applied at the persistence
     * boundary, so reading the payload would not test it.
     *
     * @param  array<string,mixed>  $q
     * @return array<string,mixed>
     */
    private function runQuestion(ChatService $chat, Employee $employee, array $q, bool $negative): array
    {
        $expected = $negative && $q['expect'] === 'answer' ? 'escalate_fallback_gap' : (string) $q['expect'];
        $expectedNegativeSplit = $negative && $q['expect'] === 'answer';
        // On a negative profile the invariant is "no Estatuto answer", not "this
        // exact reason". A question can be stopped by an EARLIER gate that has
        // nothing to do with the fallback — the sensitive-topic guardrail fires
        // pre-router on a despido question, and the reference-fact pre-check
        // fires on a convenio that has a verified fact for the topic. Both are
        // the loop working as designed, and both still satisfy the split. So the
        // gate below is the invariant itself; the reason is reported, not judged.

        $chat->handleMessage($employee, (string) $q['question'], null, null);

        $msg = ChatMessage::where('role', 'assistant')
            ->whereHas('session', fn ($s) => $s->where('employee_id', $employee->id))
            ->with(['trace', 'citations'])
            ->orderByDesc('id')->first();

        $trace = $msg?->trace->trace ?? [];
        $floor = $trace['floor_decision'] ?? [];
        $outcome = (string) ($floor['outcome'] ?? '?');
        $reason = $floor['escalation_reason'] ?? null;
        // Presence, not truthiness: additivity depends on the key being ABSENT on
        // every non-fallback turn, and `?? null` cannot tell absent from null.
        $hasFallbackKey = array_key_exists('fallback', $floor);
        $grounded = $floor['grounding']['grounded'] ?? null;
        $content = (string) ($msg?->content ?? '');
        $caveat = str_contains($content, 'mínimos legales') && str_contains($content, 'convenio colectivo');

        $got = match (true) {
            $outcome === 'answer' => 'answer',
            $reason === 'estatuto_fallback_gap' => 'escalate_fallback_gap',
            $reason === 'salary_coverage_gap' || $reason === 'salary_not_in_chat' => 'escalate_salary',
            $reason === 'sensitive_topic' => 'escalate_sensitive',
            default => 'escalate_'.($reason ?? 'unknown'),
        };

        $citedDocs = $msg?->citations->pluck('document_id')->unique()->values()->all() ?? [];

        $pass = $negative
            ? $got !== 'answer' && ! $hasFallbackKey
            : $got === $expected;

        return [
            'id' => $q['id'],
            'question' => $q['question'],
            'expected' => $expected,
            'got' => $got,
            'pass' => $pass,
            'stopped_early' => $expectedNegativeSplit && $got !== 'escalate_fallback_gap',
            'article' => $q['article'] ?? null,
            'expect_content' => $q['expect_content'] ?? null,
            'outcome' => $outcome,
            'reason' => $reason,
            'fallback_key' => $hasFallbackKey ? ($floor['fallback'] ?? '(present, null)') : null,
            'has_fallback_key' => $hasFallbackKey,
            'grounded' => $grounded,
            'caveat' => $caveat,
            'prose_gap' => $trace['prose_gap']['classification'] ?? null,
            'cited_documents' => $citedDocs,
            'check_a_top_score' => $trace['retrieval']['top_score'] ?? null,
            'answer' => $content,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function report(array $rows, string $profile, string $email, bool $negative): int
    {
        $this->info("Estatuto fallback gold eval — {$profile} profile ({$email})");
        $this->newLine();

        foreach ($rows as $r) {
            $mark = $r['pass'] ? '<info>PASS</info>' : '<error>FAIL</error>';
            $early = ($r['stopped_early'] ?? false) ? '  <comment>(stopped by an earlier gate — still no Estatuto answer)</comment>' : '';
            $this->line("  [{$r['id']}] {$mark}  expected {$r['expected']}, got {$r['got']}{$early}");
            $this->line(sprintf(
                '        outcome=%s reason=%s fallback=%s grounded=%s caveat=%s prose_gap=%s',
                $r['outcome'], $r['reason'] ?? '—',
                $r['has_fallback_key'] ? $r['fallback_key'] : '(key absent)',
                $r['grounded'] === null ? '—' : ($r['grounded'] ? 'yes' : 'NO'),
                $r['caveat'] ? 'yes' : 'no',
                $r['prose_gap'] ?? '—',
            ));
            if ($r['expect_content'] !== null && $r['got'] === 'answer') {
                $this->line("        expected content: {$r['expect_content']}");
                $this->line('        answer: '.mb_substr(preg_replace('/\s+/', ' ', $r['answer']) ?? '', 0, 200).'…');
            }
        }

        $answered = array_values(array_filter($rows, fn ($r) => $r['got'] === 'answer'));
        $failures = array_values(array_filter($rows, fn ($r) => ! $r['pass']));
        // Spec §2.3(e), precisely: the key must be absent from turns that never
        // ran the prose fallback branch at all — the salary path, and any turn a
        // guardrail stopped before the router.
        //
        // It is NOT an error for the key to appear on a prose turn that took the
        // fallback branch and then failed a gate. `stampFallback()` is applied at
        // all four exits of `answerProse()`, deliberately: such a turn really was
        // searched against national law alone, and a trace that hid that would be
        // lying about what produced the escalation. Asserting "answers only" here
        // would be asserting something the design does not claim.
        $strayKeys = array_values(array_filter(
            $rows,
            fn ($r) => $r['has_fallback_key'] && $r['prose_gap'] === null,
        ));

        $this->newLine();
        $this->info('  Metrics (spec §2.3)');
        $this->line(sprintf('   (a) answer rate:       %d/%d', count($answered), count($rows)));
        $this->line(sprintf('   (b) grounded:          %d/%d answered turns', count(array_filter($answered, fn ($r) => $r['grounded'] === true)), count($answered)));
        $this->line(sprintf('   (c) caveat persisted:  %d/%d answered turns', count(array_filter($answered, fn ($r) => $r['caveat'])), count($answered)));
        $this->line(sprintf('   (e) fallback key on a turn that never took the prose branch: %d (must be 0)', count($strayKeys)));
        $this->line(sprintf('       (informational) fallback key on a prose turn that escalated: %d — expected, see the comment above',
            count(array_filter($rows, fn ($r) => $r['has_fallback_key'] && $r['got'] !== 'answer' && $r['prose_gap'] !== null))));

        $scores = array_values(array_filter(array_map(fn ($r) => $r['check_a_top_score'], $rows), fn ($s) => $s !== null));
        if ($scores !== []) {
            sort($scores);
            $this->line(sprintf('   Check-A top score: min %.4f · median %.4f · max %.4f',
                $scores[0], $scores[intdiv(count($scores), 2)], $scores[count($scores) - 1]));
        }

        if ($negative) {
            $reached = count(array_filter($rows, fn ($r) => $r['got'] === 'escalate_fallback_gap'));
            $early = count(array_filter($rows, fn ($r) => $r['stopped_early'] ?? false));
            $this->line(sprintf('   reached the fallback decision and refused it: %d · stopped by an earlier gate: %d', $reached, $early));
        }

        $this->newLine();
        if ($failures === [] && $strayKeys === []) {
            $this->info('  '.($negative
                ? 'TRIGGER SPLIT HOLDS: not one question was answered from the Estatuto, and no turn carried a fallback key.'
                : 'All expectations met.'));

            return self::SUCCESS;
        }

        foreach ($failures as $f) {
            $this->error("  [{$f['id']}] expected {$f['expected']}, got {$f['got']} — {$f['question']}");
        }
        if ($negative) {
            $this->error('  TRIGGER SPLIT BROKEN: an expired-convenio employee was answered from the Estatuto (ADR-0032, ET 86.4).');
        }

        return self::FAILURE;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function reportJson(array $rows, string $profile, string $email): int
    {
        $this->line(json_encode([
            'profile' => $profile,
            'email' => $email,
            'chunk_source' => DB::table('documents')->where('id', 75)->value('source_filename'),
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return array_filter($rows, fn ($r) => ! $r['pass']) === [] ? self::SUCCESS : self::FAILURE;
    }
}
