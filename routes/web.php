<?php

use App\Http\Controllers\ActiveLevelController;
use App\Http\Controllers\BudgetAttachmentController;
use App\Http\Controllers\CashTransactionProofController;
use App\Http\Controllers\PaymentChannelQrisController;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\ReportExportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('role:staff')->group(function () {
        Route::view('pilih-jenjang', 'pages.levels.select')->name('levels.select');
        Route::post('jenjang-aktif', ActiveLevelController::class)->name('levels.switch');
    });

    Route::get('kuitansi/{payment}', PaymentReceiptController::class)->name('payments.receipt');
    Route::get('pembayaran/{payment}/bukti', PaymentProofController::class)->name('payments.proof');
    Route::get('rekening/{paymentChannel}/qris', PaymentChannelQrisController::class)->name('payment-channels.qris');

    Route::middleware('level.selected')->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        Route::middleware('role:admin,yayasan')->group(function () {
            Route::livewire('siswa', 'pages::students.index')->name('students.index');
            Route::livewire('siswa/{student}', 'pages::students.show')->name('students.show');
            Route::livewire('riwayat-aktivitas', 'pages::activity-logs.index')->name('activity-logs.index');
        });

        Route::middleware('role:bendahara,kepala_sekolah,yayasan')->group(function () {
            Route::livewire('tagihan', 'pages::billing.index')->name('billing.index');
            Route::livewire('tagihan/siswa/{student}', 'pages::billing.student')->name('billing.student');
            Route::livewire('pembayaran', 'pages::payments.index')->name('payments.index');
            Route::livewire('pemasukan', 'pages::finance.incomes')->name('finance.incomes');
            Route::livewire('kas', 'pages::finance.cash')->name('finance.cash');
            Route::get('kas/{cashTransaction}/bukti', CashTransactionProofController::class)->name('finance.proof');
            Route::livewire('laporan', 'pages::reports.index')->name('reports.index');
            Route::livewire('laporan/{report}', 'pages::reports.show')->name('reports.show');
            Route::get('laporan/{report}/{format}', ReportExportController::class)->whereIn('format', ['excel', 'pdf'])->name('reports.export');
            Route::livewire('anggaran', 'pages::budgets.index')->name('budgets.index');
            Route::livewire('anggaran/{budgetProposal}', 'pages::budgets.show')->whereNumber('budgetProposal')->name('budgets.show');
            Route::get('anggaran/{budgetProposal}/lampiran/{attachment}', BudgetAttachmentController::class)->name('budgets.attachment');
        });

        Route::middleware('role:yayasan')->group(function () {
            Route::livewire('pengeluaran', 'pages::finance.expenses')->name('finance.expenses');
        });

        Route::middleware('role:kepala_sekolah,yayasan')->group(function () {
            Route::livewire('tabungan', 'pages::savings.index')->name('savings.index');
            Route::livewire('tabungan/siswa/{student}', 'pages::savings.student')->name('savings.student');
        });

        Route::middleware('role:teller')->group(function () {
            Route::livewire('kasir', 'pages::teller.index')->name('teller.index');
        });

        Route::middleware('role:bendahara')->group(function () {
            Route::livewire('tagihan/terbitkan', 'pages::billing.issue')->name('billing.issue');
            Route::livewire('anggaran/buat', 'pages::budgets.form')->name('budgets.create');
            Route::livewire('anggaran/{budgetProposal}/ubah', 'pages::budgets.form')->name('budgets.edit');
        });

        Route::middleware('role:wali_murid')->prefix('wali')->group(function () {
            Route::livewire('tagihan', 'pages::guardian.bills')->name('guardian.bills');
            Route::livewire('cicilan', 'pages::guardian.installments')->name('guardian.installments');
            Route::livewire('riwayat', 'pages::guardian.payments')->name('guardian.payments');
            Route::livewire('tabungan', 'pages::guardian.savings')->name('guardian.savings');
        });

        Route::middleware('role:admin')->prefix('admin')->group(function () {
            Route::livewire('pengguna', 'pages::admin.users')->name('admin.users');
            Route::livewire('pengingat-tagihan', 'pages::admin.payment-reminders')->name('admin.payment-reminders');
            Route::livewire('jenjang', 'pages::admin.levels')->name('admin.levels');
            Route::livewire('import-siswa', 'pages::admin.student-import')->name('admin.student-import');
            Route::livewire('tahun-ajaran', 'pages::admin.academic-years')->name('admin.academic-years');
            Route::livewire('kelas', 'pages::admin.classrooms')->name('admin.classrooms');
            Route::livewire('wali-murid', 'pages::admin.guardians')->name('admin.guardians');
            Route::livewire('kenaikan-kelas', 'pages::admin.promotions')->name('admin.promotions');
            Route::livewire('kategori-tarif', 'pages::admin.fee-rates')->name('admin.fee-rates');
            Route::livewire('seragam', 'pages::admin.uniform-items')->name('admin.uniform-items');
            Route::livewire('rekening', 'pages::admin.payment-channels')->name('admin.payment-channels');
            Route::livewire('akun-kas', 'pages::admin.cash-accounts')->name('admin.cash-accounts');
            Route::livewire('kategori-transaksi', 'pages::admin.transaction-categories')->name('admin.transaction-categories');
        });
    });
});

require __DIR__.'/settings.php';
