<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Employee;
use App\Models\Sector;
use App\Models\Territory;
use App\Services\Agent\PiiScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 13, build step 9 (plan.md §B.6.2) — `PiiScrubber` is the PRIMARY
 * scrub for the `general_knowledge` lane's question. hr-ai's own defence-in-
 * depth pattern re-check (`app/general_lane.py::refuse_if_pii`) is covered
 * separately by `scripts/general_lane_fetch_test.py`'s sibling script (the
 * pattern-level part only — email/DNI/NIE/NAF/IBAN/phone); THIS class also
 * knows who the employee IS (name, convenio, territory), which a pattern
 * alone never can.
 */
class PiiScrubberTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $territory = Territory::create(['code' => '20', 'name' => 'Gipuzkoa', 'level' => 'provincial', 'aliases' => ['Guipúzcoa']]);
        $sector = Sector::create(['name' => 'Hostelería', 'aliases' => []]);
        $convenio = Convenio::create([
            'numero' => '99-PII-0001',
            'name' => 'Convenio de Hostelería de Gipuzkoa',
            'aliases' => ['COEAS'],
            'territory_id' => $territory->id,
            'sector_id' => $sector->id,
        ]);
        $this->employee = Employee::create([
            'email' => 'ainhoa.etxeberria@example.com',
            'full_name' => 'Ainhoa Etxeberria Goikoetxea',
            'convenio_id' => $convenio->id,
            'territory_id' => $territory->id,
            'employment_type' => 'full_time',
            'status' => 'active',
        ]);
    }

    public function test_scrubs_the_employees_own_name(): void
    {
        $result = (new PiiScrubber)->scrub($this->employee, '¿Ainhoa Etxeberria tiene derecho a excedencia?');

        $this->assertStringNotContainsString('Ainhoa', $result['text']);
        $this->assertStringNotContainsString('Etxeberria', $result['text']);
        $this->assertContains('name', $result['kinds']);
    }

    public function test_scrubs_the_name_case_insensitively(): void
    {
        $result = (new PiiScrubber)->scrub($this->employee, 'Habla AINHOA etxeberria sobre su baja');

        $this->assertStringNotContainsString('AINHOA', $result['text']);
        $this->assertStringNotContainsString('etxeberria', $result['text']);
    }

    public function test_scrubs_email(): void
    {
        $result = (new PiiScrubber)->scrub($this->employee, 'Mi correo es persona.random@dominio-ajeno.com y tengo una duda');

        $this->assertStringNotContainsString('@dominio-ajeno.com', $result['text']);
        $this->assertContains('email', $result['kinds']);
    }

    public function test_scrubs_dni(): void
    {
        $result = (new PiiScrubber)->scrub($this->employee, 'Mi DNI es 12345678Z, ¿qué es una excedencia?');

        $this->assertStringNotContainsString('12345678Z', $result['text']);
        $this->assertContains('dni', $result['kinds']);
    }

    public function test_scrubs_nie(): void
    {
        $result = (new PiiScrubber)->scrub($this->employee, 'Mi NIE es X1234567L, quiero saber qué es la IT');

        $this->assertStringNotContainsString('X1234567L', $result['text']);
        $this->assertContains('nie', $result['kinds']);
    }

    public function test_scrubs_naf(): void
    {
        $result = (new PiiScrubber)->scrub($this->employee, 'Mi número de la Seguridad Social es 28/12345678/90');

        $this->assertStringNotContainsString('28/12345678/90', $result['text']);
        $this->assertContains('naf', $result['kinds']);
    }

    public function test_scrubs_iban(): void
    {
        $result = (new PiiScrubber)->scrub($this->employee, 'Mi cuenta es ES91 2100 0418 4502 0005 1332, qué es una excedencia');

        $this->assertStringNotContainsString('2100 0418 4502 0005 1332', $result['text']);
        $this->assertContains('iban', $result['kinds']);
    }

    public function test_scrubs_phone(): void
    {
        $result = (new PiiScrubber)->scrub($this->employee, 'Llámame al 612 345 678 si hace falta');

        $this->assertStringNotContainsString('612 345 678', $result['text']);
        $this->assertContains('phone', $result['kinds']);
    }

    public function test_scrubs_convenio_name_numero_and_alias(): void
    {
        $r1 = (new PiiScrubber)->scrub($this->employee, 'Según el Convenio de Hostelería de Gipuzkoa, ¿qué es una excedencia?');
        $this->assertStringNotContainsString('Hostelería de Gipuzkoa', $r1['text']);
        $this->assertContains('convenio', $r1['kinds']);

        $r2 = (new PiiScrubber)->scrub($this->employee, 'Mi convenio es el 99-PII-0001, ¿qué es la IT?');
        $this->assertStringNotContainsString('99-PII-0001', $r2['text']);

        $r3 = (new PiiScrubber)->scrub($this->employee, 'Estoy en el convenio COEAS, ¿qué significa excedencia?');
        $this->assertStringNotContainsString('COEAS', $r3['text']);
    }

    public function test_scrubs_territory_name_and_alias(): void
    {
        $r1 = (new PiiScrubber)->scrub($this->employee, 'Trabajo en Gipuzkoa, ¿qué es una excedencia?');
        $this->assertStringNotContainsString('Gipuzkoa', $r1['text']);
        $this->assertContains('territory', $r1['kinds']);

        $r2 = (new PiiScrubber)->scrub($this->employee, 'Trabajo en Guipúzcoa, ¿qué es la IT?');
        $this->assertStringNotContainsString('Guipúzcoa', $r2['text']);
    }

    public function test_scrubs_money_amounts(): void
    {
        $result = (new PiiScrubber)->scrub($this->employee, 'Cobro 1.234,56 € al mes, ¿qué es una excedencia?');

        $this->assertStringNotContainsString('1.234,56', $result['text']);
        $this->assertContains('money', $result['kinds']);
    }

    public function test_scrubs_dates(): void
    {
        $r1 = (new PiiScrubber)->scrub($this->employee, 'Empecé el 15/03/2020, ¿qué es una excedencia?');
        $this->assertStringNotContainsString('15/03/2020', $r1['text']);
        $this->assertContains('date', $r1['kinds']);

        $r2 = (new PiiScrubber)->scrub($this->employee, 'Empecé el 15 de marzo de 2020, ¿qué es la IT?');
        $this->assertStringNotContainsString('15 de marzo de 2020', $r2['text']);
    }

    public function test_a_clean_question_is_unchanged_and_reports_zero_hits(): void
    {
        $result = (new PiiScrubber)->scrub($this->employee, '¿Qué significa estar de excedencia voluntaria?');

        $this->assertSame('¿Qué significa estar de excedencia voluntaria?', $result['text']);
        $this->assertSame([], $result['kinds']);
        $this->assertSame(0, $result['count']);
    }

    public function test_multiple_pii_kinds_in_one_question_are_all_scrubbed(): void
    {
        $result = (new PiiScrubber)->scrub(
            $this->employee,
            'Soy Ainhoa Etxeberria, DNI 12345678Z, mi correo es x@y.com y cobro 1.500 € al mes en Gipuzkoa',
        );

        $this->assertStringNotContainsString('Ainhoa', $result['text']);
        $this->assertStringNotContainsString('12345678Z', $result['text']);
        $this->assertStringNotContainsString('x@y.com', $result['text']);
        $this->assertStringNotContainsString('1.500', $result['text']);
        $this->assertStringNotContainsString('Gipuzkoa', $result['text']);
        $this->assertGreaterThanOrEqual(5, $result['count']);
    }
}
