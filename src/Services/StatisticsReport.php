<?php

namespace KraftInk\Plugins\Stats\Services;

use App\Kraft\System\RetentionPolicy;
use App\Models\Servers\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use KraftInk\Plugins\Stats\Models\ClientVisit;
use KraftInk\Plugins\Stats\Models\DailyVisit;
use KraftInk\Plugins\Stats\Models\PageVisit;
use KraftInk\Plugins\Stats\Models\ReferrerVisit;
use KraftInk\Plugins\Stats\Models\ServerSample;

class StatisticsReport
{
    private const array PERIODS = [1, 7, 30, 90, 180];

    private const array SITE_KEYS = ['views', 'guests', 'members', 'registrations', 'visitors'];

    private const array SERVER_MAXIMUMS = ['players', 'capacity', 'memory', 'memory_bytes', 'memory_max_bytes', 'cpu'];

    private const array SERVER_KEYS = [...self::SERVER_MAXIMUMS, 'tps', 'uptime', 'samples'];

    public function __construct(private RetentionPolicy $retention)
    {
    }

    public function read(array $filters, bool $export = false): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $earliest = $today->subDays($this->retention->days('technical') - 1);
        [$period, $start, $end, $rangeError] = $this->range($filters, $earliest, $today);
        $hourly = !$export && $start->isSameDay($end);
        $compare = filter_var($filters['compare'] ?? false, FILTER_VALIDATE_BOOL);
        $previousStart = $start->subDays((int) $start->diffInDays($end) + 1);
        $previousEnd = $start->subDay();

        $servers = Server::query()->orderBy('name')->orderBy('id')->get(['id', 'name']);
        $serverId = filter_var($filters['server'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $server = ($serverId === false ? null : $servers->firstWhere('id', $serverId)) ?? $servers->first();
        $current = $this->readPeriod($start, $end, $server, $hourly);
        $previous = $compare && $previousStart->gte($earliest)
            ? $this->readPeriod($previousStart, $previousEnd, $server, $hourly)
            : null;

        $data = [
            ...$current,
            'periods' => self::PERIODS,
            'period' => $period,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'minimumDay' => $earliest->toDateString(),
            'maximumDay' => $today->toDateString(),
            'rangeError' => $rangeError,
            'hourly' => $hourly,
            'servers' => $servers,
            'server' => $server,
            'compare' => $compare,
            'comparisonFrom' => $previous === null ? null : $previousStart->toDateString(),
            'comparisonTo' => $previous === null ? null : $previousEnd->toDateString(),
            'comparisonDays' => $previous['days'] ?? [],
            'comparisonServerDays' => $previous['serverDays'] ?? [],
            'comparison' => $previous['series'] ?? null,
            'comparisonSummary' => $previous['summary'] ?? null,
        ];

        return $export ? $data : [...$data, ...$this->breakdowns($data['from'], $data['to'])];
    }

    private function range(array $filters, CarbonImmutable $earliest, CarbonImmutable $today): array
    {
        $period = $filters['days'] ?? 30;
        if ($period === 'all') {
            return [$period, $earliest, $today, false];
        }
        if ($period === 'custom') {
            $start = $this->date($filters['from'] ?? null);
            $end = $this->date($filters['to'] ?? null);
            if ($start !== null && $end !== null && $start->gte($earliest) && $end->lte($today) && $start->lte($end)) {
                return [$period, $start, $end, false];
            }
        }

        $days = filter_var($period, FILTER_VALIDATE_INT);
        $days = in_array($days, self::PERIODS, true) ? $days : 30;

        return [$days, $today->subDays($days - 1)->max($earliest), $today, $period === 'custom'];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date?->toDateString() === $value ? $date : null;
    }

    private function readPeriod(CarbonImmutable $start, CarbonImmutable $end, ?Server $server, bool $hourly): array
    {
        $days = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $days[] = $date->toDateString();
        }
        $serverDays = $hourly
            ? array_map(fn (int $hour) => $start->addHours($hour)->toDateTimeString(), range(0, 23))
            : $days;

        $visits = DailyVisit::query()->whereBetween('day', [$days[0], $days[array_key_last($days)]])->toBase()->get()->keyBy('day');
        $samples = $server === null ? collect() : $this->sampleHistory($start, $end, $server, $hourly);
        $series = array_fill_keys([...self::SITE_KEYS, ...self::SERVER_KEYS], []);

        foreach ($days as $day) {
            $visit = $visits->get($day);
            $guests = (int) ($visit?->guest ?? 0);
            $members = (int) ($visit?->member ?? 0);
            $series['views'][] = $guests + $members;
            $series['guests'][] = $guests;
            $series['members'][] = $members;
            $series['registrations'][] = (int) ($visit?->registrations ?? 0);
            $series['visitors'][] = (int) ($visit?->visitors ?? 0);
        }
        foreach ($serverDays as $day) {
            $sample = $samples->get($day);
            foreach (self::SERVER_KEYS as $key) {
                $series[$key][] = $sample?->{$key};
            }
        }
        $summary = $this->summary($series, $samples);
        $samples = $samples->values();

        return compact('days', 'serverDays', 'samples', 'series', 'summary');
    }

