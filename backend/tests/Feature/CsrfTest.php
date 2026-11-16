<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class CsrfTest extends TestCase
{
    public function test_csrf_cookie_has_the_expected_local_attributes(): void
    {
        $response = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());

        $this->assertFalse($cookies['XSRF-TOKEN']->isHttpOnly());
        $this->assertTrue($cookies[config('session.cookie')]->isHttpOnly());
        $this->assertSame('lax', $cookies[config('session.cookie')]->getSameSite());
    }

    public function test_mutating_auth_requests_require_csrf_outside_the_test_bypass(): void
    {
        $this->app->instance('env', 'local');

        foreach (['login', 'register', 'logout'] as $path) {
            $this->postJson('/api/v1/'.$path, [])
                ->assertStatus(419)->assertExactJson(['error' => 'Session expired', 'code' => 419]);
        }
    }

    public function test_a_valid_csrf_token_reaches_request_validation(): void
    {
        $this->app->instance('env', 'local');
        $this->withSession(['_token' => 'test-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/api/v1/login', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_production_cookies_default_to_secure(): void
    {
        $previous = $_SERVER['APP_ENV'];
        $_SERVER['APP_ENV'] = 'production';

        try {
            $session = require config_path('session.php');
            $this->assertTrue($session['secure']);
        } finally {
            $_SERVER['APP_ENV'] = $previous;
        }
    }
}
