<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentProofController extends Controller
{
    /**
     * Show the uploaded payment proof to staff and the student's guardians.
     */
    public function __invoke(Payment $payment): StreamedResponse
    {
        Gate::authorize('view-student-billing', $payment->student);

        abort_if($payment->proof_path === null || ! Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path);
    }
}
