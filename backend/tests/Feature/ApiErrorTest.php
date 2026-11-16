<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ApiErrorTest extends TestCase
{
    public function test_unknown_api_path_returns_the_error_contract_without_an_accept_header(): void
    {
        $this->get('/api/v1/unknown')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['error' => 'Not Found', 'code' => 404]);
    }

    public function test_a_non_api_json_error_keeps_laravels_response_format(): void
    {
        $this->getJson('/unknown')
            ->assertNotFound()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('code');
    }

    public function test_unexpected_errors_do_not_expose_exception_details_even_in_debug_mode(): void
    {
        config(['app.debug' => true, 'logging.default' => 'null']);
        $this->app->bind(HealthController::class, fn () => throw new RuntimeException('Internal diagnostic'));

        $this->get('/api/v1/health')
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['error' => 'Internal Server Error', 'code' => 500]);

        $this->call('HEAD', '/api/v1/health')
            ->assertStatus(500)
            ->assertNoContent(500);
    }

    public function test_validation_errors_keep_field_messages(): void
    {
        $errors = ['page' => ['The page field must be an integer.']];
        $this->app->bind(HealthController::class, fn () => throw ValidationException::withMessages($errors));

        $this->get('/api/v1/health')
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['error' => 'Validation failed', 'code' => 422, 'errors' => $errors]);
    }
}
