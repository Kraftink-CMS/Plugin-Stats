@extends('admin.layouts.layout')

@section('title', __('stats::messages.title'))
@section('description', __('stats::messages.description'))

@section('actions')
    <button type="submit" form="stats-filter" name="export" value="csv" class="btn btn-outline-primary">
        <i class="bi bi-download me-1" aria-hidden="true"></i>{{ __('stats::messages.export') }}
    </button>
@endsection

@section('content')
    @php
        $browserName = function (string $client): string {
            [$browser, $version] = array_pad(explode('/', $client, 2), 2, null);

            return __('stats::messages.clients.'.$browser).($version === null ? '' : ' '.$version);
        };
        $mebibytes = fn ($bytes) => $bytes === null ? null : round((float) $bytes / 1048576, 2);
        $number = fn ($value) => $value === null ? '—' : number_format(
            (float) $value,
            floor((float) $value) === (float) $value ? 0 : 2,
            str_starts_with(app()->getLocale(), 'fr') ? ',' : '.',
            ' ',
        );
        $serverTrends = [
            ['key' => 'players', 'unit' => '', 'scale' => 1],
            ['key' => 'cpu', 'unit' => '%', 'scale' => 100],
            ['key' => 'memory_bytes', 'unit' => 'MiB', 'scale' => 1],
            ['key' => 'tps', 'unit' => '', 'scale' => 20],
        ];
    @endphp

    <section class="card mb-4">
        <div class="card-body">
            <form class="row g-3 align-items-end" id="stats-filter" method="get" action="{{ route('plugins.stats.admin.index') }}">
                <div class="col-sm-auto">
                    <label class="form-label" for="stats-period">{{ __('stats::messages.period') }}</label>
                    <select class="form-select" id="stats-period" name="days">
                        @foreach ($periods as $option)
                            <option value="{{ $option }}" @selected($option === $period)>{{ $option === 1 ? __('stats::messages.today') : __('stats::messages.days', ['count' => $option]) }}</option>
                        @endforeach
                        <option value="all" @selected($period === 'all')>{{ __('stats::messages.all_time') }}</option>
                        <option value="custom" @selected($period === 'custom')>{{ __('stats::messages.custom') }}</option>
                    </select>
                </div>
                <div class="col-sm-auto" data-stats-custom>
                    <label class="form-label" for="stats-from">{{ __('stats::messages.from') }}</label>
                    <input class="form-control" type="date" id="stats-from" name="from" value="{{ $from }}"
                        min="{{ $minimumDay }}" max="{{ $maximumDay }}">
                </div>
                <div class="col-sm-auto" data-stats-custom>
                    <label class="form-label" for="stats-to">{{ __('stats::messages.to') }}</label>
                    <input class="form-control" type="date" id="stats-to" name="to" value="{{ $to }}"
                        min="{{ $minimumDay }}" max="{{ $maximumDay }}">
                </div>
                @if ($servers->isNotEmpty())
                    <div class="col-sm-auto">
                        <label class="form-label" for="stats-server">{{ __('stats::messages.server') }}</label>
                        <select class="form-select" id="stats-server" name="server">
                            @foreach ($servers as $option)
                                <option value="{{ $option->id }}" @selected($option->id === $server?->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-sm-auto pb-sm-2">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="stats-compare" name="compare" value="1" @checked($compare)>
                        <label class="form-check-label" for="stats-compare">{{ __('stats::messages.compare') }}</label>
                    </div>
                </div>
                <div class="col-sm-auto">
                    <button type="submit" class="btn btn-primary">{{ __('stats::messages.show') }}</button>
                </div>
            </form>
            @if ($rangeError)
                <p class="text-danger small mb-0 mt-3" role="alert">{{ __('stats::messages.range_error') }}</p>
            @endif
            <p class="text-body-secondary small mb-0 mt-3">
                {{ __('stats::messages.selected_range', ['from' => $from, 'to' => $to]) }}
                {{ __('stats::messages.utc') }}
                @if ($comparisonFrom !== null)
                    {{ __('stats::messages.previous_range', ['from' => $comparisonFrom, 'to' => $comparisonTo]) }}
                @elseif ($compare)
                    {{ __('stats::messages.no_comparison') }}
                @endif
            </p>
        </div>
    </section>

    <section aria-labelledby="stats-site-title" class="mb-4">
        <h2 id="stats-site-title" class="h4 mb-3">{{ __('stats::messages.website') }}</h2>
        <div class="row g-3 mb-3">
            <x-admin::stat :label="__('stats::messages.views')" :value="$summary['views']" icon="eye" />
            <x-admin::stat :label="__('stats::messages.visitors_total')" :value="$summary['visitors']" icon="people" color="info"
                :title="__('stats::messages.visitor_help')" />
            <x-admin::stat :label="__('stats::messages.registrations')" :value="$summary['registrations']" icon="person-plus" color="success" />
            <x-admin::stat :label="__('stats::messages.members')" :value="$summary['members']" icon="person-check" color="secondary" />
        </div>
        <div class="row g-3">
            @foreach (['views', 'visitors', 'registrations'] as $metric)
                <div class="col-lg-6 col-xxl-4">
                    @include('stats::admin.partials.trend', [
                        'id' => 'stats-'.$metric,
                        'title' => __('stats::messages.'.$metric),
                        'points' => $days,
                        'values' => $series[$metric],
                        'previous' => $comparison[$metric] ?? [],
                        'previousDays' => $comparisonDays,
                    ])
                </div>
            @endforeach
        </div>
        <p class="text-body-secondary small mt-3 mb-0">{{ __('stats::messages.visitor_help') }}</p>
        <details class="mt-3">
            <summary class="mb-2">{{ __('stats::messages.audience_details') }}</summary>
            <div class="row g-3 mt-0">
                @foreach (['guests', 'members'] as $metric)
                    <div class="col-lg-6">
                        @include('stats::admin.partials.trend', [
                            'id' => 'stats-'.$metric,
                            'title' => __('stats::messages.'.$metric),
                            'points' => $days,
                            'values' => $series[$metric],
                            'previous' => $comparison[$metric] ?? [],
                            'previousDays' => $comparisonDays,
                        ])
                    </div>
                @endforeach
            </div>
        </details>
        <div class="row g-3 mt-1">
            @foreach ([
                ['browsers', 'browser', $browsers, fn ($item) => $browserName($item->client)],
                ['systems', 'system', $systems, fn ($item) => __('stats::messages.clients.'.$item->client)],
                ['browser_versions', 'browser', $browserVersions, fn ($item) => $browserName($item->client)],
                ['top_pages', 'page', $pages, fn ($item) => $item->route],
                ['referrers', 'referrer', $referrers, fn ($item) => $item->domain === 'other' ? __('stats::messages.clients.other') : $item->domain],
            ] as [$heading, $label, $items, $name])
                <div class="{{ $loop->index < 3 ? 'col-md-6 col-xl-4' : 'col-xl-6' }}">
                    @include('stats::admin.partials.breakdown', [
                        'title' => __('stats::messages.'.$heading),
                        'label' => __('stats::messages.'.$label),
                        'items' => $items,
                        'name' => $name,
                    ])
                </div>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="stats-servers-title">
        <h2 id="stats-servers-title" class="h4 mb-3">{{ __('stats::messages.server_history') }}</h2>
        @if ($server === null || (!$summary['samples'] && !($comparisonSummary['samples'] ?? 0)))
            <div class="card mb-0">
                <div class="card-body text-body-secondary">
                    {{ __('stats::messages.'.($server === null ? 'no_servers' : 'no_samples')) }}
                </div>
            </div>
        @else
            <div class="row g-3 mb-3">
                <x-admin::stat :label="__('stats::messages.peak_players')" :value="$summary['players'] ?? '—'" icon="people" />
                <x-admin::stat :label="__('stats::messages.peak_cpu')" :value="$summary['cpu'] === null ? '—' : $number($summary['cpu']).' %'" icon="cpu" color="warning" />
                <x-admin::stat :label="__('stats::messages.peak_memory')" :value="$summary['memory_bytes'] === null ? '—' : $number($mebibytes($summary['memory_bytes'])).' MiB'" icon="memory" color="info" />
                <x-admin::stat :label="__('stats::messages.samples')" :value="$summary['samples']" icon="clock-history" color="secondary" />
            </div>
            <div class="row g-3 mb-3">
                @foreach ($serverTrends as ['key' => $metric, 'unit' => $unit, 'scale' => $scale])
                    @php
                        $values = $series[$metric];
                        $previous = $comparison[$metric] ?? [];
                        if ($metric === 'memory_bytes') {
                            $values = array_map($mebibytes, $values);
                            $previous = array_map($mebibytes, $previous);
                        }
                    @endphp
                    @if (array_filter([...$values, ...$previous], fn ($value) => $value !== null))
                        <div class="col-lg-6">
                            @include('stats::admin.partials.trend', [
                                'id' => 'stats-'.$metric,
                                'title' => __('stats::messages.'.$metric),
                                'points' => $serverDays,
                                'values' => $values,
                                'previous' => $previous,
                                'previousDays' => $comparisonServerDays,
                                'unit' => $unit,
                                'scale' => $scale,
                            ])
                        </div>
                    @endif
                @endforeach
            </div>
            <section class="card mb-0">
                <div class="card-header">
                    <h3 class="card-title">{{ __('stats::messages.recent_samples') }}</h3>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="text-nowrap">
                            <tr>
                                <th scope="col">{{ __('common.labels.date') }}</th>
                                @foreach (['peak_players', 'capacity', 'peak_cpu', 'peak_memory', 'memory_percent', 'tps', 'samples'] as $column)
                                    <th scope="col" class="text-end">{{ __('stats::messages.'.$column) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($samples->take(30) as $sample)
                                <tr>
                                    <th scope="row" class="fw-normal text-nowrap"><time datetime="{{ str_replace(' ', 'T', $sample->day) }}">{{ $sample->day }}</time></th>
                                    <td class="text-end">{{ $number($sample->players) }}</td>
                                    <td class="text-end">{{ $number($sample->capacity) }}</td>
                                    <td class="text-end">{{ $sample->cpu === null ? '—' : $number($sample->cpu).' %' }}</td>
                                    <td class="text-end">{{ $sample->memory_bytes === null ? '—' : $number($mebibytes($sample->memory_bytes)).' MiB' }}</td>
                                    <td class="text-end">{{ $sample->memory === null ? '—' : $number($sample->memory).' %' }}</td>
                                    <td class="text-end">{{ $number($sample->tps) }}</td>
                                    <td class="text-end">{{ $number($sample->samples) }}</td>
                                </tr>
                            @empty
                                <x-tables.empty :colspan="8" :message="__('stats::messages.no_samples')" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-body-secondary small">{{ __('stats::messages.sample_explanation') }}</div>
            </section>
        @endif
    </section>

    @push('scripts')
        <script type="module" src="{{ route('site.extensions.assets.show', ['id' => 'stats', 'asset' => 'admin.js']) }}"></script>
    @endpush
@endsection
