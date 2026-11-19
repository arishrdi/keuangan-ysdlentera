<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PaymentReceiptController extends Controller
{
    /**
     * Stream the A5 landscape receipt (kuitansi) of a verified or cancelled payment.
     */
    public function __invoke(Payment $payment): Response
    {
        Gate::authorize('view-student-billing', $payment->student);

        abort_unless($payment->hasReceipt(), 404);

        $payment->load(['student', 'level', 'channel', 'verifier', 'allocations.bill.paymentCategory']);

        return Pdf::loadView('receipts.payment', ['payment' => $payment])
            ->setPaper('a5', 'landscape')
            ->setOption('enable_font_subsetting', true)
            ->stream(Str::slug($payment->receipt_number).'.pdf');
    }
}
