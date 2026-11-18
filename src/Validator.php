<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

namespace App;

/**
 * Checks a submission and returns field => message.
 * An empty array means the input is valid.
 */
final class Validator
{
    private const URL_MESSAGE = 'Enter a valid URL starting with http:// or https://';

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validate(array $input): array
    {
        $fields = $this->fields($input);
        $errors = [];

        if ($fields['name'] === '') {
            $errors['name'] = 'Full name is required';
        } elseif (mb_strlen($fields['name']) < 2 || mb_strlen($fields['name']) > 100) {
            $errors['name'] = 'Name must be between 2 and 100 characters';
        }

        $regNo = $fields['regNo'];

        // DEMO SWITCH: this is the only place a missing registration number is rejected.
        // Comment out this if-block to accept a blank regNo during the demo.
        if ($regNo === '') {
            $errors['regNo'] = 'Registration number is required';
        }

        if ($regNo !== '' && !$this->registrationNumberIsValid($regNo)) {
            $errors['regNo'] = 'Registration number may only contain letters, numbers, dashes and slashes';
        }

        if ($fields['title'] === '') {
            $errors['title'] = 'Assignment title is required';
        } elseif (mb_strlen($fields['title']) < 3 || mb_strlen($fields['title']) > 150) {
            $errors['title'] = 'Title must be between 3 and 150 characters';
        }

        if ($fields['url'] === '') {
            $errors['url'] = 'Submission URL is required';
        } elseif (!$this->urlIsValid($fields['url'])) {
            $errors['url'] = self::URL_MESSAGE;
        }

        return $errors;
    }

    /**
     * Trimmed field values, safe to store after validate() returns no errors.
     *
     * @param array<string, mixed> $input
     * @return array{name: string, regNo: string, title: string, url: string}
     */
    public function fields(array $input): array
    {
        return [
            'name' => $this->clean($input['name'] ?? null),
            'regNo' => $this->clean($input['regNo'] ?? null),
            'title' => $this->clean($input['title'] ?? null),
            'url' => $this->clean($input['url'] ?? null),
        ];
    }

    private function clean(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        $stripped = preg_replace('/\p{C}+/u', '', $value);

        return trim($stripped ?? '');
    }

    private function registrationNumberIsValid(string $regNo): bool
    {
        $length = mb_strlen($regNo);
        if ($length < 3 || $length > 30) {
            return false;
        }

        return preg_match('/^[\p{L}\p{N}\/-]+$/u', $regNo) === 1;
    }

    private function urlIsValid(string $url): bool
    {
        if (mb_strlen($url) > 2048) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        return str_contains($host, '.') || strcasecmp($host, 'localhost') === 0;
    }
}
