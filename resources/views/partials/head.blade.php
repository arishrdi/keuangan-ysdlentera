<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    window.applyAppearance = function (appearance = localStorage.getItem('appearance') || 'system') {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = appearance === 'system' ? (prefersDark ? 'dark' : 'light') : appearance;

        document.documentElement.setAttribute('data-theme', theme);
    };

    window.applyAppearance();

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => window.applyAppearance());
    document.addEventListener('livewire:navigated', () => window.applyAppearance());
</script>
