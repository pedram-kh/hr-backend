<?php

namespace Tests\Unit;

use App\Models\ConvenioGroup;
use App\Support\FactGroupBindingPlanner;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 7f (ADR-0028) — the `group_label` grammar, tested against the labels
 * the REAL corpus actually contains, with no database.
 *
 * Two halves, and the second half is the point.
 *
 * The first half proves the planner resolves what it should: exact groups, roman
 * numerals, enumerations, and the compound labels Hostelería Navarra's verified
 * facts use ("Grupo 1 (todas las áreas) y Grupo 2 (área 5)" is one fact spanning
 * two nodes, which is why the binding table is many-to-many).
 *
 * The second half proves it REFUSES what it cannot prove. Those cases are not
 * gaps; they are the fix. The matcher this sprint deletes finds a digit in a
 * label and always answers, which is how "Grupo 2 (área 5)" becomes plain
 * "grupo 2". A parser that guessed more cleverly would repeat that mistake with
 * better manners, so every unproven label lands in front of a human instead.
 */
class FactGroupBindingPlannerTest extends TestCase
{
    private FactGroupBindingPlanner $planner;

    /** @var Collection<int,ConvenioGroup> */
    private Collection $navarraHosteleria;

    /** @var Collection<int,ConvenioGroup> */
    private Collection $coeas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new FactGroupBindingPlanner;

        // Convenio 21 as its convenio really reads: G2 priced in two slices.
        $this->navarraHosteleria = collect([
            $this->node(1, 'Grupo 1', '1'),
            $this->node(2, 'Grupo 2', '2'),
            $this->node(3, 'área 5', 'area-5', 2),
            $this->node(4, 'resto áreas', 'resto-areas', 2),
            $this->node(5, 'Grupo 3', '3'),
        ]);

