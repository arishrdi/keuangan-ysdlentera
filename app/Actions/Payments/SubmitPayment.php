<?php

namespace App\Actions\Payments;

use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PaymentSubmitted;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Record a payment for one or more bills of a student.
 *
 * Guardians submit transfers or QRIS payments with proof, which wait for the treasurer's verification.
 * Payments recorded by the treasurer (cash at the counter or a confirmed transfer) are verified immediately.
 */
class SubmitPayment
{
    public function __construct(private VerifyPayment $verifyPayment) {}

    /**
     * @param  array<int, int>  $allocations  Amounts keyed by bill id.
     */
    public function handle(
        User $actor,
        Student $student,
        array $allocations,
        PaymentMethod $method,
        CarbonInterface $paidOn,
        ?PaymentChannel $channel = null,
        ?UploadedFile $proof = null,
        ?string $notes = null,
    ): Payment {
        $isTreasurer = $actor->hasRole(Role::Treasurer);

        $this->ensureMethodIsAllowed($actor, $student, $method, $channel, $proof, $isTreasurer);

        $payment = DB::transaction(function () use ($actor, $student, $allocations, $method, $paidOn, $channel, $proof, $notes): Payment {
            $allocations = array_filter($allocations, fn (int $amount) => $amount > 0);

            /** @var Collection<int, Bill> $bills */
            $bills = Bill::query()
                ->with('paymentCategory')
                ->withPendingAmount()
                ->whereKey(array_keys($allocations))
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->get();

            if ($allocations === [] || $bills->count() !== count($allocations)) {
                throw ValidationException::withMessages(['allocations' => 'Pilih minimal satu tagihan milik siswa ini.']);
            }

            foreach ($bills as $bill) {
                $this->ensureAmountIsValid($bill, $allocations[$bill->id]);
            }

            $payment = Payment::query()->create([
                'student_id' => $student->id,
                'level_id' => $student->level_id,
                'method' => $method,
                'payment_channel_id' => $channel?->id,
                'amount' => array_sum($allocations),
                'paid_on' => $paidOn->toDateString(),
                'proof_path' => $proof?->store('payment-proofs', 'local'),
                'notes' => $notes,
                'status' => PaymentStatus::Pending,
                'submitted_by' => $actor->id,
            ]);

            foreach ($allocations as $billId => $amount) {
                $payment->allocations()->create(['bill_id' => $billId, 'amount' => $amount]);
            }

            ActivityLog::record('payment.submitted', 'Mencatat pembayaran '.$method->label().' untuk '.$student->name, $payment, [
                'amount' => $payment->amount,
                'allocations' => $allocations,
            ]);

            return $payment;
        });

        if ($isTreasurer) {
            return $this->verifyPayment->handle($payment, $actor);
        }

        Notification::send(
            User::query()->where('role', Role::Treasurer)->where('is_active', true)->get(),
            new PaymentSubmitted($payment),
        );

        return $payment;
    }

    private function ensureMethodIsAllowed(User $actor, Student $student, PaymentMethod $method, ?PaymentChannel $channel, ?UploadedFile $proof, bool $isTreasurer): void
    {
        if (! $isTreasurer && ! ($actor->hasRole(Role::Guardian) && $student->isGuardedBy($actor))) {
            throw ValidationException::withMessages(['student' => 'Anda tidak berhak membayar tagihan siswa ini.']);
        }

        if ($method === PaymentMethod::Cash) {
            if (! $isTreasurer) {
                throw ValidationException::withMessages(['method' => 'Pembayaran tunai dicatat oleh bendahara di sekolah.']);
            }

            return;
        }

        if ($channel === null || $channel->level_id !== $student->level_id || $channel->type !== $method->channelType() || ! $channel->is_active) {
            throw ValidationException::withMessages(['channel' => 'Pilih rekening atau QRIS tujuan yang sesuai jenjang siswa.']);
        }

        if (! $isTreasurer && $proof === null) {
            throw ValidationException::withMessages(['proof' => 'Unggah bukti pembayaran.']);
        }
    }

    private function ensureAmountIsValid(Bill $bill, int $amount): void
    {
        $key = 'allocations.'.$bill->id;
        $payable = $bill->payableAmount();

        if ($bill->status === BillStatus::Cancelled || $payable <= 0) {
            throw ValidationException::withMessages([$key => 'Tagihan '.$bill->description.' sudah lunas atau sedang menunggu verifikasi.']);
        }

        if ($amount > $payable) {
            throw ValidationException::withMessages([$key => 'Nominal untuk '.$bill->description.' melebihi sisa tagihan.']);
        }

        if (! $bill->isInstallable() && $amount !== $payable) {
            throw ValidationException::withMessages([$key => 'Tagihan '.$bill->description.' harus dibayar lunas.']);
        }

        if ($amount < min(PaymentCategory::MINIMUM_INSTALLMENT, $payable)) {
            throw ValidationException::withMessages([$key => 'Cicilan minimal Rp10.000.']);
        }
    }
}
