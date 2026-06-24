<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\SandboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Test a question against this document" (Sprint 3, spec C). Read-only reuse of
 * the answer pipeline scoped to ONE document; persists nothing (no chat rows, no
 * escalation). Open to any admin (it is read-only) — not gated by knowledge.edit.
 */
class SandboxController extends Controller
{
    public function run(Request $request, string $uuid, SandboxService $sandbox): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        $document = Document::where('uuid', $uuid)->firstOrFail();

        return response()->json($sandbox->run($document, $data['question']));
    }
}
