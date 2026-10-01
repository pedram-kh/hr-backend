<?php

namespace App\Services;

use App\Models\GuardrailCataloguePage;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 13c (plan.md §6) — the official-page catalogue the general-knowledge lane may fetch.
 *
 * READ side ({@see self::pages()}): the enabled rows of `guardrail_catalogue_pages`; when the table is absent or holds no
 * rows at all, the `config('hr.general_lane.sources')` array (the Sprint-13 behaviour, byte for byte). Only ever read by
 * the lane tool, so lane-off turns never touch it.
 *
 * WRITE-side guard ({@see self::urlViolation()}): the SAME allowlist the fetcher enforces — https only, no userinfo, no
 * port, no IP literal, host equal to or a subdomain of a `config('hr.general_lane.domains')` entry. The allowlist itself is
 * config/env (a deploy), never this table: a row can decide WHICH page of an already-trusted host the lane may read, never
 * widen the set of hosts. hr-ai's SSRF guard (`general_lane.fetch_source`) re-checks every fetch regardless.
 */
final class GeneralLaneCatalogue
{
    /** @return list<array{id:string,url:string,title:string,topics:list<string>}> */
    public static function pages(): array
    {
        $fallback = fn (): array => array_values(array_map(fn ($p) => [
            'id' => (string) $p['id'], 'url' => (string) $p['url'], 'title' => (string) $p['title'], 'topics' => array_values($p['topics'] ?? []),
        ], (array) config('hr.general_lane.sources', [])));

        if (! Schema::hasTable('guardrail_catalogue_pages') || GuardrailCataloguePage::query()->count() === 0) {
            return $fallback();
        }

        return GuardrailCataloguePage::query()->where('enabled', true)->orderBy('id')->get()
            ->map(fn (GuardrailCataloguePage $p) => [
                'id' => $p->slug, 'url' => $p->url, 'title' => $p->title, 'topics' => array_values($p->topics ?? []),
            ])->all();
    }

    /** @return list<string> */
    public static function domains(): array
    {
        return array_values((array) config('hr.general_lane.domains', []));
    }

    /** A human-readable reason the URL may not be catalogued, or null when it may. */
    public static function urlViolation(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'La dirección no es una URL válida.';
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return 'Solo se admiten direcciones https.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'La dirección no puede incluir credenciales.';
        }
        if (isset($parts['port'])) {
            return 'La dirección no puede indicar un puerto.';
        }
        $host = strtolower($parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return 'La dirección no puede ser una IP.';
        }
        foreach (self::domains() as $domain) {
            $domain = strtolower($domain);
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return null;
            }
        }

        return 'El dominio «'.$host.'» no está en la lista de dominios oficiales permitidos ('.implode(', ', self::domains()).').';
    }

    /** Normalized, de-duplicated topic terms (lower-case, trimmed). @param  list<mixed>  $topics  @return list<string> */
    public static function cleanTopics(array $topics): array
    {
        $out = [];
        foreach ($topics as $t) {
            $t = mb_strtolower(trim((string) $t), 'UTF-8');
            if ($t !== '' && mb_strlen($t) <= 80 && ! in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return $out;
    }
}
