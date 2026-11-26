<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashTransactionProofController extends Controller
{
    /**
     * Show the receipt or invoice attached to an income or expense entry.
     */
    public function __invoke(CashTransaction $cashTransaction): StreamedResponse
    {
        Gate::authorize('view-finance');
        Gate::authorize('access-level', $cashTransaction->level_id);

        abort_if($cashTransaction->proof_path === null || ! Storage::disk('local')->exists($cashTransaction->proof_path), 404);

        return Storage::disk('local')->response($cashTransaction->proof_path);
    }
}
