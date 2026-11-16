<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class HealthTest extends TestCase
{
    public function test_public_health_endpoint_returns_json(): void
    {
        $this->get('/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_head_health_returns_the_status_and_headers_without_a_body(): void
    {
        $this->call('HEAD', '/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertNoContent(200);
    }

    public function test_health_endpoint_rejects_an_unsupported_method(): void
    {
        $this->post('/api/v1/health')
            ->assertStatus(405)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Allow', 'GET, HEAD')
            ->assertExactJson(['error' => 'Method Not Allowed', 'code' => 405]);
    }

    public function test_test_environment_is_isolated_from_development(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame(getenv('TEST_DB_CONNECTION') ?: 'sqlite', config('database.default'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        if (config('database.default') === 'pgsql') {
            $this->assertStringEndsWith('_test', config('database.connections.pgsql.database'));
        } else {
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));
            $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'));
            $this->assertEmpty(config('database.connections.pgsql.password'));
        }
        $this->assertSame(32, strlen(base64_decode(substr(config('app.key'), 7))));
    }
}
