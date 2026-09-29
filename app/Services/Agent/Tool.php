<?php

namespace App\Services\Agent;

/**
 * Sprint 13, build step 3 (plan.md §B.2) — the contract every planner tool
 * follows. Real v1 tools (`salary_lookup`, `reference_fact`, `convenio_search`,
 * `national_law`, `ask_employee`, `general_knowledge`) land in steps 5/9;
 * `finalize`/`escalate` are handled specially by {@see AgentChatService}
 * (they are control tools, not registry entries — §B.3.8/§B.5).
 */
interface Tool
{
    /** The planner-facing name — matches the tool call's `tool` field exactly. */
    public function name(): string;

    /**
     * Planner-facing description + JSON input schema (§C.8), mirrored as a
     * validation schema in hr-ai's `/plan` tool list.
     *
     * @return array{name:string,description:string,input_schema:array<string,mixed>}
     */
    public function definition(): array;

    /**
     * Whether a call to this tool counts against the 2-per-conversation
     * clarification budget (§B.4.3, §C.9) — true only for `ask_employee`
     * (and the deterministic `needs_category` salary pick, counted the same
     * way per §B.4.3's own note). False for every other tool.
     */
    public function countsAsClarification(): bool;

    /** @param  array<string,mixed>  $input */
    public function run(array $input, TurnState $state): ToolResult;
}
