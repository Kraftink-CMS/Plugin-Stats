<?php

namespace KraftInk\Plugins\Stats\Services;

use App\Models\Servers\Server;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ServerTelemetry
{
    public function recordHeartbeat(RequestHandled $event): void
    {
        $request = $event->request;
        $response = $event->response;
        $server = $request->attributes->get('link.server');
        if (!$server instanceof Server || !$response instanceof JsonResponse || !$response->isSuccessful()) {
            return;
        }

        if ($request->routeIs('api.link.heartbeat.store')) {
            $heartbeat = $request->json()->all();
            $result = 'success';
        } elseif ($request->routeIs('api.link.poll')) {
            $heartbeat = $request->json('operations.heartbeat');
            $result = 'data.operations.heartbeat.success';
        } else {
            return;
        }

        if (!is_array($heartbeat) || !$server->last_seen_at || data_get($response->getData(true), $result) !== true) {
            return;
        }

        $cpu = $heartbeat['cpu_percent'] ?? null;
        try {
            if ((!is_int($cpu) && !is_float($cpu)) || !is_finite($cpu) || $cpu < 0 || $cpu > 100) {
                Cache::forget($this->key($server));

                return;
            }

            Cache::put($this->key($server), [
                'observed_at' => $server->last_seen_at->getTimestamp(),
                'cpu_percent' => round($cpu, 2),
            ], 90);
        } catch (Throwable $error) {
            report($error);
        }
    }

    public function cpuPercent(Server $server): ?float
    {
        if ($server->connection !== 'kraftlink' || !$server->last_seen_at) {
            return null;
        }

        $sample = Cache::get($this->key($server));

        return ($sample['observed_at'] ?? null) === $server->last_seen_at->getTimestamp()
            ? $sample['cpu_percent']
            : null;
    }

    private function key(Server $server): string
    {
        return 'stats:server-cpu:'.$server->id;
    }
}
