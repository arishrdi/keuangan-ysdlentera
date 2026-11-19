<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Notifications\Notification;

class PaymentRejected extends Notification
{
    public function __construct(public Payment $payment) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Pembayaran ditolak',
            'message' => 'Pembayaran '.$this->payment->student->name.' sebesar Rp'.number_format($this->payment->amount, 0, ',', '.').' ditolak: '.$this->payment->rejection_reason,
            'url' => route('guardian.payments'),
        ];
    }
}
