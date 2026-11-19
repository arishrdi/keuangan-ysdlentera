<?php

namespace App\Enums;

enum PaymentChannelType: string
{
    case BankAccount = 'rekening';
    case Qris = 'qris';

    public function label(): string
    {
        return match ($this) {
            self::BankAccount => 'Rekening bank',
            self::Qris => 'QRIS',
        };
    }

    public function paymentMethod(): PaymentMethod
    {
        return match ($this) {
            self::BankAccount => PaymentMethod::Transfer,
            self::Qris => PaymentMethod::Qris,
        };
    }
}
