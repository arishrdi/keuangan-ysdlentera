<?php

use App\Actions\Billing\PaymentReminder;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Students with unpaid bills that are due, each guardian one click away from a prefilled WhatsApp reminder.
 */
new #[Title('Pengingat Tagihan')] class extends Component {
    use WithPagination;

    /**
     * Which bills to remind about: "lewat" (past due), "minggu" (due within 7 days) or "semua" (every unpaid bill).
     */
    #[Url(as: 'cakupan')]
    public string $scope = 'minggu';

    #[Url(as: 'kelas')]
    public ?int $classroomId = null;

    #[Url(as: 'cari')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('send-payment-reminders');

        if (! in_array($this->scope, ['lewat', 'minggu', 'semua'], true)) {
            $this->scope = 'minggu';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['scope', 'classroomId', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    /**
     * @return Collection<int, Classroom>
     */
    #[Computed]
    public function classrooms(): Collection
    {
        $academicYear = AcademicYear::current();

        if ($academicYear === null) {
            return new Collection;
        }

        return Classroom::query()
            ->with('level')
            ->whereBelongsTo($academicYear)
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy('grade')
            ->orderBy('name')
            ->get();
    }

    /**
     * Restrict a bill query to the unpaid bills the reminder covers.
     *
     * @param  Builder<\App\Models\Bill>  $query
     */
    private function dueBills(Builder $query): void
    {
        $query->outstanding()
            ->when($this->scope === 'lewat', fn ($query) => $query->whereDate('due_date', '<', today()))
            ->when($this->scope === 'minggu', fn ($query) => $query->whereDate('due_date', '<=', today()->addDays(7)));
    }

    /**
     * @return LengthAwarePaginator<int, Student>
     */
    #[Computed]
    public function students(): LengthAwarePaginator
    {
        return Student::query()
            ->active()
            ->whereHas('bills', fn ($query) => $this->dueBills($query))
            ->with([
                'level',
                'currentEnrollment.classroom',
                'guardians',
                'bills' => fn ($query) => $query->tap(fn ($query) => $this->dueBills($query))->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id'),
            ])
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->when($this->classroomId, fn ($query, int $classroomId) => $query->whereHas('currentEnrollment', fn ($query) => $query->where('classroom_id', $classroomId)))
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.trim($this->search).'%')
                ->orWhere('nis', 'like', '%'.trim($this->search).'%')
                ->orWhereHas('guardians', fn ($query) => $query->where('name', 'like', '%'.trim($this->search).'%'))))
            ->orderBy('name')
            ->paginate(25);
    }
}; ?>

<div>
    <x-page-header
        title="Pengingat Tagihan"
        :meta="[($this->activeLevel ? 'Jenjang '.$this->activeLevel->name : 'Semua jenjang')]"
        description="Kirim pengingat tagihan ke WhatsApp wali murid. Tombol Kirim WA membuka WhatsApp dengan pesan yang sudah terisi, tinggal tekan kirim."
    />

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <x-select wire:model.live="scope" label="Tagihan" class="select-sm">
            <option value="minggu">Jatuh tempo ≤ 7 hari lagi &amp; menunggak</option>
            <option value="lewat">Sudah lewat jatuh tempo</option>
            <option value="semua">Semua yang belum lunas</option>
        </x-select>
        <x-select wire:model.live="classroomId" label="Kelas" placeholder="Semua kelas" class="select-sm">
            @foreach ($this->classrooms as $classroom)
                <option value="{{ $classroom->id }}" wire:key="classroom-{{ $classroom->id }}">{{ $classroom->name }}{{ $this->activeLevel ? '' : ' ('.$classroom->level->code.')' }}</option>
            @endforeach
        </x-select>
        <label class="input input-sm w-64">
            <x-icon name="magnifying-glass" class="size-4 opacity-60" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari siswa / NIS / wali" aria-label="Cari" />
        </label>
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100">
        <table class="table">
            <thead>
                <tr>
                    <th>Siswa</th>
                    <th>Tagihan</th>
                    <th class="text-end">Total</th>
                    <th>Wali murid</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->students as $student)
                    @php
                        $bills = $student->bills;
                        $reminder = app(PaymentReminder::class);
                    @endphp
                    <tr wire:key="student-{{ $student->id }}" class="align-top">
                        <td>
                            <div class="font-medium">{{ $student->name }}</div>
                            <div class="text-sm text-base-content/60">{{ $student->level->code }}, kelas {{ $student->currentEnrollment?->classroom->name ?? '-' }}</div>
                        </td>
                        <td class="text-sm">
                            <ul class="space-y-0.5">
                                @foreach ($bills as $bill)
                                    <li wire:key="bill-{{ $bill->id }}">
                                        {{ $bill->description }}
                                        @if ($bill->due_date)
                                            <span @class(['text-xs', 'font-medium text-error' => $bill->isOverdue(), 'text-base-content/60' => ! $bill->isOverdue()])>· {{ $bill->due_date->translatedFormat('j M Y') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($bills->sum(fn ($bill) => $bill->remainingAmount())) }}</td>
                        <td>
                            <div class="flex flex-col gap-1.5">
                                @forelse ($student->guardians as $guardian)
                                    @php $url = $reminder->whatsappUrl($guardian, $student, $bills); @endphp
                                    <div wire:key="guardian-{{ $student->id }}-{{ $guardian->id }}" class="flex items-center justify-between gap-3">
                                        <span class="text-sm">
                                            {{ $guardian->name }}
                                            <span class="block text-xs text-base-content/60">{{ $guardian->phone ?? 'Belum ada no. HP' }}</span>
                                        </span>
                                        @if ($url)
                                            <a href="{{ $url }}" target="_blank" rel="noopener" class="btn btn-xs btn-success">Kirim WA</a>
                                        @else
                                            <span class="badge badge-warning badge-sm">Tanpa no. HP</span>
                                        @endif
                                    </div>
                                @empty
                                    <span class="badge badge-ghost badge-sm">Belum ada akun wali</span>
                                @endforelse
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-8 text-center text-base-content/60">Tidak ada tagihan yang perlu diingatkan.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->students->links() }}</div>
</div>
