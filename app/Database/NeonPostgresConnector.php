<?php

namespace App\Database;

use Illuminate\Database\Connectors\PostgresConnector;

class NeonPostgresConnector extends PostgresConnector
{
    protected function getDsn(array $config): string
    {
        $dsn = parent::getDsn($config);
        $endpointId = $this->neonEndpointId($config);

        if ($endpointId === null || str_contains($dsn, ';options=')) {
            return $dsn;
        }

        return "{$dsn};options='endpoint={$endpointId}'";
    }

    private function neonEndpointId(array $config): ?string
    {
        $explicit = env('NEON_ENDPOINT_ID') ?: env('PGENDPOINT');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $host = $config['host'] ?? null;
        if (! is_string($host) || $host === '') {
            return null;
        }

        $firstLabel = explode('.', trim($host))[0] ?? '';
        if (! str_starts_with($firstLabel, 'ep-')) {
            return null;
        }

        return $firstLabel;
    }
}
