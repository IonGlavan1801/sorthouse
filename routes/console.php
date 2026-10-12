<?php

use App\Application\Demo\DemoSessionService;
use App\Jobs\SweepOutbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('demo:purge', function (DemoSessionService $service) {
    $this->info('Purged expired demo workspaces: '.$service->purgeExpired());
})->purpose('Delete expired demo sessions with all their data and stored files');

Schedule::command('demo:purge')->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new SweepOutbox)->everyMinute();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
