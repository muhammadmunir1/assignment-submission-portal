<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

namespace App\Tests;

use App\Api;
use App\Store;
use App\SubmissionService;
use App\Validator;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    private string $path;

    private Api $api;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asp');
        self::assertNotFalse($path, 'tempnam should create a private storage file for this test.');
        $this->path = $path;
        $this->api = new Api(new SubmissionService(new Validator(), new Store($this->path)));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_file($this->path . '.lock')) {
            unlink($this->path . '.lock');
        }
    }

    public function test_valid_post_returns_201(): void
    {
        [$status, $payload] = $this->api->handle('POST', $this->validJson(), 'application/json');

        self::assertSame(201, $status, 'A valid POST should return 201.');
        self::assertTrue($payload['ok'] ?? false, 'A valid POST body should report ok true.');
        self::assertSame('Hina Farooq', $payload['submission']['name'] ?? null, 'The 201 response should include the saved submission.');
    }

    public function test_invalid_post_returns_422_with_field_errors(): void
    {
        $body = json_encode($this->validFields(['regNo' => '']), JSON_THROW_ON_ERROR);
        [$status, $payload] = $this->api->handle('POST', $body, 'application/json');

        self::assertSame(422, $status, 'Invalid input should return 422.');
        self::assertFalse($payload['ok'] ?? true, 'A 422 response should report ok false.');
        self::assertSame('Validation failed', $payload['message'] ?? null, 'A 422 response should say validation failed.');
        self::assertSame(
            'Registration number is required',
            $payload['errors']['regNo'] ?? null,
            'The 422 errors object should contain the registration-number message.'
        );
    }

    public function test_malformed_json_returns_400(): void
    {
        [$status, $payload] = $this->api->handle('POST', '{', 'application/json');

        self::assertSame(400, $status, 'Malformed JSON should return 400.');
        self::assertFalse($payload['ok'] ?? true, 'A 400 response should report ok false.');
    }

    public function test_get_returns_the_saved_list(): void
    {
        $this->api->handle('POST', $this->validJson(), 'application/json');

        [$status, $payload] = $this->api->handle('GET', '', '');

        self::assertSame(200, $status, 'GET should return 200.');
        self::assertTrue($payload['ok'] ?? false, 'GET should report ok true.');
        self::assertSame(1, $payload['count'] ?? null, 'GET count should match the number of saved submissions.');
        self::assertCount(1, $payload['submissions'] ?? [], 'GET should return the saved submission.');
        self::assertSame('Hina Farooq', $payload['submissions'][0]['name'] ?? null, 'GET should return the name that was posted.');
    }

    public function test_delete_returns_405(): void
    {
        [$status, $payload, $headers] = $this->api->handle('DELETE', '', '');

        self::assertSame(405, $status, 'DELETE should return 405.');
        self::assertSame('GET, POST', $headers['Allow'] ?? null, 'A 405 response should advertise Allow: GET, POST.');
        self::assertFalse($payload['ok'] ?? true, 'A 405 response should report ok false.');
    }

    private function validJson(): string
    {
        return json_encode($this->validFields(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function validFields(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Hina Farooq',
            'regNo' => 'FA22-BCS-014',
            'title' => 'Lab Notes',
            'url' => 'https://example.com/lab',
        ], $overrides);
    }
}
