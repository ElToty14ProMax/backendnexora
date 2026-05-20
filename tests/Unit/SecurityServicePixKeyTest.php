<?php

namespace Tests\Unit;

use App\Services\SecurityService;
use PHPUnit\Framework\TestCase;

class SecurityServicePixKeyTest extends TestCase
{
    public function test_it_accepts_only_random_bank_pix_keys(): void
    {
        $security = new SecurityService;

        $this->assertTrue($security->isValidPixKey('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertTrue($security->isValidPixKey('550E8400-E29B-41D4-A716-446655440000'));

        $this->assertFalse($security->isValidPixKey('52998224725'));
        $this->assertFalse($security->isValidPixKey('11987654321'));
        $this->assertFalse($security->isValidPixKey('+5511987654321'));
        $this->assertFalse($security->isValidPixKey('pix@example.com'));
        $this->assertFalse($security->isValidPixKey('550e8400-e29b-11d4-a716-446655440000'));
    }
}
