<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Custom notifications a sender scheduled for later. Every minute, so "send at
// 19:00" means 19:00 and not "some time in the next hour".
Schedule::command('notifications:send-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

// Lessons whose scheduled publish time has arrived. Nothing flips a row at that
// moment, so without this tick a scheduled lesson goes live unannounced.
Schedule::command('notifications:announce-lessons')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Teacher billing alerts that only the clock can raise. Daily, mid-morning, so
// the warning lands in working hours rather than overnight.
Schedule::command('notifications:subscription-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping();

// Fawry references whose payment notification never arrived. A student pays cash
// at an outlet and only the notification tells us — hourly, ask Fawry directly
// about everything still pending, and close what has lapsed.
Schedule::command('fawry:reconcile')
    ->hourly()
    ->withoutOverlapping();
