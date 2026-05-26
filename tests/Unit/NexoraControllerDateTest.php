<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Http\Controllers\NexoraController;
use App\Services\ReceitaFederalService;
use App\Services\SecurityService;
use ReflectionMethod;
use Tests\TestCase;

class NexoraControllerDateTest extends TestCase
{
    public function test_normalize_birthdate_accepts_iso_and_slash_format(): void
    {
        $method = $this->normalizeBirthdateMethod();
        $controller = new NexoraController(new SecurityService, new ReceitaFederalService);

        $this->assertSame('1990-05-25', $method->invoke($controller, '1990-05-25'));
        $this->assertSame('1990-05-25', $method->invoke($controller, '25/05/1990'));
    }

    public function test_normalize_birthdate_rejects_invalid_calendar_dates(): void
    {
        $this->expectException(ApiException::class);

        $method = $this->normalizeBirthdateMethod();
        $controller = new NexoraController(new SecurityService, new ReceitaFederalService);

        $method->invoke($controller, '31/02/1990');
    }

    private function normalizeBirthdateMethod(): ReflectionMethod
    {
        $method = new ReflectionMethod(NexoraController::class, 'normalizeBirthdate');
        $method->setAccessible(true);

        return $method;
    }
}
