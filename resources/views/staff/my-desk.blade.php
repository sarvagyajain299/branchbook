<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ $advisor->desk_label }} · {{ $branch->name }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div role="status" class="rounded-md border border-teal-300 bg-teal-50 px-4 py-3 text-teal-900">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div role="alert" class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-red-900">{{ session('error') }}</div>
            @endif

            @if ($current)
                <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="current">
                    <h3 id="current" class="text-sm font-medium uppercase tracking-wide text-gray-500">{{ $current->status === App\Enums\TicketStatus::Called ? 'Called' : 'Now serving' }}</h3>
                    <p class="mt-1 text-5xl font-bold">{{ $current->display_code }}</p>
                    <p class="mt-1 text-gray-700">
                        {{ $current->service->name }}
                        @if ($current->appointment)
                            · {{ $current->appointment->customer->first_name }} {{ mb_substr($current->appointment->customer->last_name, 0, 1) }}.
                        @elseif ($current->walk_in_name)
                            · {{ $current->walk_in_name }}
                        @else
                            · walk-in
                        @endif
                    </p>

                    <div class="mt-5 flex flex-wrap gap-3">
                        @if ($current->status === App\Enums\TicketStatus::Called)
                            <form method="POST" action="{{ route('my-desk.start', [$branch, $current]) }}">@csrf
                                <button class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800">Start</button></form>
                            <form method="POST" action="{{ route('my-desk.recall', [$branch, $current]) }}">@csrf
                                <button class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium hover:bg-gray-50">Recall</button></form>
                            <form method="POST" action="{{ route('my-desk.no-show', [$branch, $current]) }}">@csrf
                                <button class="rounded-md border border-red-600 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">No-show</button></form>
                        @else
                            <form method="POST" action="{{ route('my-desk.complete', [$branch, $current]) }}">@csrf
                                <button class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800">Complete</button></form>
                        @endif
                    </div>
                </section>
            @else
                <section class="rounded-lg bg-white p-6 shadow-sm">
                    <p class="text-gray-700"><strong>{{ $waitingCount }}</strong> customer(s) waiting for your services.</p>
                    <form method="POST" action="{{ route('my-desk.call-next', $branch) }}" class="mt-4">
                        @csrf
                        <button class="rounded-md bg-teal-700 px-6 py-3 text-lg font-semibold text-white hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">Call next</button>
                    </form>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