        // COEAS: six unsplit groups (convenios 3, 4, 11).
        $this->coeas = collect([
            $this->node(11, 'Grupo 1', '1'),
            $this->node(12, 'Grupo 2', '2'),
            $this->node(13, 'Grupo 3', '3'),
            $this->node(14, 'Grupo 4', '4'),
            $this->node(15, 'Grupo 5', '5'),
            $this->node(16, 'Grupo 6', '6'),
        ]);
    }

    // ── What it resolves ────────────────────────────────────────────────────

    public function test_a_plain_group_label_resolves_to_its_node(): void
    {
        $this->assertResolved([11], 'Grupo 1', $this->coeas);
    }

    public function test_a_roman_numeral_reaches_the_same_node_as_its_arabic_twin(): void
    {
        // Fixture file 1 writes "Grupo 1" and file 2 writes "Grupo I" for the
        // same groups. An exact matcher must see one node, not two.
        $this->assertResolved([11], 'Grupo I', $this->coeas);
        $this->assertResolved([13], 'Grupo III', $this->coeas);
    }

    public function test_an_enumeration_binds_one_fact_to_every_group_it_names(): void
    {
        // Facts 10, 13 and 43, verbatim, in both spacings the corpus uses.
        $this->assertResolved([13, 14, 15, 16], 'Grupos 3,4,5 y 6', $this->coeas);
        $this->assertResolved([13, 14, 15, 16], 'Grupos 3, 4, 5 y 6', $this->coeas);
    }

    public function test_a_two_group_enumeration_resolves(): void
    {
        $this->assertResolved([11, 12], 'Grupos 1 y 2', $this->coeas);
    }

    public function test_todas_las_areas_means_the_group_itself_not_its_children(): void
    {
        // Fact 46. "Grupo 3 (todas las áreas)" is the whole group; binding it to
        // the children instead would leave the group node itself unanswerable.
        $this->assertResolved([5], 'Grupo 3 (todas las áreas)', $this->navarraHosteleria);
    }

    public function test_a_parenthesised_sub_area_resolves_to_the_child(): void
    {
        // Fact 45 — and THE case the deleted digit matcher gets wrong: it reads
        // this as group 2 and answers 60/45/30 for every area.
        $this->assertResolved([4], 'Grupo 2 (resto áreas)', $this->navarraHosteleria);
    }

    public function test_the_real_compound_label_spans_a_group_and_another_groups_sub_area(): void
    {
        // Fact 44, verbatim. One fact, two nodes — the reason
        // `reference_fact_group_scopes` is many-to-many rather than a column.
        $plan = $this->planner->resolveLabel(
            'Grupo 1 (todas las áreas) y Grupo 2 (área 5)',
            $this->navarraHosteleria,
        );

        $this->assertSame(FactGroupBindingPlanner::STATUS_RESOLVED, $plan['status']);
        $this->assertSame([1, 3], $plan['node_ids']);
        $this->assertSame('compound', $plan['kind']);
    }

    public function test_the_inverted_spelling_of_that_same_scope_reaches_the_same_nodes(): void
    {
        // Fact 34 says "Grupo 1 y área cinco de Grupo 2" where fact 44 says
        // "Grupo 1 (todas las áreas) y Grupo 2 (área 5)". Same scope, different
        // prose, spelled-out numeral — both must land on nodes 1 and 3, or the
        // two file versions of one rule would bind to different structure.
        $plan = $this->planner->resolveLabel('Grupo 1 y área cinco de Grupo 2', $this->navarraHosteleria);

        $this->assertSame(FactGroupBindingPlanner::STATUS_RESOLVED, $plan['status']);
        $this->assertSame([1, 3], $plan['node_ids']);
    }

    public function test_an_empty_label_is_convenio_wide_and_binds_to_nothing(): void
    {
        // Fact 40's shape. Tier 3 already answers this correctly; a group
        // binding would narrow a convenio-wide rule and break it.
        foreach ([null, '', '   '] as $label) {
            $plan = $this->planner->resolveLabel($label, $this->coeas);
            $this->assertSame(FactGroupBindingPlanner::STATUS_CONVENIO_WIDE, $plan['status']);
            $this->assertSame([], $plan['node_ids']);
        }
    }

    // ── What it refuses ─────────────────────────────────────────────────────

    public function test_a_bare_group_reference_is_refused_when_that_group_is_split(): void
    {
        // THE guard. "Grupo 2" is unambiguous in COEAS and under-specified in
        // Hostelería Navarra, where G2 is priced in two slices. Same string,
        // different answer, because the structure differs — and defaulting to
        // "the whole group" is precisely the mis-scope the sprint removes.
        $this->assertResolved([12], 'Grupo 2', $this->coeas);

        $plan = $this->planner->resolveLabel('Grupo 2', $this->navarraHosteleria);
        $this->assertSame(FactGroupBindingPlanner::STATUS_NEEDS_HUMAN, $plan['status']);
        $this->assertSame('ambiguous_split_group', $plan['kind']);
        $this->assertSame([], $plan['node_ids']);
    }

    public function test_a_complement_label_goes_to_a_human(): void
    {
        // Facts 28 and 56. "Resto de grupos" is defined by what it excludes, so
        // resolving it means knowing which groups the other facts claim — a
        // judgement, not a parse.
        $plan = $this->planner->resolveLabel('Resto de grupos', $this->coeas);
        $this->assertSame(FactGroupBindingPlanner::STATUS_NEEDS_HUMAN, $plan['status']);
        $this->assertSame('complement', $plan['kind']);
        $this->assertNotNull($plan['reason']);
    }

    public function test_an_except_label_goes_to_a_human_even_though_a_human_can_see_what_it_means(): void
    {
        // Fact 35, "Grupo 2 excepto área cinco", does mean `resto áreas` — but
        // only because someone read the convenio and split G2 that way. Deriving
        // it here would be inference dressed as parsing.
        $plan = $this->planner->resolveLabel('Grupo 2 excepto área cinco', $this->navarraHosteleria);
        $this->assertSame(FactGroupBindingPlanner::STATUS_NEEDS_HUMAN, $plan['status']);
        $this->assertSame('complement', $plan['kind']);
    }

    public function test_a_label_that_is_not_a_group_at_all_binds_to_nothing(): void
    {
        // Both real: fact 82's label is a CONTRACT TYPE parked in the group
        // column, and fact 29's is a cross-reference to another convenio. The
        // deleted matcher would find no digit in the first and answer
        // convenio-wide; what matters is that neither invents a group here.
        foreach (['Contratos de formación en alternancia', 'Establecido en COEAS ESTATAL'] as $label) {
            $plan = $this->planner->resolveLabel($label, $this->coeas);
            $this->assertSame(FactGroupBindingPlanner::STATUS_NOT_A_GROUP, $plan['status'], $label);
            $this->assertSame([], $plan['node_ids'], $label);
        }
    }

    public function test_a_group_the_approved_tree_does_not_contain_is_refused(): void
    {
        // Not "resolve to the nearest node" — there is no nearest node.
        $plan = $this->planner->resolveLabel('Grupo 9', $this->coeas);
        $this->assertSame(FactGroupBindingPlanner::STATUS_NEEDS_HUMAN, $plan['status']);
        $this->assertSame('unknown_group', $plan['kind']);
    }

    public function test_a_sub_area_the_approved_tree_does_not_contain_is_refused(): void
    {
        $plan = $this->planner->resolveLabel('Grupo 2 (área 7)', $this->navarraHosteleria);
        $this->assertSame(FactGroupBindingPlanner::STATUS_NEEDS_HUMAN, $plan['status']);
        $this->assertSame('unknown_sub_area', $plan['kind']);
    }

    public function test_a_partly_resolvable_compound_label_binds_to_nothing_at_all(): void
    {
        // The most important refusal. "Grupo 1 y Grupo 9": node 1 exists, node 9
        // does not. Binding the half that parsed would answer confidently for
        // Grupo 1 and silently drop the rest of the fact's scope — a partial
        // binding is worse than none, because nothing about it looks wrong.
        $plan = $this->planner->resolveLabel('Grupo 1 y Grupo 9', $this->coeas);
        $this->assertSame(FactGroupBindingPlanner::STATUS_NEEDS_HUMAN, $plan['status']);
        $this->assertSame([], $plan['node_ids']);
        // Reported de-accented/lowercased, as the parser saw it; the reason
        // string quotes the label as printed so the reviewer can find it.
        $this->assertSame('grupo 9', $plan['unresolved_segment']);
        $this->assertStringContainsString('Grupo 1 y Grupo 9', (string) $plan['reason']);
    }

    public function test_with_no_approved_structure_nothing_resolves(): void
    {
        // Every convenio's state before Checkpoint 2. It must not fall back to
        // anything, least of all to matching on digits.
        $plan = $this->planner->resolveLabel('Grupo 1', collect());
        $this->assertSame(FactGroupBindingPlanner::STATUS_NEEDS_HUMAN, $plan['status']);
        $this->assertSame('no_structure', $plan['kind']);
    }

    public function test_a_sub_area_is_never_reachable_without_naming_its_group(): void
    {
        // "área 5" alone could belong to any group. It must not resolve to node
        // 3 just because that label happens to be unique today.
        $plan = $this->planner->resolveLabel('área 5', $this->navarraHosteleria);
        $this->assertNotSame(FactGroupBindingPlanner::STATUS_RESOLVED, $plan['status']);
        $this->assertSame([], $plan['node_ids']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $expected
     * @param  Collection<int,ConvenioGroup>  $nodes
     */
    private function assertResolved(array $expected, string $label, Collection $nodes): void
    {
        $plan = $this->planner->resolveLabel($label, $nodes);

        $this->assertSame(
            FactGroupBindingPlanner::STATUS_RESOLVED,
            $plan['status'],
            "\"{$label}\" should resolve, got {$plan['status']}: ".($plan['reason'] ?? ''),
        );
        $this->assertSame($expected, $plan['node_ids'], "\"{$label}\" bound to the wrong nodes");
    }

    private function node(int $id, string $label, string $code, ?int $parentId = null): ConvenioGroup
    {
        $node = new ConvenioGroup([
            'label' => $label,
            'code_normalized' => $code,
            'parent_id' => $parentId,
            'status' => ConvenioGroup::STATUS_APPROVED,
        ]);
        $node->id = $id;

        return $node;
    }
}
