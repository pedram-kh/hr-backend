<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Employee;
use App\Services\IdentityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Employee|Admin $account */
        $account = $request->user();
        $accountType = $account instanceof Admin ? 'admin' : 'employee';

        return response()->json([
            'identity' => IdentityPresenter::present($account, $accountType),
        ]);
    }
}
