@props(['step' => null, 'title' => null])
@php
    $steps = ['Service', 'Branch', 'Time', 'Details', 'Verify', 'Done'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:start-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">Skip to content</a>

    <header class="bg-teal-800 text-white">
        <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-4">
            <a href="{{ route('book.services') }}" class="text-lg font-semibold tracking-tight focus:outline-none focus-visible:ring-2 focus-visible:ring-white">Northwind Bank <span class="font-normal opacity-80">· Book an appointment</span></a>
        </div>
    </header>

    <main id="main" class="mx-auto max-w-4xl px-4 py-8">
        @if ($step)
            <ol class="mb-8 flex flex-wrap gap-x-4 gap-y-1 text-sm" aria-label="Booking progress">
                @foreach ($steps as $i => $label)
                    <li @if ($i + 1 === $step) aria-current="step" @endif
                        class="{{ $i + 1 === $step ? 'font-semibold text-teal-800' : ($i + 1 < $step ? 'text-slate-600' : 'text-slate-400') }}">
                        {{ $i + 1 }}. {{ $label }}
                    </li>
                @endforeach
            </ol>
        @endif

        @if (session('status'))
            <div role="status" class="mb-6 rounded-md border border-teal-300 bg-teal-50 px-4 py-3 text-teal-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div role="alert" class="mb-6 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-red-900">{{ session('error') }}</div>
        @endif

        {{ $slot }}
    </main>

    <footer class="mx-auto max-w-4xl px-4 pb-10 text-sm text-slate-500">
        Northwind Bank is a fictional bank used for a software portfolio project.
        We will never ask you for card numbers, account numbers or your Emirates ID number on this site.
    </footer>
</body>
</html>
