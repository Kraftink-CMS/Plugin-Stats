<?php

namespace KraftInk\Plugins\Stats\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Servers\Server;

class ServerSample extends Model
{
    protected $table = 'stats__server_samples';

    public $timestamps = false;

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    protected function casts(): array
    {
        return ['sampled_at' => 'immutable_datetime', 'tps' => 'float', 'cpu_percent' => 'float'];
    }
}
