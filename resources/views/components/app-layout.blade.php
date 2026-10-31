@props(['title'])

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title }} · OMS</title>

        @vite('resources/css/app.css')
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
        <a href="#main-content" class="sr-only rounded-lg bg-indigo-700 p-3 text-white focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50">
            Skip to content
        </a>

        <x-navigation />

        <main id="main-content" class="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
            {{ $slot }}
        </main>
    </body>
</html>
