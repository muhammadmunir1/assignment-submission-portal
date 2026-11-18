<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

namespace App;

/**
 * Validates a submission, then stores it. Invalid input is never written.
 */
final class SubmissionService
{
    public function __construct(
        private readonly Validator $validator,
        private readonly Store $store,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: array<string, string>, submission: ?array<string, string>}
     */
    public function submit(array $input): array
    {
        $errors = $this->validator->validate($input);
        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => $errors,
                'submission' => null,
            ];
        }

        $fields = $this->validator->fields($input);
        $submission = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $fields['name'],
            'regNo' => $fields['regNo'],
            'title' => $fields['title'],
            'url' => $fields['url'],
            'submittedAt' => gmdate('c'),
        ];

        $this->store->add($submission);

        return [
            'ok' => true,
            'errors' => [],
            'submission' => $submission,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->store->all();
    }
}
