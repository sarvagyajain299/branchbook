@if ($crud->hasAccess('revealContact', $entry))
    <form method="POST" action="{{ url($crud->route.'/'.$entry->getKey().'/reveal') }}" class="d-inline">
        @csrf
        <button class="btn btn-sm btn-link"><i class="la la-eye"></i> Reveal contact details</button>
    </form>
@endif
