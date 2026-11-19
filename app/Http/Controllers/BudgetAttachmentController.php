<?php

namespace App\Http\Controllers;

use App\Models\BudgetAttachment;
use App\Models\BudgetProposal;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BudgetAttachmentController extends Controller
{
    /**
     * Download a supporting document of a budget proposal.
     */
    public function __invoke(BudgetProposal $budgetProposal, BudgetAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view-finance');

        abort_unless($attachment->budget_proposal_id === $budgetProposal->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }
}
