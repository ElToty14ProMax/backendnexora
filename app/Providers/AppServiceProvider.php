<?php

namespace App\Providers;

use App\Database\NeonPostgresConnector;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->configureNeonEndpointOption();
        $this->app->bind('db.connector.pgsql', fn () => new NeonPostgresConnector());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    private function configureNeonEndpointOption(): void
    {
        if (getenv('PGOPTIONS')) {
            return;
        }

        $databaseUrl = env('DATABASE_URL') ?: env('POSTGRES_URL') ?: env('DB_URL');
        $host = $databaseUrl ? parse_url((string) $databaseUrl, PHP_URL_HOST) : null;
        $host = $host ?: env('DB_HOST') ?: env('PGHOST') ?: env('POSTGRES_HOST');

        if (! is_string($host) || $host === '') {
            return;
        }

        $firstLabel = explode('.', trim($host))[0] ?? '';
        if (! str_starts_with($firstLabel, 'ep-')) {
            return;
        }

        $options = "endpoint={$firstLabel}";

        putenv("PGOPTIONS={$options}");
        $_ENV['PGOPTIONS'] = $options;
        $_SERVER['PGOPTIONS'] = $options;
    }
}
