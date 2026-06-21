<?php

namespace App\Support;

use App\Models\Convenio;
use App\Models\Sector;
use App\Models\Territory;

/**
 * Resolves free-text spellings to controlled-vocabulary FKs by matching the
 * normalized value against each row's canonical `name` and its `aliases`.
 * NEVER creates rows — a miss returns null (the caller decides: conflict /
 * unresolved). This is the read side of the closed-vocabulary guarantee
 * (ADR-0002).
 */
class VocabularyResolver
{
    /** @var array<string,Territory>|null */
    private ?array $territoryByKey = null;

    /** @var array<string,Territory>|null */
    private ?array $territoryByCode = null;

    /** @var array<string,Sector>|null */
    private ?array $sectorByKey = null;

    /** @var array<string,Convenio>|null */
    private ?array $convenioByKey = null;

    public function territoryByCode(?string $code): ?Territory
    {
        if ($code === null || $code === '') {
            return null;
        }
        $this->loadTerritories();

        return $this->territoryByCode[$code] ?? null;
    }

    public function territoryByName(?string $name): ?Territory
    {
        $key = TextNormalizer::key($name);
        if ($key === '') {
            return null;
        }
        $this->loadTerritories();

        return $this->territoryByKey[$key] ?? null;
    }

    public function sectorByName(?string $name): ?Sector
    {
        $key = TextNormalizer::key($name);
        if ($key === '') {
            return null;
        }
        $this->loadSectors();

        return $this->sectorByKey[$key] ?? null;
    }

    /**
     * Resolve a convenio by name, matching against its canonical `name` AND its
     * `aliases` (folded duplicate-numero spellings), so either the formal or the
     * colloquial title resolves to the one convenio. Read-only; never creates.
     */
    public function convenioByName(?string $name): ?Convenio
    {
        $key = TextNormalizer::key($name);
        if ($key === '') {
            return null;
        }
        $this->loadConvenios();

        return $this->convenioByKey[$key] ?? null;
    }

    private function loadConvenios(): void
    {
        if ($this->convenioByKey !== null) {
            return;
        }
        $this->convenioByKey = [];
        foreach (Convenio::all() as $c) {
            $this->convenioByKey[TextNormalizer::key($c->name)] = $c;
            foreach ((array) $c->aliases as $alias) {
                $this->convenioByKey[TextNormalizer::key($alias)] = $c;
            }
        }
    }

    private function loadTerritories(): void
    {
        if ($this->territoryByKey !== null) {
            return;
        }
        $this->territoryByKey = [];
        $this->territoryByCode = [];
        foreach (Territory::all() as $t) {
            if ($t->code !== null && $t->code !== '') {
                $this->territoryByCode[$t->code] = $t;
            }
            $this->territoryByKey[TextNormalizer::key($t->name)] = $t;
            foreach ((array) $t->aliases as $alias) {
                $this->territoryByKey[TextNormalizer::key($alias)] = $t;
            }
        }
    }

    private function loadSectors(): void
    {
        if ($this->sectorByKey !== null) {
            return;
        }
        $this->sectorByKey = [];
        foreach (Sector::all() as $s) {
            $this->sectorByKey[TextNormalizer::key($s->name)] = $s;
            foreach ((array) $s->aliases as $alias) {
                $this->sectorByKey[TextNormalizer::key($alias)] = $s;
            }
        }
    }
}
