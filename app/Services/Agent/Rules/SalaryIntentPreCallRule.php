<?php

namespace App\Services\Agent\Rules;

use App\Services\Agent\Rule;
use App\Services\Agent\ToolResult;
use App\Services\Agent\TurnState;
use App\Services\Agent\Verdict;
use App\Services\RouterService;
use App\Support\TopicLexicon;

/**
 * Sprint 13, CP-2 fix for whitelist case wt-03 (review.md "wt-03 fix") — an AGENT-ONLY
 * pre-call rule on `convenio_search` and `national_law`.
 *
 * The finding: "Llevo cinco años en la empresa, ¿cuántos trienios cobro?" is a salary
 * question, but the deterministic `RouterService::matchesSalary()` lexicon does not
 * contain "trienio", so round 0 did not short-circuit and the planner went to
 * `convenio_search`; the synthesised prose then quoted a euro figure for a category
 * the employee does not have. Classic reached `salary_sql` through its LLM router
 * and escalated. A salary figure is only ever valid from `salary_lookup` (ADR-0006/0027).
 *
 * Rule: when the question — or the planner's own reformulation in the call input
 * (`query`, `subqueries`, `decomposed_queries`: the deterministic form of "planner-
 * declared salary intent") — has salary/pay intent, `convenio_search`/`national_law`
 * is DENIED; the planner is told to use `salary_lookup` (which quotes the table, asks
 * for the category or escalates) or escalate.
 *
 * Exception, to keep the cross-path behaviour classic and round 0 already have: a
 * COMPOUND question (`deterministicSplit` ≥ 2 segments) with at least one segment that
 * has NO pay intent keeps prose retrieval for that half; the post-call
 * {@see FigureNotFromTablePostCallRule} then guarantees no euro amount can ride along.
 *
 * Classic never runs this rule (it is registered only on the agent's RuleEngine), so
 * the golden traces are unaffected.
 */
final class SalaryIntentPreCallRule implements Rule
{
    /**
     * Pay-intent terms beyond `RouterService::SALARY_PATTERNS` (which is tested first
     * via `matchesSalary()`). Post-normalization (lowercase, accents/ñ folded).
     * Antigüedad only counts when pay language is in the same question.
     *
     * @var list<string>
     */
    public const PATTERNS = [
        // "retribución" (the noun) only — "permisos retribuidos" is paid LEAVE, not pay.
        '/\bretribuci\w+/u',
        '/\b(trienios?|quinquenios?|bienios?)\b/u',
        '/\bplus(es)?\b/u',
        '/\bcomplementos?\b/u',
        '/\bnominas?\b/u',
        '/\b(salari\w*|sueldos?)\b/u',
        '/\bantiguedad\b.*\b(cobr\w*|pag\w*|import\w*|cuantia|euros?|plus|complemento|retribuci\w+)\b/u',
        '/\b(cobr\w*|pag\w*|import\w*|cuantia|euros?|plus|complemento|retribuci\w+)\b.*\bantiguedad\b/u',
    ];

    public function __construct(private readonly RouterService $router) {}

    public function id(): string
    {
        return 'salary_intent_pre_call';
    }

    public function evaluate(TurnState $state, ?array $call, ?ToolResult $result): Verdict
    {
        if (! $this->hasPayIntent($state->question) && ! $this->plannerDeclaredPayIntent($call)) {
            return Verdict::allow();
        }

        // Compound question with a genuinely non-pay half: that half still needs prose.
        if ($this->hasProseHalf($state->question)) {
            return Verdict::allow();
        }

        return Verdict::deny(
            'Esta pregunta es de retribución (salario, trienios, pluses, complementos, antigüedad) — '
            .'usa salary_lookup o escala; no busques la cifra en el texto del convenio.',
            $this->id(),
        );
    }

    /** Pay intent in free text: the router's salary lexicon OR this rule's extra terms. */
    public function hasPayIntent(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        if ($this->router->matchesSalary($text)) {
            return true;
        }
        $normalized = str_replace('ñ', 'n', TopicLexicon::stripAccents(mb_strtolower($text, 'UTF-8')));
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The planner's own query text on this call (reformulations included). */
    private function plannerDeclaredPayIntent(?array $call): bool
    {
        $input = is_array($call['input'] ?? null) ? $call['input'] : [];
        $texts = [];
        if (is_string($input['query'] ?? null)) {
            $texts[] = $input['query'];
        }
        foreach (['subqueries', 'decomposed_queries'] as $key) {
            foreach ((array) ($input[$key] ?? []) as $t) {
                if (is_string($t)) {
                    $texts[] = $t;
                }
            }
        }
        foreach ($texts as $t) {
            if ($this->hasPayIntent($t)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A genuinely compound salary+prose question. Two ways, both deliberately strict so a
     * "y" inside one question ("¿Hay algún complemento por turnos y cuánto es?") is NOT
     * mistaken for a second topic: (1) the classic/round-0 cross-path detection
     * (`crossPathProseClauses`, unchanged lexicon) finds a prose clause — preserving the
     * agent's existing behaviour for those turns; or (2) the employee wrote two or more
     * explicit "¿ … ?" questions and at least one of them has no pay intent.
     */
    private function hasProseHalf(string $question): bool
    {
        if ($this->router->crossPathProseClauses($question) !== []) {
            return true;
        }

        if (preg_match_all('/¿([^?¿]+)\?/u', $question, $m) && count($m[1]) >= 2) {
            foreach ($m[1] as $segment) {
                if (mb_strlen(trim($segment)) >= 8 && ! $this->hasPayIntent($segment)) {
                    return true;
                }
            }
        }

        return false;
    }
}
