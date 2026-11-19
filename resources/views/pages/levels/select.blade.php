<x-layouts::auth title="Pilih Jenjang">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Pilih jenjang" description="Pilih jenjang pendidikan yang ingin Anda kelola" />

        <div class="grid gap-3">
            @foreach (\App\Models\Level::query()->ordered()->get() as $level)
                <form method="POST" action="{{ route('levels.switch') }}">
                    @csrf
                    <input type="hidden" name="level" value="{{ $level->id }}" />
                    <button type="submit" class="btn btn-lg btn-outline btn-primary w-full justify-start">
                        <x-icon name="building-office" />
                        {{ $level->name }}
                    </button>
                </form>
            @endforeach

            <div class="divider my-0 text-xs">atau</div>

            <form method="POST" action="{{ route('levels.switch') }}">
                @csrf
                <input type="hidden" name="level" value="{{ \App\Models\Level::ALL }}" />
                <button type="submit" class="btn btn-ghost w-full">Lihat semua jenjang</button>
            </form>
        </div>
    </div>
</x-layouts::auth>
