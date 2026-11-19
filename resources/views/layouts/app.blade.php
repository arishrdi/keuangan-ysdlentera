<x-layouts::app.sidebar :title="$title ?? null">
    <main class="flex flex-1 flex-col p-6 lg:p-8">
        {{ $slot }}
    </main>
</x-layouts::app.sidebar>
