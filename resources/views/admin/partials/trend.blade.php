@php
    $previous = $previous ?? [];
    $previousDays = $previousDays ?? [];
    $unit = $unit ?? '';
    $maximum = max($scale ?? 1, ceil(max(0, ...$values, ...$previous)));
    $count = count($points);
    $lastIndex = $count - 1;
    $x = fn ($index) => $lastIndex === 0 ? 320 : 8 + 624 * $index / $lastIndex;
    $y = fn ($value) => 154 - 148 * $value / $maximum;
    $line = function (array $series) use ($x, $y): string {
        $commands = [];
        $connected = false;
        foreach ($series as $index => $value) {
            if ($value === null) {
                $connected = false;
                continue;
            }
            $point = sprintf('%.2F %.2F', $x($index), $y($value));
            $commands[] = ($connected ? 'L' : 'M').$point;
            if (!$connected && ($series[$index + 1] ?? null) === null) {
                $commands[] = 'l0.01 0';
            }
            $connected = true;
        }

        return implode(' ', $commands);
    };
    $formatDate = fn ($point, $long = false) => \Carbon\CarbonImmutable::parse($point, 'UTC')->locale(app()->getLocale())
        ->translatedFormat(strlen($point) > 10 ? ($long ? 'd M Y H:i' : 'H:i') : ($long ? 'd M Y' : 'd M'));
    $index = $lastIndex;
    foreach (array_reverse($values, true) as $key => $value) {
        if ($value !== null) {
            $index = $key;
            break;
        }
    }
@endphp
<section class="card h-100 mb-0" data-stats-trend data-stats-values="{{ json_encode($values, JSON_THROW_ON_ERROR) }}"
    data-stats-previous="{{ json_encode($previous, JSON_THROW_ON_ERROR) }}" data-stats-unit="{{ $unit }}"
    data-stats-dates="{{ json_encode(array_map(fn ($point) => $formatDate($point, true), $points), JSON_THROW_ON_ERROR) }}"
    data-stats-previous-dates="{{ json_encode(array_map(fn ($point) => $formatDate($point, true), $previousDays), JSON_THROW_ON_ERROR) }}">
    <div class="card-header">
        <h3 class="card-title" id="{{ $id }}-title">{{ $title }}</h3>
    </div>
    <div class="card-body">
        <div class="d-flex align-items-stretch gap-2">
            <div class="d-flex flex-shrink-0 flex-column justify-content-between text-end text-nowrap text-body-secondary small" aria-hidden="true">
                <span>{{ $maximum }}{{ $unit !== '' ? ' '.$unit : '' }}</span>
                <span>0</span>
            </div>
            <svg viewBox="0 0 640 160" height="160" class="w-100 text-primary" preserveAspectRatio="none"
                role="img" aria-labelledby="{{ $id }}-title" data-stats-plot>
                <path d="M0 6H640 M0 80H640 M0 154H640" fill="none" stroke="currentColor" opacity="0.18" />
                @if ($previous !== [])
                    <path d="{{ $line($previous) }}" class="text-body-secondary" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-dasharray="4 4" vector-effect="non-scaling-stroke" />
                @endif
                <path d="{{ $line($values) }}" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                <line x1="{{ $x($index) }}" x2="{{ $x($index) }}" y1="6" y2="154" stroke="currentColor"
                    opacity="0.35" vector-effect="non-scaling-stroke" data-stats-cursor />
            </svg>
        </div>
        <div class="d-flex justify-content-between gap-2 small text-body-secondary mb-2">
            @foreach (array_unique([0, (int) floor($lastIndex / 2), $lastIndex]) as $tick)
                <span>{{ $formatDate($points[$tick]) }}</span>
            @endforeach
        </div>
        @if ($count > 1)
            <input type="range" class="form-range mb-1" min="0" max="{{ $lastIndex }}" value="{{ $index }}"
                aria-label="{{ __('stats::messages.inspect', ['metric' => $title]) }}" data-stats-range hidden>
        @endif
        <div class="d-flex flex-wrap justify-content-between gap-2 small" data-stats-readout>
            <span>
                <span class="text-body-secondary" data-stats-date>{{ $formatDate($points[$index], true) }}</span>
                <strong class="text-primary ms-1" data-stats-value>{{ isset($values[$index]) ? trim($values[$index].' '.$unit) : '—' }}</strong>
            </span>
            @if ($previous !== [])
                <span>
                    <span class="text-body-secondary" data-stats-previous-date>{{ $formatDate($previousDays[$index], true) }}</span>
                    <strong class="ms-1" data-stats-previous-value>{{ isset($previous[$index]) ? trim($previous[$index].' '.$unit) : '—' }}</strong>
                </span>
            @endif
        </div>
    </div>
</section>
