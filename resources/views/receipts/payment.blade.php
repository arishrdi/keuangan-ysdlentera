@php
    use App\Enums\PaymentStatus;
    use Illuminate\Support\Number;

    /** @var \App\Models\Payment $payment */
    $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/logo-print.png')));
    $classroom = $payment->student->enrollments()->with('classroom')->latest('id')->first()?->classroom;
    $isCancelled = $payment->status === PaymentStatus::Cancelled;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kuitansi {{ $payment->receipt_number }}</title>
    <style>
        @page { margin: 18px 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        .header { width: 100%; border-bottom: 2px solid #00a651; padding-bottom: 6px; }
        .header td { vertical-align: middle; }
        .title { font-size: 16px; font-weight: bold; color: #00a651; letter-spacing: 2px; text-align: right; }
        .muted { color: #6b7280; }
        .meta { width: 100%; margin-top: 8px; }
        .meta td { padding: 1px 0; vertical-align: top; }
        .items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .items th { background: #e8f6ee; text-align: left; padding: 4px 6px; border-bottom: 1px solid #00a651; }
        .items td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .right, .items th.right { text-align: right; }
        .total td { font-weight: bold; font-size: 11px; border-bottom: none; }
        .terbilang { margin-top: 6px; padding: 4px 6px; background: #fffbe0; border-left: 3px solid #f5d800; font-style: italic; }
        .footer { width: 100%; margin-top: 10px; }
        .watermark { position: fixed; top: 35%; left: 20%; font-size: 90px; color: rgba(220, 38, 38, 0.25); transform: rotate(-20deg); font-weight: bold; letter-spacing: 12px; }
    </style>
</head>
<body>
    @if ($isCancelled)
        <div class="watermark">BATAL</div>
    @endif

    <table class="header">
        <tr>
            <td style="width: 45%"><img src="{{ $logo }}" style="height: 48px"></td>
            <td class="right">
                <div class="title">KUITANSI</div>
                <div><strong>{{ $payment->receipt_number }}</strong></div>
                <div class="muted">{{ $payment->level->name }}{{ $payment->level->address ? ', '.$payment->level->address : '' }}{{ $payment->level->phone ? ', telp. '.$payment->level->phone : '' }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td style="width: 18%" class="muted">Telah terima dari</td>
            <td style="width: 42%">: <strong>{{ $payment->student->name }}</strong></td>
            <td style="width: 16%" class="muted">Tanggal bayar</td>
            <td>: {{ $payment->paid_on->translatedFormat('j F Y') }}</td>
        </tr>
        <tr>
            <td class="muted">NIS / Kelas</td>
            <td>: {{ $payment->student->nis ?? '-' }} / {{ $classroom?->name ?? '-' }} ({{ $payment->level->name }})</td>
            <td class="muted">Metode</td>
            <td>: {{ $payment->method->label() }}{{ $payment->channel ? ' ('.$payment->channel->name.')' : '' }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%">No</th>
                <th>Keterangan</th>
                <th style="width: 18%">Kategori</th>
                <th style="width: 22%" class="right">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payment->allocations as $allocation)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $allocation->bill->description }}</td>
                    <td>{{ $allocation->bill->paymentCategory->name }}</td>
                    <td class="right">{{ Number::rupiah($allocation->amount) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3" class="right">TOTAL</td>
                <td class="right">{{ Number::rupiah($payment->amount) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="terbilang">Terbilang: {{ ucfirst(Number::spell($payment->amount, 'id')) }} rupiah</div>

    <table class="footer">
        <tr>
            <td class="muted" style="width: 60%">
                @if ($isCancelled)
                    <strong style="color: #dc2626">Kuitansi ini telah DIBATALKAN</strong> pada {{ $payment->cancelled_at?->translatedFormat('j F Y H:i') }}.<br>
                    Alasan: {{ $payment->cancellation_reason }}<br>
                @endif
                Dicetak oleh sistem SIKEU Lentera pada {{ now()->translatedFormat('j F Y H:i') }}.<br>
                Kuitansi ini sah tanpa tanda tangan.
            </td>
            <td class="right">
                Diverifikasi oleh<br>
                <strong>{{ $payment->verifier?->name ?? '-' }}</strong><br>
                <span class="muted">Bendahara, {{ $payment->verified_at?->translatedFormat('j F Y H:i') }}</span>
            </td>
        </tr>
    </table>
</body>
</html>
