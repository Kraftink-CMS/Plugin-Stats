<?php

namespace KraftInk\Plugins\Stats\Services;

use App\Events\PruningData;
use App\Exceptions\OperationBusy;
use App\Kraft\System\OperationLock;
use App\Kraft\System\RetentionPolicy;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Stats\Models\ClientVisit;
use KraftInk\Plugins\Stats\Models\DailyVisit;
use KraftInk\Plugins\Stats\Models\PageVisit;
use KraftInk\Plugins\Stats\Models\ReferrerVisit;
use KraftInk\Plugins\Stats\Models\ServerSample;
use Throwable;

class History
{
    private const int REFERRER_LIMIT = 50;

    public function __construct(
        private OperationLock $lock,
        private RetentionPolicy $retention,
        private Router $router,
        private ServerTelemetry $telemetry,
    ) {
    }

    public function recordVisit(RequestHandled $event): void
    {
        $request = $event->request;
        $response = $event->response;
        if (
            !$request->isMethod('GET') || !$request->route() || !$response->isSuccessful() ||
            strtolower(trim(explode(';', $response->headers->get('Content-Type', ''))[0])) !== 'text/html' ||
            str_contains(strtolower($response->headers->get('X-Robots-Tag', '')), 'noindex') ||
            $request->routeIs('admin.*', 'plugins.*.admin.*', 'account.*', 'auth.*', 'password.*', 'verification.*', 'in-game-sign-in.*') ||
            $request->is('admin', 'admin/*', 'install', 'install/*', 'api/*', 'account', 'account/*', 'login', 'register') ||
            $request->ajax() || $request->prefetch() || $request->headers->has('X-Livewire') ||
            !in_array($request->header('Sec-Fetch-Dest'), [null, 'document'], true) ||
            str_contains(strtolower((string) $request->header('Sec-Purpose')), 'prefetch') ||
            $this->requiresPrivateAccess($request)
        ) {
            return;
        }
        $client = TrafficClient::dimensions($request->userAgent());
        if ($client === null) {
            return;
        }

        try {
            $this->lock->shared(fn () => $this->recordTraffic($request, $client));
        } catch (OperationBusy) {
            return;
        } catch (Throwable $error) {
            report($error);
        }
    }

    private function requiresPrivateAccess(Request $request): bool
    {
        $restricted = [Authenticate::class, RedirectIfAuthenticated::class, ValidateSignature::class];

        return array_any(
            $this->router->gatherRouteMiddleware($request->route()),
            fn ($middleware) => is_string($middleware) && in_array(explode(':', $middleware, 2)[0], $restricted, true),
        );
    }

    public function recordRegistration(Registered $event): void
    {
        if (!$event->user instanceof User || $event->user->isServiceAccount()) {
            return;
        }

        try {
            $this->lock->shared(function (): void {
                $this->increment(DailyVisit::query(), ['day' => now('UTC')->toDateString()], ['registrations' => 1]);
            });
        } catch (OperationBusy) {
            return;
        } catch (Throwable $error) {
            report($error);
        }
    }

    private function recordTraffic(Request $request, array $client): void
    {
        $time = now('UTC');
        $day = $time->toDateString();
        $column = $request->user() ? 'member' : 'guest';
        $route = (string) $request->route()->getName();
        $route = preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,159}$/D', $route) ? $route : 'other';
        $domain = $this->externalReferrer($request);
        $visitorKey = $this->markVisitor($request, $time);

