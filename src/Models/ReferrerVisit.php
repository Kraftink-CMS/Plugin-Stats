<?php

namespace KraftInk\Plugins\Stats\Models;

use Illuminate\Database\Eloquent\Model;

class ReferrerVisit extends Model
{
    protected $table = 'stats__referrer_visits';

    public $timestamps = false;
}
