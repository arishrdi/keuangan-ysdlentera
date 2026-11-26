<?php

namespace App\Actions\Billing;

use App\Models\Bill;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Build the WhatsApp reminder a guardian receives about a student's unpaid bills.
 *
 * The message opens in WhatsApp through a wa.me link; the admin presses send themselves.
 */
class PaymentReminder
{
    /**
     * @param  Collection<int, Bill>  $bills
     */
    public function message(User $guardian, Student $student, Collection $bills): string
    {
        $lines = $bills->map(fn (Bill $bill): string => '- '.$bill->description.': '.Number::rupiah($bill->remainingAmount())
            .($bill->due_date ? ' (jatuh tempo '.$bill->due_date->translatedFormat('j F Y').')' : ''));

        $classroom = $student->currentEnrollment?->classroom->name;

        return implode("\n", [
            "Assalamu'alaikum Bapak/Ibu {$guardian->name},",
            '',
            'Kami dari '.($student->level->name ?? config('app.name')).' mengingatkan tagihan ananda *'.$student->name.'*'.($classroom ? ' (kelas '.$classroom.')' : '').' yang belum lunas:',
            ...$lines->all(),
            '',
            '*Total: '.Number::rupiah($bills->sum(fn (Bill $bill): int => $bill->remainingAmount())).'*',
            '',
            'Pembayaran dapat dilakukan melalui aplikasi '.config('app.name').' ('.route('dashboard').') atau tunai di sekolah. Mohon abaikan pesan ini bila sudah membayar.',
            '',
            'Terima kasih. Wassalamu\'alaikum.',
        ]);
    }

    /**
     * The wa.me link that opens a chat with the guardian and the reminder already typed, or null without a phone number.
     *
     * @param  Collection<int, Bill>  $bills
     */
    public function whatsappUrl(User $guardian, Student $student, Collection $bills): ?string
    {
        $number = self::whatsappNumber($guardian->phone);

        if ($number === null) {
            return null;
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode($this->message($guardian, $student, $bills));
    }

    /**
     * Convert an Indonesian phone number to the international format WhatsApp expects (62xxx).
     */
    public static function whatsappNumber(?string $phone): ?string
    {
        $digits = User::normalizePhone($phone);

        if ($digits === null || strlen($digits) < 9) {
            return null;
        }

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }
}
