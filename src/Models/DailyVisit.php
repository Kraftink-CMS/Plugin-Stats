<?php

namespace KraftInk\Plugins\Stats\Models;

use Illuminate\Database\Eloquent\Model;

class DailyVisit extends Model
{
    protected $table = 'stats__daily_visits';

    protected $primaryKey = 'day';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;
}
