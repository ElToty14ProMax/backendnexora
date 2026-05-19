<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$migs = DB::table('migrations')->orderBy('id')->get();
foreach ($migs as $m) {
    echo $m->id.': '.$m->migration.PHP_EOL;
}
