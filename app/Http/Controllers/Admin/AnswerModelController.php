<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnswerModelSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The admin "Answer model" screen API (ADR-0015). super_admin only.
 *
 * The raw key is NEVER returned by any of these endpoints. It is set once,
 * encrypted at rest, shown MASKED (••••<last4>, reconstructed without decrypting),
 * rotatable (replace, never read back), and clearable. The browser never sees the
 * key and never calls the provider.
 */
class AnswerModelController extends Controller
{
    /** Status only — configured?, masked key, provider, when. NEVER the raw key. */
    public function status(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $settings = AnswerModelSetting::current();

        return response()->json([
            'configured' => $settings->isConfigured(),
            'masked_key' => $settings->maskedKey(),
            'provider' => $settings->provider ?? config('services.hr_ai.answer_provider'),
            'configured_at' => $settings->configured_at?->toIso8601String(),
        ]);
    }

    /** Set or rotate the key. Encrypts, stores last 4 for masking, never echoes it. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:8', 'max:512'],
        ]);

        $settings = AnswerModelSetting::current();
        $settings->setKey($data['api_key'], $request->user()->id);

        // Audit the action — the actor + time, NEVER the key value.
        Log::info('answer-model key set/rotated', [
            'admin_id' => $request->user()->id,
            'key_last_four' => $settings->key_last_four,
        ]);

        return response()->json([
            'configured' => true,
            'masked_key' => $settings->maskedKey(),
            'provider' => $settings->provider,
            'configured_at' => $settings->configured_at?->toIso8601String(),
        ]);
    }

    /** Remove the key (de-configure). */
    public function destroy(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $settings = AnswerModelSetting::current();
        $settings->clearKey($request->user()->id);

        Log::info('answer-model key cleared', ['admin_id' => $request->user()->id]);

        return response()->json(['configured' => false]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        $admin = $request->user();
        if (! method_exists($admin, 'hasRole') || ! $admin->hasRole('super_admin')) {
            abort(403, 'Only a super_admin can manage the answer model.');
        }
    }
}
