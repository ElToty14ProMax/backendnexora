<?php

namespace Tests\Feature;

use App\Services\SecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContributionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_contributions_keeps_expired_items_in_user_history(): void
    {
        config(['nexora.contribution_expiration_minutes' => 5]);

        $security = app(SecurityService::class);
        $now = (int) floor(microtime(true) * 1000);
        $requesterId = (string) Str::uuid();
        $donorId = (string) Str::uuid();
        $token = 'donor-history-token';

        DB::table('users')->insert([
            $this->userPayload($security, $requesterId, 'requester@example.com', '52998224725', '550e8400-e29b-41d4-a716-446655440000', $now),
            $this->userPayload($security, $donorId, 'donor@example.com', '11144477735', '9f4c2c7e-7f9b-45c0-8c33-0fa84fb8867b', $now),
        ]);

        DB::table('auth_tokens')->insert([
            'token_hash' => $security->hashToken($token),
            'user_id' => $donorId,
            'expires_at' => $now + 3600000,
            'created_at_ms' => $now,
        ]);

        DB::table('support_requests')->insert([
            'id' => 'support-history',
            'requester_id' => $requesterId,
            'public_code' => 'AP-HISTORY',
            'amount_cents' => 3000,
            'funded_cents' => 1000,
            'due_days' => 7,
            'status' => 'OPEN',
            'created_at_ms' => $now - 600000,
            'approved_at' => $now - 600000,
        ]);

        DB::table('contributions')->insert([
            'id' => 'contribution-history',
            'request_id' => 'support-history',
            'donor_id' => $donorId,
            'amount_cents' => 1000,
            'status' => 'PENDING_RECEIPTS',
            'created_at_ms' => $now - 600000,
            'verification_status' => null,
            'admin_review_required' => false,
            'has_sender_receipt' => false,
            'has_receiver_receipt' => false,
        ]);

        $response = $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/support-requests/contributions/mine');

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => 'contribution-history',
            'status' => 'EXPIRED',
        ]);
    }

    private function userPayload(
        SecurityService $security,
        string $id,
        string $email,
        string $cpf,
        string $pixKey,
        int $now,
    ): array {
        return [
            'id' => $id,
            'public_id' => 'NX-'.substr(strtoupper(str_replace('-', '', $id)), 0, 8),
            'name' => 'Pessoa Teste',
            'email' => $email,
            'email_verified' => true,
            'cpf_hash' => $security->hashCpf($cpf),
            'cpf_cipher' => $security->encrypt($cpf),
            'birthdate' => '1990-01-01',
            'pix_cipher' => $security->encrypt($pixKey),
            'password_hash' => $security->hashPassword('SenhaTeste123'),
            'status' => 'APPROVED',
            'role' => 'USER',
            'invite_code' => substr(strtoupper(str_replace('-', '', $id)), 0, 8),
            'created_at_ms' => $now,
        ];
    }
}
