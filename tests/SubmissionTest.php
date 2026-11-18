<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

namespace App\Tests;

use App\Store;
use App\SubmissionService;
use App\Validator;
use PHPUnit\Framework\TestCase;

final class SubmissionTest extends TestCase
{
    private string $path;

    private SubmissionService $service;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asp');
        self::assertNotFalse($path, 'tempnam should create a private storage file for this test.');
        $this->path = $path;
        $this->service = new SubmissionService(new Validator(), new Store($this->path));
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

    public function test_complete_details_and_valid_url_is_saved_and_listed(): void
    {
        $result = $this->service->submit($this->validInput());

        self::assertTrue($result['ok'], 'A complete submission with a valid URL should be accepted.');
        self::assertSame([], $result['errors'], 'A valid submission should not produce validation errors.');
        self::assertIsArray($result['submission'], 'A valid submission should return the saved row.');

        $listed = $this->service->list();
        self::assertCount(1, $listed, 'Saving one valid submission should leave exactly one row in the list.');
        self::assertSame($result['submission'], $listed[0], 'The listed row should be the submission that was just saved.');
    }

    public function test_missing_registration_number_is_rejected_with_validation_message(): void
    {
        $result = $this->service->submit($this->validInput(['regNo' => '']));

        self::assertFalse($result['ok'], 'A submission without a registration number should be rejected.');
        self::assertSame(
            'Registration number is required',
            $result['errors']['regNo'] ?? null,
            'A blank registration number must be rejected with "Registration number is required".'
        );
        self::assertSame([], $this->service->list(), 'A rejected submission must not be written to storage.');
    }

    public function test_invalid_submission_url_is_rejected_and_not_saved(): void
    {
        $result = $this->service->submit($this->validInput(['url' => 'not-a-url']));

        self::assertFalse($result['ok'], 'A submission with an invalid URL should be rejected.');
        self::assertSame(
            'Enter a valid URL starting with http:// or https://',
            $result['errors']['url'] ?? null,
            'An invalid URL should explain that http:// or https:// is required.'
        );
        self::assertSame([], $this->service->list(), 'An invalid URL must not be saved.');
    }

    public function test_missing_name_is_rejected(): void
    {
        $result = $this->service->submit($this->validInput(['name' => '']));

        self::assertFalse($result['ok'], 'A submission without a name should be rejected.');
        self::assertSame(
            'Full name is required',
            $result['errors']['name'] ?? null,
            'A blank name must be rejected with "Full name is required".'
        );
        self::assertSame([], $this->service->list(), 'A missing name must not be saved.');
    }

    public function test_missing_title_is_rejected(): void
    {
        $result = $this->service->submit($this->validInput(['title' => '']));

        self::assertFalse($result['ok'], 'A submission without a title should be rejected.');
        self::assertSame(
            'Assignment title is required',
            $result['errors']['title'] ?? null,
            'A blank title must be rejected with "Assignment title is required".'
        );
        self::assertSame([], $this->service->list(), 'A missing title must not be saved.');
    }

    public function test_missing_url_is_rejected(): void
    {
        $result = $this->service->submit($this->validInput(['url' => '']));

        self::assertFalse($result['ok'], 'A submission without a URL should be rejected.');
        self::assertSame(
            'Submission URL is required',
            $result['errors']['url'] ?? null,
            'A blank URL must be rejected with "Submission URL is required".'
        );
        self::assertSame([], $this->service->list(), 'A missing URL must not be saved.');
    }

    public function test_whitespace_only_values_are_rejected(): void
    {
        $result = $this->service->submit([
            'name' => '   ',
            'regNo' => " \t ",
            'title' => " \n ",
            'url' => '  ',
        ]);

        self::assertFalse($result['ok'], 'Whitespace-only fields should be rejected.');
        self::assertSame('Full name is required', $result['errors']['name'] ?? null, 'Whitespace is not a name.');
        self::assertSame('Registration number is required', $result['errors']['regNo'] ?? null, 'Whitespace is not a registration number.');
        self::assertSame('Assignment title is required', $result['errors']['title'] ?? null, 'Whitespace is not a title.');
        self::assertSame('Submission URL is required', $result['errors']['url'] ?? null, 'Whitespace is not a URL.');
        self::assertSame([], $this->service->list(), 'Whitespace-only input must not be saved.');
    }

    public function test_javascript_url_is_rejected(): void
    {
        $result = $this->service->submit($this->validInput(['url' => 'javascript:alert(1)']));

        self::assertFalse($result['ok'], 'A javascript: URL should be rejected.');
        self::assertSame(
            'Enter a valid URL starting with http:// or https://',
            $result['errors']['url'] ?? null,
            'A javascript: URL is not an http or https submission link.'
        );
        self::assertSame([], $this->service->list(), 'A javascript: URL must not be saved.');
    }

