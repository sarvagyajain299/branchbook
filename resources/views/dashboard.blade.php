<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Dashboard</h2>
    </x-slot>

    @php
        $user = auth()->user();
        $branches = $user->isHq() ? App\Models\Branch::query()->orderBy('name')->get() : App\Models\Branch::query()->whereKey($user->branch_id)->get();
    @endphp

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            <p class="text-gray-700">Signed in as <strong>{{ $user->name }}</strong> ({{ $user->role->label() }}).</p>

            @can('view-reports')
                <p><a class="inline-block rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800" href="{{ route('admin.reports') }}">Reports and exports</a></p>
            @endcan

            <ul class="grid gap-4 sm:grid-cols-2">
                @foreach ($branches as $branch)
                    <li class="rounded-lg bg-white p-5 shadow-sm">
                        <h3 class="font-semibold">{{ $branch->name }}</h3>
                        <ul class="mt-3 space-y-1 text-sm">
                            @can('issue', [App\Models\QueueTicket::class, $branch->id])
                                <li><a class="text-teal-800 underline" href="{{ route('desk', $branch) }}">Front desk</a></li>
                            @endcan
                            @can('callNext', [App\Models\QueueTicket::class, $branch->id])
                                <li><a class="text-teal-800 underline" href="{{ route('my-desk', $branch) }}">My desk</a></li>
                            @endcan
                            <li><a class="text-teal-800 underline" href="{{ route('display', $branch) }}" target="_blank" rel="noopener">Waiting-room display</a></li>
                        </ul>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-app-layout>
