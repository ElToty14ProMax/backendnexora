<?php

namespace App\Console\Commands;

use App\Http\Controllers\NexoraController;
use Illuminate\Console\Command;

class CheckExpiredContributions extends Command
{
    protected $signature = 'nexora:check-expired';

    protected $description = 'Marcar como expiradas las contribuciones sin comprobantes despues del plazo configurado';

    public function handle(): int
    {
        $controller = app(NexoraController::class);
        $result = $controller->checkExpiredContributions();
        $data = json_decode($result->getContent(), true);
        $this->info($data['message'] ?? 'Verificación de expiración ejecutada.');

        return self::SUCCESS;
    }
}
