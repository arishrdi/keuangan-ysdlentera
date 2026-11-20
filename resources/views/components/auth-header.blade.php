@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-1">
    <h1 class="text-2xl font-bold tracking-tight">{{ $title }}</h1>
    <p class="text-sm text-base-content/65">{{ $description }}</p>
</div>
