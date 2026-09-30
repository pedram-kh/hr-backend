<?php

namespace App\Console\Commands\Concerns;

use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\ConvenioJobCategory;
use App\Models\Employee;
use App\Models\Territory;
use Illuminate\Support\Str;

/**
 * The gate-fixture conventions shared by `answer:gate` and Sprint 13b's `normalization:probe`
 * (moved verbatim out of `AnswerGate`): a fixture case -> concrete question variants + expectations,
 * and a case -> the employee it runs as.
 */
trait ResolvesGateCases
{
    /**
     * @param  array<string,mixed>  $raw
     * @return list<array<string,mixed>>
     */
    protected function normalizeCase(array $raw, int $index): array
    {
        $baseId = (string) ($raw['id'] ?? ('case_'.$index));

        $variants = [];
        if (array_key_exists('canonical_question', $raw) || array_key_exists('colloquial_question', $raw)) {
            if (isset($raw['canonical_question'])) {
                $variants[] = ['suffix' => 'canonical', 'question' => (string) $raw['canonical_question']];
            }
            if (isset($raw['colloquial_question'])) {
                $variants[] = ['suffix' => 'colloquial', 'question' => (string) $raw['colloquial_question']];
            }
        } elseif (isset($raw['question'])) {
            $variants[] = ['suffix' => null, 'question' => (string) $raw['question']];
        }

        $expect = $raw['expect'] ?? [
            'outcome' => $raw['expected_outcome'] ?? (isset($raw['expected_path']) ? 'answer' : null),
            'reason' => $raw['expected_reason'] ?? null,
            'path' => $raw['expected_path'] ?? null,
            'value_contains' => $raw['value_contains'] ?? null,
            'must_not_answer' => $raw['must_not_answer'] ?? false,
            'first_tool' => $raw['expected_tools']['first'] ?? $raw['expected_first_tool'] ?? null,
            'terminal' => $raw['expected_tools']['terminal'] ?? $raw['expected_terminal'] ?? null,
        ];
        if (isset($raw['expected_tools']['first'])) {
            $expect['first_tool'] = $raw['expected_tools']['first'];
        }

        $scope = null;
        if (isset($raw['convenio_id'])) {
            $scope = [
                'convenio_id' => (int) $raw['convenio_id'],
                'group_label' => $raw['group_label'] ?? null,
                'job_category' => $raw['job_category'] ?? null,
                'as_of_date' => $raw['as_of_date'] ?? null,
                'start_date' => $raw['start_date'] ?? null,
            ];
        }

        $out = [];
        foreach ($variants as $v) {
            $out[] = [
                'id' => $baseId.($v['suffix'] ? '.'.$v['suffix'] : ''),
                'email' => $raw['email'] ?? null,
                'scope' => $scope,
                'question' => $v['question'],
                'expect' => $expect,
                'class' => $raw['class'] ?? null,
                // Sprint 13b (plan.md §7.1): outcome-independent labels, computed once by label-banks.php
                'bank' => $raw['bank'] ?? null,
                'anchored' => array_key_exists('anchored', $raw) ? (bool) $raw['anchored'] : null,
                'phrasing' => $raw['authored'] ?? ($v['suffix'] ?? null),
                'situational' => ($raw['class'] ?? null) === 'colloquial_situational' || isset($expect['path_not']),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array{employee:?Employee,note:?string}
     */
    protected function resolveEmployee(array $case): array
    {
        if ($case['email']) {
            $employee = Employee::where('email', $case['email'])->first();

            return $employee
                ? ['employee' => $employee, 'note' => null]
                : ['employee' => null, 'note' => "employee {$case['email']} not found"];
        }

        if ($case['scope']) {
            $scope = $case['scope'];
            $convenio = Convenio::find($scope['convenio_id']);
            if ($convenio === null) {
                return ['employee' => null, 'note' => "convenio {$scope['convenio_id']} not found in this database — scope not found"];
            }

            $jobCategoryId = null;
            if ($scope['job_category']) {
                $jobCategoryId = ConvenioJobCategory::where('convenio_id', $convenio->id)
                    ->whereRaw('lower(name) = ?', [Str::lower((string) $scope['job_category'])])
                    ->value('id');
            }

            $groupId = null;
            if ($scope['group_label']) {
                $groupId = ConvenioGroup::approved()->where('convenio_id', $convenio->id)
                    ->whereRaw('lower(label) = ?', [Str::lower((string) $scope['group_label'])])
                    ->value('id');
            }

            $territoryId = $convenio->territory_id ?? Territory::query()->value('id');

            $email = 'test-answer-gate-'.substr(sha1(json_encode($scope)), 0, 16).'@example.com';
            $employee = Employee::firstOrCreate(
                ['email' => $email],
                [
                    'full_name' => 'Answer Gate Fixture',
                    'convenio_id' => $convenio->id,
                    'territory_id' => $territoryId,
                    'job_category_id' => $jobCategoryId,
                    'convenio_group_id' => $groupId,
                    'employment_type' => 'full_time',
                    'status' => 'active',
                    'start_date' => $scope['start_date'],
                ],
            );

            $note = 'as_of_date not honoured (no engine supports a caller-supplied date yet — roadmap.md §7)';
            if ($scope['job_category'] && $jobCategoryId === null) {
                $note .= "; job_category '{$scope['job_category']}' not found on this convenio";
            }
            if ($scope['group_label'] && $groupId === null) {
                $note .= "; group_label '{$scope['group_label']}' not found (approved) on this convenio";
            }

            return ['employee' => $employee, 'note' => $note];
        }

        return ['employee' => null, 'note' => 'case has neither email nor scope'];
    }
}
