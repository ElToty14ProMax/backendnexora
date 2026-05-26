<?php

namespace Tests\Feature;

use App\Http\Controllers\NexoraController;
use App\Services\ReceitaFederalService;
use App\Services\SecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class SuperAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_preserves_existing_random_pix_without_override(): void
    {
        config([
            'nexora.admin_pix_key' => '00000000-0000-4000-8000-000000000000',
            'nexora.super_admin_email' => 'founder@example.com',
            'nexora.super_admin_cpf' => '52998224725',
            'nexora.super_admin_password' => 'FounderPass123',
        ]);

        $security = app(SecurityService::class);
        $controller = new NexoraController($security, app(ReceitaFederalService::class));
        $method = $this->ensureBootstrapMethod();
        $keptPixKey = 'e4d1468b-41dd-40b1-8bbb-86825c3958c7';

        DB::table('users')->where('email', 'founder@example.com')->update([
            'pix_cipher' => $security->encrypt($keptPixKey),
        ]);

        $method->invoke($controller);

        $cipher = (string) DB::table('users')->where('email', 'founder@example.com')->value('pix_cipher');
        $this->assertSame($keptPixKey, $security->decrypt($cipher));
    }

    public function test_bootstrap_applies_explicit_pix_override(): void
    {
        config([
            'nexora.admin_pix_key' => '00000000-0000-4000-8000-000000000000',
            'nexora.super_admin_email' => 'founder@example.com',
            'nexora.super_admin_cpf' => '52998224725',
            'nexora.super_admin_password' => 'FounderPass123',
        ]);

        $security = app(SecurityService::class);
        $controller = new NexoraController($security, app(ReceitaFederalService::class));
        $method = $this->ensureBootstrapMethod();
        $overridePixKey = 'e4d1468b-41dd-40b1-8bbb-86825c3958c7';

        $method->invoke($controller, $overridePixKey);

        $cipher = (string) DB::table('users')->where('email', 'founder@example.com')->value('pix_cipher');
        $this->assertSame($overridePixKey, $security->decrypt($cipher));
    }

    private function ensureBootstrapMethod(): ReflectionMethod
    {
        $method = new ReflectionMethod(NexoraController::class, 'ensureBootstrapSuperAdmin');
        $method->setAccessible(true);

        return $method;
    }
}
