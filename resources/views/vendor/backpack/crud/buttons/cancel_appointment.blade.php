@if ($crud->hasAccess('cancelAppointment', $entry) && in_array($entry->status->value, ['held', 'confirmed', 'checked_in'], true))
    <form method="POST" action="{{ url($crud->route.'/'.$entry->getKey().'/cancel') }}" class="d-inline" onsubmit="return confirm('Cancel this appointment?');">
        @csrf
        <button class="btn btn-sm btn-link text-danger"><i class="la la-ban"></i> Cancel</button>
    </form>
@endif
