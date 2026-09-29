<?php

namespace App\Models;

use App\Services\Answer\AnswerEngineDispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Sprint 13, build step 2 (plan.md §E.15 step 2, §F.14) — the single-row
 * runtime override for which answer engine (`classic`|`agent`) serves a
 * turn, mirroring {@see AnswerModelSetting}'s single-row shape (ADR-0015).
 *
 * `engine === null` means "no override" — the effective engine is then the
 * `HR_ANSWER_ENGINE` env baseline (default `classic`), read by
 * {@see AnswerEngineDispatcher::effectiveEngine()}.
 */
class AnswerEngineSetting extends Model
{
    public const CLASSIC = 'classic';

    public const AGENT = 'agent';

    /** @var list<string> */
    public const VALID_ENGINES = [self::CLASSIC, self::AGENT];

    protected $fillable = ['engine', 'updated_by'];

    /**
     * The single settings row (id = 1), created on first access.
     *
     * NOT `firstOrCreate(['id' => 1])`: `create()` fills via mass assignment,
     * and `id` is deliberately not in `$fillable`, so that call silently
     * drops the id and lands on whatever `nextval()` gives — reliably 1 only
     * on a truly fresh table. Postgres sequences are not rolled back between
     * tests (`RefreshDatabase` only rolls back the transaction), so relying
     * on that coincidence is flaky beyond the first test to touch this table
     * in a process — the exact trap {@see AnswerModelSetting::current()}
     * documents and works around ad hoc in `Sprint6GuardrailInvariantTest`.
     * Setting `id` directly (not via `fill()`) bypasses the guard instead.
     */
    public static function current(): self
    {
        return static::find(1) ?? tap(new static, function (self $model) {
            $model->id = 1;
            $model->save();
        });
    }

    /**
     * Set (or clear, with `$engine = null`) the runtime override. Always
     * audited via `updated_by`, exactly like {@see AnswerModelSetting::setKey()}.
     */
    public function setEngine(?string $engine, ?int $adminId = null): void
    {
        $this->engine = $engine;
        $this->updated_by = $adminId;
        $this->save();
    }
}
