<?php

use Illuminate\Support\Facades\Route;
use KraftInk\Plugins\Stats\Http\Controllers\StatisticsController;

Route::get('/admin/plugins/stats', StatisticsController::class)
    ->middleware(['web', 'auth', 'permission:admin.access', 'permission:stats.view'])
    ->name('plugins.stats.admin.index');
