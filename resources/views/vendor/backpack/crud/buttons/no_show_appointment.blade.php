@if ($crud->hasAccess('noShowAppointment', $entry) && in_array($entry->status->value, ['confirmed', 'checked_in'], true))
    <form method="POST" action="{{ url($crud->route.'/'.$entry->getKey().'/no-show') }}" class="d-inline">
        @csrf
        <button class="btn btn-sm btn-link"><i class="la la-user-slash"></i> No-show</button>
    </form>
@endif
