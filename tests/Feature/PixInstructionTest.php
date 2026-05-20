<?php

namespace Tests\Feature;

use App\Services\SecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PixInstructionTest extends TestCase
{
    use RefreshDatabase;

    public function test_contribution_pix_code_uses_the_requester_pix_key_not_the_admin_key(): void
    {
        config(['nexora.admin_pix_key' => '00000000-0000-4000-8000-000000000000']);

        $security = app(SecurityService::class);
        $now = (int) floor(microtime(true) * 1000);
        $requesterId = (string) Str::uuid();
        $donorId = (string) Str::uuid();
        $token = 'donor-test-token';

        DB::table('users')->insert([
            [
                'id' => $requesterId,
                'public_id' => 'NX-REQTEST',
                'name' => 'Pessoa Recebedora',
                'email' => 'recebedora@example.com',
                'email_verified' => true,
                'cpf_hash' => $security->hashCpf('52998224725'),
                'cpf_cipher' => $security->encrypt('52998224725'),
                'pix_cipher' => $security->encrypt('550e8400-e29b-41d4-a716-446655440000'),
                'password_hash' => $security->hashPassword('SenhaTeste123'),
                'status' => 'APPROVED',
                'role' => 'USER',
                'invite_code' => 'REQTEST1',
                'created_at_ms' => $now,
            ],
            [
                'id' => $donorId,
                'public_id' => 'NX-DONTEST',
                'name' => 'Pessoa Doadora',
                'email' => 'doadora@example.com',
                'email_verified' => true,
                'cpf_hash' => $security->hashCpf('11144477735'),
                'cpf_cipher' => $security->encrypt('11144477735'),
                'pix_cipher' => $security->encrypt('9f4c2c7e-7f9b-45c0-8c33-0fa84fb8867b'),
                'password_hash' => $security->hashPassword('SenhaTeste123'),
                'status' => 'APPROVED',
                'role' => 'USER',
                'invite_code' => 'DONTEST1',
                'created_at_ms' => $now,
            ],
        ]);

        DB::table('auth_tokens')->insert([
            'token_hash' => $security->hashToken($token),
            'user_id' => $donorId,
            'expires_at' => $now + 3600000,
            'created_at_ms' => $now,
        ]);

        DB::table('support_requests')->insert([
            'id' => 'support-test',
            'requester_id' => $requesterId,
            'public_code' => 'AP-REQPIX',
            'amount_cents' => 3000,
            'funded_cents' => 0,
            'due_days' => 7,
            'status' => 'OPEN',
            'created_at_ms' => $now,
            'approved_at' => $now,
        ]);

        $response = $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/support-requests/support-test/contributions', [
                'amountCents' => 1000,
            ]);

        $response->assertCreated();
        $payload = $response->json('pixCopyCode');

        $this->assertStringContainsString('550e8400-e29b-41d4-a716-446655440000', $payload);
        $this->assertStringNotContainsString('00000000-0000-4000-8000-000000000000', $payload);
        $this->assertSame('', $response->json('receiverPixKey'));
    }
}
