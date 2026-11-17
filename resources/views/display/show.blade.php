<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Queue · {{ $branch->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-yellow-300 antialiased">
    <main id="display" data-url="{{ route('display.state', $branch) }}" class="mx-auto flex min-h-screen max-w-7xl flex-col p-8">
        <header class="flex items-center justify-between">
            <h1 class="text-3xl font-semibold text-white">{{ $branch->name }}</h1>
            <button type="button" data-sound class="rounded border border-yellow-300 px-3 py-1 text-sm text-yellow-300 hover:bg-yellow-300 hover:text-black">Turn sound on</button>
        </header>

        <section class="mt-8 grid flex-1 gap-8 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <h2 class="text-2xl uppercase tracking-widest text-white">Now serving</h2>
                <ul data-serving aria-live="polite" class="mt-4 space-y-4"></ul>
                <p data-empty class="mt-6 text-3xl text-gray-400">Please wait to be called.</p>
            </div>
            <div>
                <h2 class="text-2xl uppercase tracking-widest text-white">Next</h2>
                <ul data-next class="mt-4 space-y-2 text-4xl font-semibold"></ul>
            </div>
        </section>
    </main>
</body>
</html>
