<?php

namespace Tests\Unit;

use App\Services\PixCopyCode;
use PHPUnit\Framework\TestCase;

class PixCopyCodeTest extends TestCase
{
    public function test_it_generates_a_static_pix_brcode_with_valid_crc(): void
    {
        $payload = PixCopyCode::build('550e8400-e29b-41d4-a716-446655440000', 1000, 'NX-G23EZ7F3', 'NEXORA', 'SAO PAULO');

        $this->assertStringStartsWith('00020101021226', $payload);
        $this->assertStringContainsString('0014br.gov.bcb.pix', $payload);
        $this->assertStringContainsString('0136550e8400-e29b-41d4-a716-446655440000', $payload);
        $this->assertStringContainsString('5303986', $payload);
        $this->assertStringContainsString('540510.00', $payload);
        $this->assertStringContainsString('5802BR', $payload);
        $this->assertMatchesRegularExpression('/6304[0-9A-F]{4}$/', $payload);

        $withoutCrc = substr($payload, 0, -4);
        $this->assertSame($payload, $withoutCrc.$this->crc16($withoutCrc));
    }

    public function test_it_only_accepts_random_bank_pix_keys(): void
    {
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', PixCopyCode::normalizePixKey('550E8400-E29B-41D4-A716-446655440000'));

        foreach (['11987654321', '119.766.392-47', 'pix@example.com', '+5511987654321'] as $value) {
            try {
                PixCopyCode::normalizePixKey($value);
                $this->fail("Expected {$value} to be rejected.");
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('chave aleatoria', $exception->getMessage());
            }
        }
    }

    private function crc16(string $payload): string
    {
        $crc = 0xFFFF;
        foreach (str_split($payload) as $char) {
            $crc ^= ord($char) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
