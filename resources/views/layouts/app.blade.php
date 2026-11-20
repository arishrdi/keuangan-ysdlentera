<x-layouts::app.sidebar :title="$title ?? null">
    <main class="mx-auto flex w-full max-w-7xl flex-1 flex-col px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        {{ $slot }}
    </main>
</x-layouts::app.sidebar>
