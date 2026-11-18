{{-- Sidebar for the admin panel. HQ sees everything; branch managers only what they may manage. --}}
@php($isHq = backpack_user()->isHq())
<li class="nav-item"><a class="nav-link" href="{{ backpack_url('dashboard') }}"><i class="la la-home nav-icon"></i> Dashboard</a></li>

<x-backpack::menu-dropdown title="Branches" icon="la la-building">
    <x-backpack::menu-dropdown-item title="Branches" icon="la la-building" :link="backpack_url('branch')" />
    <x-backpack::menu-dropdown-item title="Opening hours" icon="la la-clock" :link="backpack_url('branch-hour')" />
    <x-backpack::menu-dropdown-item title="Closures and holidays" icon="la la-calendar-times" :link="backpack_url('closure')" />
</x-backpack::menu-dropdown>

<x-backpack::menu-dropdown title="Team" icon="la la-user-tie">
    <x-backpack::menu-dropdown-item title="Advisors" icon="la la-user-tie" :link="backpack_url('advisor')" />
    <x-backpack::menu-dropdown-item title="Weekly shifts" icon="la la-business-time" :link="backpack_url('advisor-shift')" />
    <x-backpack::menu-dropdown-item title="Time off" icon="la la-umbrella-beach" :link="backpack_url('advisor-time-off')" />
</x-backpack::menu-dropdown>

<x-backpack::menu-item title="Services" icon="la la-concierge-bell" :link="backpack_url('service')" />
<x-backpack::menu-item title="Appointments" icon="la la-calendar-check" :link="backpack_url('appointment')" />
<x-backpack::menu-item title="Customers" icon="la la-address-book" :link="backpack_url('customer')" />
<x-backpack::menu-item title="Reports" icon="la la-chart-bar" :link="route('admin.reports')" />

@if ($isHq)
    <x-backpack::menu-item title="Staff users" icon="la la-users" :link="backpack_url('user')" />
    <x-backpack::menu-item title="Audit log" icon="la la-history" :link="backpack_url('audit-log')" />
@endif