        try {
            DB::transaction(function () use ($day, $column, $route, $domain, $client, $visitorKey): void {
                $traffic = [$column => 1];
                $this->increment(DailyVisit::query(), ['day' => $day], $visitorKey === null ? $traffic : $traffic + ['visitors' => 1]);
                $this->increment(PageVisit::query(), ['day' => $day, 'route' => $route], $traffic);
                if ($domain !== null) {
                    $this->recordReferrer($day, $domain);
                }
                foreach ($client as $dimension => $name) {
                    $this->increment(ClientVisit::query(), ['day' => $day, 'dimension' => $dimension, 'client' => $name], ['visits' => 1]);
                }
            }, 3);
        } catch (Throwable $error) {
            if ($visitorKey !== null) {
                try {
                    Cache::forget($visitorKey);
                } catch (Throwable $cacheError) {
                    report($cacheError);
                }
            }
            throw $error;
        }
    }

    private function markVisitor(Request $request, Carbon $time): ?string
    {
        $secret = config('app.key');
        if (!$request->hasSession() || !is_string($secret) || $secret === '') {
            return null;
        }

        $key = 'stats:session:'.hash_hmac('sha256', $time->toDateString().':'.$request->session()->getId(), $secret);
        try {
            return Cache::add($key, true, $time->copy()->addDay()->startOfDay()) ? $key : null;
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }

    private function externalReferrer(Request $request): ?string
    {
        $value = $request->header('Referer');
        if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            return null;
        }
        $url = parse_url($value);
        if (!in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true) || isset($url['user']) || isset($url['pass'])) {
            return null;
        }

        $domain = $this->normalizedHost($url['host'] ?? '');
        if (
            strlen($domain) > 253 ||
            !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9](?:[a-z0-9-]{0,57}[a-z0-9])?)$/D', $domain) ||
            preg_match('/\.(?:example|internal|invalid|lan|local|localhost|onion|test)$/D', $domain)
        ) {
            return null;
        }

        $hosts = [$request->getHost(), (string) parse_url((string) config('app.url'), PHP_URL_HOST)];
        foreach ($hosts as $host) {
            $host = $this->normalizedHost($host);
            if ($host !== '' && ($domain === $host || str_ends_with($domain, '.'.$host) || str_ends_with($host, '.'.$domain))) {
                return null;
            }
        }

        return $domain;
    }

    private function normalizedHost(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function recordReferrer(string $day, string $domain): void
    {
        $referrers = ReferrerVisit::query()->where('day', $day);
        if ((clone $referrers)->where('domain', $domain)->increment('visits') > 0) {
            return;
        }
        if ($referrers->where('domain', '!=', 'other')->count() >= self::REFERRER_LIMIT) {
            $domain = 'other';
        }

        $this->increment(ReferrerVisit::query(), ['day' => $day, 'domain' => $domain], ['visits' => 1]);
    }

    private function increment(Builder $query, array $key, array $counters): void
    {
        $row = (clone $query)->where($key);
        if ($row->incrementEach($counters) === 0 && $query->insertOrIgnore($key + $counters) === 0) {
            $row->incrementEach($counters);
        }
    }

    public function sampleServers(): void
    {
        try {
            $this->lock->shared(function (): void {
                $time = now('UTC')->floorMinutes(5)->format('Y-m-d H:i:s');
                Server::query()->where('enabled', true)->chunkById(100, function ($servers) use ($time): void {
                    $rows = [];
                    foreach ($servers as $server) {
                        if (!$server->hasFreshStatus()) {
                            continue;
                        }

                        $status = $server->status ?? [];
                        $players = $server->onlinePlayerCount();
                        $capacity = $server->playerCapacity();
                        $uptime = $this->nonnegativeInteger($status['uptime_seconds'] ?? null);
                        $tps = $status['tps'][0] ?? null;
                        $tps = is_numeric($tps) && $tps >= 0 && $tps <= 1000 ? (float) $tps : null;
                        $statusCpu = $status['cpu_percent'] ?? null;
                        $cpu = $this->telemetry->cpuPercent($server) ?? (
                            is_numeric($statusCpu) && $statusCpu >= 0 && $statusCpu <= 100 ? round((float) $statusCpu, 2) : null
                        );
                        $used = $this->nonnegativeInteger($status['memory']['used'] ?? null);
                        $maximum = $this->nonnegativeInteger($status['memory']['max'] ?? null);
                        $memory = $used !== null && $maximum > 0
                            ? (int) round(min(100, $used / $maximum * 100))
                            : null;
                        if ($players === null && $capacity === null && $uptime === null && $tps === null && $cpu === null && $used === null && $maximum === null) {
                            continue;
                        }

                        $rows[] = [
                            'server_id' => $server->id,
                            'sampled_at' => $time,
                            'players' => $players,
                            'capacity' => $capacity,
                            'uptime_seconds' => $uptime,
                            'tps' => $tps,
                            'cpu_percent' => $cpu,
                            'memory_percent' => $memory,
                            'memory_used_bytes' => $used,
                            'memory_max_bytes' => $maximum,
                        ];
                    }
                    if ($rows !== []) {
                        ServerSample::query()->insertOrIgnore($rows);
                    }
                });
            });
        } catch (OperationBusy) {
            return;
        }
    }

    private function nonnegativeInteger(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $integer === false ? null : $integer;
    }

    public function prune(PruningData $event): void
    {
        $day = now('UTC')->startOfDay()->subDays($this->retention->days('technical') - 1)->toDateString();
        $history = [
            'visits' => DailyVisit::query()->where('day', '<', $day),
            'pages' => PageVisit::query()->where('day', '<', $day),
            'referrers' => ReferrerVisit::query()->where('day', '<', $day),
            'clients' => ClientVisit::query()->where('day', '<', $day),
            'samples' => ServerSample::query()->where('sampled_at', '<', $day.' 00:00:00'),
        ];
        foreach ($history as $scope => $query) {
            $event->add('stats.'.$scope, $event->dryRun ? $query->count() : $query->delete());
        }
    }
}
