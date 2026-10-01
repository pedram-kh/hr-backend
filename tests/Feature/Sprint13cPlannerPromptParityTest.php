<?php

namespace Tests\Feature;

use App\Services\Agent\ToolRegistry;
use App\Services\GuardrailPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 13c (plan.md §2.2, "no new planner call"): the planner hint for the lane lives in the `general_knowledge` tool
 * description, which is sent ONLY when the tool is registered. So with the lane off the tool list — and therefore the
 * planner prompt hash hr-ai computes from the tools actually sent — is exactly what it was; and the PHP and Python
 * copies of the description never drift.
 */
class Sprint13cPlannerPromptParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_the_lane_off_the_planner_is_not_offered_the_tool_at_all(): void
    {
        config(['hr.general_lane.enabled' => false, 'hr.general_lane.model_knowledge' => true]);
        GuardrailPolicy::flush();
        $names = array_column($this->app->make(ToolRegistry::class)->definitions(), 'name');

        $this->assertNotContains('general_knowledge', $names);
        $this->assertSame(['salary_lookup', 'reference_fact', 'convenio_search', 'national_law', 'ask_employee'], $names);
    }

    public function test_the_tool_is_offered_when_the_lane_is_on_regardless_of_the_sub_flag(): void
    {
        foreach ([false, true] as $sub) {
            $this->app->forgetInstance(ToolRegistry::class);
            config(['hr.general_lane.enabled' => true, 'hr.general_lane.model_knowledge' => $sub]);
            GuardrailPolicy::flush();
            $this->assertContains('general_knowledge', array_column($this->app->make(ToolRegistry::class)->definitions(), 'name'));
        }
    }

    public function test_the_php_and_python_descriptions_are_the_same_text_and_carry_the_abstained_hint(): void
    {
        config(['hr.general_lane.enabled' => true]);
        GuardrailPolicy::flush();
        $php = collect($this->app->make(ToolRegistry::class)->definitions())->firstWhere('name', 'general_knowledge')['description'];

        $pyFile = base_path('../hr-ai/app/planner/tools.py');
        if (! is_file($pyFile)) {
            $this->markTestSkipped('hr-ai is not checked out next to hr-backend.');
        }
        $py = (string) file_get_contents($pyFile);
        // the Python literal is a run of adjacent string literals; rebuild it and compare
        $this->assertSame(1, preg_match('/"name": "general_knowledge",\s*"description": \((.*?)\),\s*"input_schema"/s', $py, $m));
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/s', $m[1], $parts);
        $pyText = stripslashes(implode('', $parts[1]));

        $this->assertSame($php, $pyText, 'hr-backend GeneralKnowledgeTool::definition() and hr-ai planner/tools.py drifted');
        $this->assertStringContainsString('abstained', $php);
        $this->assertStringContainsString('nunca la propongas como primera herramienta', $php, 'the lane is still never first by prompt');
    }
}
