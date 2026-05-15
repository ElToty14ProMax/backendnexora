<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_health_endpoint_returns_backend_marker(): void
    {
        $response = $this->get('/health');

        $response->assertOk()
            ->assertJson(['message' => 'nexora-backend-laravel']);
    }
}
