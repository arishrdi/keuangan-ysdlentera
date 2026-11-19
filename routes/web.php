<?php

use App\Http\Controllers\ActiveLevelController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('role:staff')->group(function () {
        Route::view('pilih-jenjang', 'pages.levels.select')->name('levels.select');
        Route::post('jenjang-aktif', ActiveLevelController::class)->name('levels.switch');
    });

    Route::middleware('level.selected')->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        Route::middleware('role:admin,yayasan')->group(function () {
            Route::livewire('siswa', 'pages::students.index')->name('students.index');
            Route::livewire('siswa/{student}', 'pages::students.show')->name('students.show');
            Route::livewire('riwayat-aktivitas', 'pages::activity-logs.index')->name('activity-logs.index');
        });

        Route::middleware('role:admin')->prefix('admin')->group(function () {
            Route::livewire('pengguna', 'pages::admin.users')->name('admin.users');
            Route::livewire('tahun-ajaran', 'pages::admin.academic-years')->name('admin.academic-years');
            Route::livewire('kelas', 'pages::admin.classrooms')->name('admin.classrooms');
            Route::livewire('wali-murid', 'pages::admin.guardians')->name('admin.guardians');
            Route::livewire('kenaikan-kelas', 'pages::admin.promotions')->name('admin.promotions');
        });
    });
});

require __DIR__.'/settings.php';