    private function sampleHistory(CarbonImmutable $start, CarbonImmutable $end, Server $server, bool $hourly): Collection
    {
        $query = ServerSample::query()->where('server_id', $server->id)
            ->where('sampled_at', '>=', $start->toDateTimeString())
            ->where('sampled_at', '<', $end->addDay()->toDateTimeString())->toBase();
        $driver = $query->getConnection()->getDriverName();
        $bucket = $this->sampleBucket($driver, $hourly);
        $latest = $query->clone()->whereNotNull('uptime_seconds')->selectRaw('MAX(sampled_at)')->groupByRaw($bucket);
        $uptimes = ServerSample::query()->where('server_id', $server->id)->whereIn('sampled_at', $latest)->toBase()
            ->selectRaw($bucket.' AS day, uptime_seconds')->pluck('uptime_seconds', 'day');

        return $query->selectRaw($bucket.' AS day, COUNT(*) AS samples, MAX(players) AS players, MAX(capacity) AS capacity, '
            .'MAX(memory_percent) AS memory, MAX(memory_used_bytes) AS memory_bytes, MAX(memory_max_bytes) AS memory_max_bytes, '
            .'MAX(cpu_percent) AS cpu, SUM(tps) AS tps_sum, COUNT(tps) AS tps_count')
            ->groupByRaw($bucket)->orderByDesc('day')->get()->keyBy('day')->each(function (object $sample, string $day) use ($uptimes): void {
                foreach (['players', 'capacity', 'memory', 'memory_bytes', 'memory_max_bytes'] as $key) {
                    $sample->{$key} = $sample->{$key} === null ? null : (int) $sample->{$key};
                }
                $sample->samples = (int) $sample->samples;
                $sample->cpu = $sample->cpu === null ? null : (float) $sample->cpu;
                $sample->tps = $sample->tps_count > 0 ? round($sample->tps_sum / $sample->tps_count, 3) : null;
                $sample->uptime = $uptimes->has($day) ? (int) $uptimes->get($day) : null;
            });
    }

    private function sampleBucket(string $driver, bool $hourly): string
    {
        if (!$hourly) {
            return 'DATE(sampled_at)';
        }

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', sampled_at)",
            'pgsql' => "TO_CHAR(sampled_at, 'YYYY-MM-DD HH24:00:00')",
            default => "DATE_FORMAT(sampled_at, '%Y-%m-%d %H:00:00')",
        };
    }

    private function summary(array $series, Collection $samples): array
    {
        $summary = array_map('array_sum', array_intersect_key($series, array_flip(self::SITE_KEYS)));
        foreach (self::SERVER_MAXIMUMS as $key) {
            $values = array_filter($series[$key], fn ($value) => $value !== null);
            $summary[$key] = $values === [] ? null : max($values);
        }
        $tpsCount = $samples->sum('tps_count');
        $summary['tps'] = $tpsCount > 0 ? round($samples->sum('tps_sum') / $tpsCount, 3) : null;
        $summary['uptime'] = $samples->first(fn (object $sample) => $sample->uptime !== null)?->uptime;
        $summary['samples'] = $samples->sum('samples');

        return $summary;
    }

    private function breakdowns(string $from, string $to): array
    {
        $pages = PageVisit::query()->whereBetween('day', [$from, $to])->toBase()
            ->selectRaw('route, SUM(guest) AS guests, SUM(member) AS members, SUM(guest) + SUM(member) AS views')
            ->groupBy('route')->orderByDesc('views')->orderBy('route')->limit(10)->get();
        $referrers = ReferrerVisit::query()->whereBetween('day', [$from, $to])->toBase()
            ->selectRaw('domain, SUM(visits) AS views')->groupBy('domain')->orderByDesc('views')->orderBy('domain')->limit(10)->get();
        $clients = ClientVisit::query()->whereBetween('day', [$from, $to])->toBase()
            ->selectRaw('dimension, client, SUM(visits) AS views')->groupBy('dimension', 'client')
            ->orderByDesc('views')->orderBy('client')->get()->groupBy('dimension');

        return [
            'pages' => $pages,
            'referrers' => $referrers,
            'browsers' => $clients->get('browser', collect()),
            'browserVersions' => $clients->get('browser_version', collect())->take(10),
            'systems' => $clients->get('os', collect()),
        ];
    }
}
