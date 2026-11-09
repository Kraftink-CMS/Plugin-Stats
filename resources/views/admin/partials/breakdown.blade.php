<section class="card h-100 mb-0">
    <div class="card-header">
        <h3 class="card-title">{{ $title }}</h3>
    </div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">{{ $label }}</th>
                    <th scope="col" class="text-end">{{ __('stats::messages.views') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <th scope="row" class="fw-normal text-break">{{ $name($item) }}</th>
                        <td class="text-end text-nowrap">{{ number_format($item->views, 0, '.', ' ') }}</td>
                    </tr>
                @empty
                    <x-tables.empty :colspan="2" :message="__('stats::messages.no_breakdown')" />
                @endforelse
            </tbody>
        </table>
    </div>
</section>