    public function test_ftp_url_is_rejected(): void
    {
        $result = $this->service->submit($this->validInput(['url' => 'ftp://files.example.com/work']));

        self::assertFalse($result['ok'], 'An ftp: URL should be rejected.');
        self::assertSame(
            'Enter a valid URL starting with http:// or https://',
            $result['errors']['url'] ?? null,
            'An ftp: URL is not an http or https submission link.'
        );
        self::assertSame([], $this->service->list(), 'An ftp: URL must not be saved.');
    }

    public function test_url_without_host_is_rejected(): void
    {
        $result = $this->service->submit($this->validInput(['url' => 'http://']));

        self::assertFalse($result['ok'], 'A URL without a host should be rejected.');
        self::assertSame(
            'Enter a valid URL starting with http:// or https://',
            $result['errors']['url'] ?? null,
            'A URL needs a host before it can be saved.'
        );
        self::assertSame([], $this->service->list(), 'A URL without a host must not be saved.');
    }

    public function test_values_are_trimmed_before_they_are_saved(): void
    {
        $result = $this->service->submit($this->validInput([
            'name' => '  Hina Farooq  ',
            'regNo' => '  FA22-BCS-014  ',
            'title' => '  Lab Notes  ',
            'url' => '  https://example.com/lab  ',
        ]));

        self::assertTrue($result['ok'], 'Padded but otherwise valid values should be accepted.');
        self::assertSame('Hina Farooq', $result['submission']['name'] ?? null, 'The saved name should have surrounding spaces removed.');
        self::assertSame('FA22-BCS-014', $result['submission']['regNo'] ?? null, 'The saved registration number should have surrounding spaces removed.');
        self::assertSame('Lab Notes', $result['submission']['title'] ?? null, 'The saved title should have surrounding spaces removed.');
        self::assertSame('https://example.com/lab', $result['submission']['url'] ?? null, 'The saved URL should have surrounding spaces removed.');
    }

    public function test_multiple_submissions_are_listed_newest_first(): void
    {
        $this->service->submit($this->validInput(['name' => 'First Student', 'regNo' => 'FA19-BSE-014']));
        $this->service->submit($this->validInput(['name' => 'Second Student', 'regNo' => 'SP21-BCS-088']));

        $listed = $this->service->list();

        self::assertCount(2, $listed, 'Two accepted submissions should both be listed.');
        self::assertSame('Second Student', $listed[0]['name'] ?? null, 'The newest submission should be listed first.');
        self::assertSame('First Student', $listed[1]['name'] ?? null, 'The older submission should follow the newest one.');
    }

    public function test_saved_rows_are_still_there_for_a_new_store(): void
    {
        $this->service->submit($this->validInput());

        $reopened = (new Store($this->path))->all();

        self::assertCount(1, $reopened, 'A new Store pointed at the same file should still see the saved row.');
        self::assertSame('Hina Farooq', $reopened[0]['name'] ?? null, 'The reopened store should return the name that was saved.');
    }

    public function test_corrupt_storage_file_is_treated_as_empty(): void
    {
        file_put_contents($this->path, '{not valid json');

        $listed = $this->service->list();

        self::assertSame([], $listed, 'A corrupt storage file should be read as an empty list and must not crash.');
    }

    public function test_overlong_inputs_are_rejected(): void
    {
        $tooLongName = $this->service->submit($this->validInput(['name' => str_repeat('A', 101)]));
        self::assertFalse($tooLongName['ok'], 'A name longer than 100 characters should be rejected.');
        self::assertSame(
            'Name must be between 2 and 100 characters',
            $tooLongName['errors']['name'] ?? null,
            'An overlong name should report the 2 to 100 character limit.'
        );

        $tooLongTitle = $this->service->submit($this->validInput(['title' => str_repeat('T', 151)]));
        self::assertFalse($tooLongTitle['ok'], 'A title longer than 150 characters should be rejected.');
        self::assertSame(
            'Title must be between 3 and 150 characters',
            $tooLongTitle['errors']['title'] ?? null,
            'An overlong title should report the 3 to 150 character limit.'
        );

        $tooLongRegNo = $this->service->submit($this->validInput(['regNo' => str_repeat('A', 31)]));
        self::assertFalse($tooLongRegNo['ok'], 'A registration number longer than 30 characters should be rejected.');
        self::assertSame(
            'Registration number may only contain letters, numbers, dashes and slashes',
            $tooLongRegNo['errors']['regNo'] ?? null,
            'An overlong registration number should be rejected.'
        );

        $tooLongUrl = $this->service->submit($this->validInput([
            'url' => 'https://example.com/' . str_repeat('a', 2048),
        ]));
        self::assertFalse($tooLongUrl['ok'], 'A URL longer than 2048 characters should be rejected.');
        self::assertSame(
            'Enter a valid URL starting with http:// or https://',
            $tooLongUrl['errors']['url'] ?? null,
            'An overlong URL should be rejected.'
        );

        self::assertSame([], $this->service->list(), 'None of the overlong submissions should be saved.');
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Hina Farooq',
            'regNo' => 'FA22-BCS-014',
            'title' => 'Lab Notes',
            'url' => 'https://example.com/lab',
        ], $overrides);
    }
}
