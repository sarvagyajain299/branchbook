@extends(backpack_view('blank'))

@section('header')
    <section class="header-operation container-fluid animated fadeIn d-flex mb-2 align-items-baseline d-print-none">
        <h1 class="text-capitalize mb-0">Dashboard</h1>
        <p class="ms-2 ml-2 mb-0">Today in your branches</p>
    </section>
@endsection

@section('content')
    @php($rows = app(\App\Services\Admin\DashboardStats::class)->forUser(backpack_user()))
    <div class="card">
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <caption class="visually-hidden">Today's figures per branch</caption>
                <thead>
                    <tr>
                        <th scope="col">Branch</th>
                        <th scope="col" class="text-end">Bookings today</th>
                        <th scope="col" class="text-end">In queue now</th>
                        <th scope="col" class="text-end">Average wait today (min)</th>
                        <th scope="col" class="text-end">No-shows this week</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <th scope="row">{{ $row['name'] }} <span class="text-muted">({{ $row['branch'] }})</span></th>
                            <td class="text-end">{{ $row['bookings_today'] }}</td>
                            <td class="text-end">{{ $row['queue_length'] }}</td>
                            <td class="text-end">{{ $row['avg_wait_min'] ?? '–' }}</td>
                            <td class="text-end">{{ $row['no_shows_week'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">No branches.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="mt-3"><a href="{{ route('admin.reports') }}" class="btn btn-primary">Open reports and exports</a></p>
@endsection
