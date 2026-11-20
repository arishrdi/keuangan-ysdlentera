<?php

use App\Actions\Reports\ReportRegistry;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Laporan')] class extends Component {}; ?>

<div>
    <x-page-header title="Laporan" description="Pilih laporan, atur filter, lalu tampilkan di layar, unduh Excel, atau cetak PDF." />

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach (ReportRegistry::availableTo(auth()->user()) as $report)
            <a href="{{ route('reports.show', $report::key()) }}" class="card border border-base-300 transition hover:border-primary hover:shadow" wire:navigate wire:key="report-{{ $report::key() }}">
                <div class="card-body">
                    <h2 class="card-title text-base">{{ $report::title() }}</h2>
                    <p class="text-sm text-base-content/70">{{ $report::description() }}</p>
                </div>
            </a>
        @endforeach
    </div>
</div>
