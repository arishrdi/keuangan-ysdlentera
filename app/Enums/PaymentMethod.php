<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'tunai';
    case Transfer = 'transfer';
    case Qris = 'qris';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Transfer => 'Transfer bank',
            self::Qris => 'QRIS',
        };
    }

    public function channelType(): ?PaymentChannelType
    {
        return match ($this) {
            self::Cash => null,
            self::Transfer => PaymentChannelType::BankAccount,
            self::Qris => PaymentChannelType::Qris,
        };
    }
}
