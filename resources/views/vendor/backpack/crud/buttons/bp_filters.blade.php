{{--
    List filters, as a plain GET form. (Backpack's own filter widgets are part of its paid PRO package,
    so this admin panel does not use them.) Submitting reloads the list with the values in the URL;
    the controller's filter logic runs from those parameters, and the table's AJAX search carries
    the same query string. Types: dropdown, simple (checkbox), text, date_range.
--}}
@php($filters = $crud->getOperationSetting('bpFilters') ?? [])
@if (count($filters))
    <form method="GET" action="{{ url()->current() }}" id="bp-filter-form" class="d-flex flex-wrap gap-2 align-items-end w-100 mb-2" role="search" aria-label="Filter the list">
        @foreach ($filters as $filter)
            @php($id = 'filter-'.$filter['name'])
            @switch($filter['type'])
                @case('dropdown')
                    @php($options = is_callable($filter['values']) ? ($filter['values'])() : $filter['values'])
                    <div>
                        <label class="form-label small mb-0" for="{{ $id }}">{{ $filter['label'] }}</label>
                        <select class="form-select form-select-sm" id="{{ $id }}" name="{{ $filter['name'] }}">
                            <option value="">All</option>
                            @foreach ($options as $key => $label)
                                <option value="{{ $key }}" @selected((string) request($filter['name']) === (string) $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    @break

                @case('simple')
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" value="1" id="{{ $id }}" name="{{ $filter['name'] }}" @checked(request()->has($filter['name']))>
                        <label class="form-check-label small" for="{{ $id }}">{{ $filter['label'] }}</label>
                    </div>
                    @break

                @case('text')
                    <div>
                        <label class="form-label small mb-0" for="{{ $id }}">{{ $filter['label'] }}</label>
                        <input class="form-control form-control-sm" type="text" id="{{ $id }}" name="{{ $filter['name'] }}" value="{{ request($filter['name']) }}">
                    </div>
                    @break

                @case('date_range')
                    @php($range = json_decode((string) request($filter['name'])))
                    <div>
                        <label class="form-label small mb-0" for="{{ $id }}-from">{{ $filter['label'] }}: from</label>
                        <input class="form-control form-control-sm" type="date" id="{{ $id }}-from" data-range-from="{{ $filter['name'] }}" value="{{ $range->from ?? '' }}">
                    </div>
                    <div>
                        <label class="form-label small mb-0" for="{{ $id }}-to">to</label>
                        <input class="form-control form-control-sm" type="date" id="{{ $id }}-to" data-range-to="{{ $filter['name'] }}" value="{{ $range->to ?? '' }}">
                    </div>
                    {{-- Filled in on submit: the controller expects {"from": ..., "to": ...} in ONE parameter. --}}
                    <input type="hidden" name="{{ $filter['name'] }}" data-range-value="{{ $filter['name'] }}" disabled>
                    @break
            @endswitch
        @endforeach

        <div>
            <button class="btn btn-sm btn-primary">Apply filters</button>
            <a class="btn btn-sm btn-link" href="{{ url()->current() }}">Clear</a>
        </div>
    </form>

    <script>
        document.getElementById('bp-filter-form').addEventListener('submit', function (event) {
            this.querySelectorAll('[data-range-value]').forEach(function (hidden) {
                var name = hidden.getAttribute('data-range-value');
                var from = event.target.querySelector('[data-range-from="' + name + '"]').value;
                var to = event.target.querySelector('[data-range-to="' + name + '"]').value;

                hidden.disabled = !(from && to);
                hidden.value = from && to ? JSON.stringify({from: from, to: to}) : '';
            });
        });
    </script>
@endif
