<?php

namespace App\Http\Controllers;

use App\Models\PaymentChannel;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentChannelQrisController extends Controller
{
    /**
     * Show the QRIS image of a payment channel to logged-in users.
     */
    public function __invoke(PaymentChannel $paymentChannel): StreamedResponse
    {
        abort_if($paymentChannel->qris_image_path === null || ! Storage::disk('local')->exists($paymentChannel->qris_image_path), 404);

        return Storage::disk('local')->response($paymentChannel->qris_image_path);
    }
}
