<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

namespace App;

use JsonException;

/**
 * HTTP adapter with no globals. The front controller passes in the request.
 */
final class Api
{
    private const MAX_BODY_BYTES = 16384;

    public function __construct(private readonly SubmissionService $service)
    {
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
     */
    public function handle(string $method, string $rawBody, string $contentType): array
    {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return [413, ['ok' => false, 'message' => 'Request body exceeds 16KB'], []];
        }

        $method = strtoupper($method);

        if ($method === 'GET') {
            $submissions = $this->service->list();

            return [200, [
                'ok' => true,
                'count' => count($submissions),
                'submissions' => $submissions,
            ], []];
        }

        if ($method === 'POST') {
            return $this->create($rawBody, $contentType);
        }

        return [405, ['ok' => false, 'message' => 'Method not allowed'], ['Allow' => 'GET, POST']];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function create(string $rawBody, string $contentType): array
    {
        $input = $this->parseBody($rawBody, $contentType);
        if ($input === null) {
            return [400, ['ok' => false, 'message' => 'Malformed JSON'], []];
        }

        $result = $this->service->submit($input);
        if ($result['ok'] !== true) {
            return [422, [
                'ok' => false,
                'message' => 'Validation failed',
                'errors' => $result['errors'],
            ], []];
        }

        return [201, [
            'ok' => true,
            'submission' => $result['submission'],
        ], []];
    }

    /**
     * JSON objects, or form bodies when the content type says so.
     * Null means the JSON could not be parsed.
     *
     * @return array<string, mixed>|null
     */
    private function parseBody(string $rawBody, string $contentType): ?array
    {
        if (str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            $parsed = [];
            parse_str($rawBody, $parsed);

            return $parsed;
        }

        if (trim($rawBody) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
