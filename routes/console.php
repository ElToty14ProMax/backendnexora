<?php

use App\Http\Controllers\NexoraController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('nexora:check-expired', function () {
    $result = app(NexoraController::class)->checkExpiredContributions();
    $this->info($result->getData(true)['message'] ?? 'Check complete.');
})->purpose('Check and expire contributions older than 24h without receipt');
