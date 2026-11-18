<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

/**
 * Turns junit.xml into testreport.json.
 * Always writes a report, including when the JUnit file cannot be read.
 */

$root = dirname(__DIR__);
$junitPath = $root . DIRECTORY_SEPARATOR . 'junit.xml';
$outputPath = isset($argv[1]) && $argv[1] !== ''
    ? $argv[1]
    : $root . DIRECTORY_SEPARATOR . 'testreport.json';

try {
    $report = buildReport($root, $junitPath);
    writeJsonFile($outputPath, $report);
    appendStepSummary($report);
    fwrite(STDOUT, 'Wrote ' . $outputPath . ' (' . $report['status'] . ')' . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    $fallback = errorReport($root, 'Could not build the test report: ' . $error->getMessage());
    try {
        writeJsonFile($outputPath, $fallback);
        fwrite(STDOUT, 'Wrote ' . $outputPath . ' (error)' . PHP_EOL);
        exit(0);
    } catch (Throwable $writeError) {
        fwrite(STDERR, $writeError->getMessage() . PHP_EOL);
        fwrite(STDERR, json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit(1);
    }
}

/**
 * @return array<string, mixed>
 */
function buildReport(string $root, string $junitPath): array
{
    $meta = reportMeta($root);

    if (!is_file($junitPath)) {
        return errorReport($root, 'junit.xml was not found.', $meta);
    }

    $raw = file_get_contents($junitPath);
    if ($raw === false || trim($raw) === '') {
        return errorReport($root, 'junit.xml was empty or could not be read.', $meta);
    }

    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    if ($xml === false) {
        $detail = libxmlErrorText();
        return errorReport($root, 'junit.xml could not be parsed.' . ($detail !== '' ? ' ' . $detail : ''), $meta);
    }

    $cases = $xml->xpath('//testcase');
    if ($cases === false || $cases === null) {
        return errorReport($root, 'junit.xml could not be parsed.', $meta);
    }
    if ($cases === []) {
        return errorReport($root, 'junit.xml did not contain any test cases.', $meta);
    }

    $tests = [];
    $passed = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($cases as $case) {
        $parsed = parseCase($case, $root);
        $tests[] = $parsed;
        if ($parsed['result'] === 'failed') {
            $failed++;
        } elseif ($parsed['result'] === 'skipped') {
            $skipped++;
        } else {
            $passed++;
        }
    }

    $total = count($tests);
    $status = $failed > 0 ? 'failed' : 'passed';

    $report = $meta;
    $report['timestamp'] = gmdate('c');
    $report['status'] = $status;
    $report['summary'] = [
        'total' => $total,
        'passed' => $passed,
        'failed' => $failed,
        'skipped' => $skipped,
    ];
    $report['tests'] = $tests;

    return $report;
}

/**
 * @param array<string, string> $meta
 * @return array<string, mixed>
 */
function errorReport(string $root, string $message, ?array $meta = null): array
{
    $report = $meta ?? reportMeta($root);
    $report['timestamp'] = gmdate('c');
    $report['status'] = 'error';
    $report['summary'] = [
        'total' => 0,
        'passed' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];
    $report['tests'] = [];
    $report['errorMessage'] = $message;

    return $report;
}

/**
 * @return array<string, string>
 */
function reportMeta(string $root): array
{
    $sha = envOr('GITHUB_SHA', gitValue($root, 'rev-parse HEAD'));
    if ($sha === '') {
        $sha = 'unknown';
    }

    $branch = envOr('GITHUB_REF_NAME', gitValue($root, 'rev-parse --abbrev-ref HEAD'));
    if ($branch === '') {
        $branch = 'unknown';
    }

    $repository = envOr('GITHUB_REPOSITORY', basename($root));
    $runId = envOr('GITHUB_RUN_ID', 'local-' . time());
    $runNumber = envOr('GITHUB_RUN_NUMBER', '');
    $actor = envOr('GITHUB_ACTOR', '');
    $server = rtrim(envOr('GITHUB_SERVER_URL', ''), '/');
    $runUrl = '';
    if ($server !== '' && getenv('GITHUB_REPOSITORY') !== false && getenv('GITHUB_RUN_ID') !== false) {
        $runUrl = $server . '/' . getenv('GITHUB_REPOSITORY') . '/actions/runs/' . getenv('GITHUB_RUN_ID');
    }

    return [
        'repository' => $repository,
        'branch' => $branch,
        'commitSha' => $sha,
        'commitShort' => substr($sha, 0, 7),
        'runId' => $runId,
        'runNumber' => $runNumber,
        'runUrl' => $runUrl,
        'actor' => $actor,
    ];
}

function parseCase(SimpleXMLElement $case, string $root): array
{
    $name = (string) ($case['name'] ?? '');
    $class = (string) ($case['class'] ?? '');
    if ($class === '') {
        $class = (string) ($case['classname'] ?? '');
    }
    $seconds = (float) ($case['time'] ?? 0);
    $durationMs = (int) round($seconds * 1000);

    $result = 'passed';
    $failureMessage = null;

    if (isset($case->error) || isset($case->failure)) {
        $result = 'failed';
        $node = isset($case->error) ? $case->error : $case->failure;
        $failureMessage = failureText($node, $root);
    } elseif (isset($case->skipped)) {
        $result = 'skipped';
    }

    return [
        'name' => $name,
        'class' => $class,
        'result' => $result,
        'durationMs' => $durationMs,
        'failureMessage' => $failureMessage,
    ];
}

function failureText(SimpleXMLElement $node, string $root): string
{
    $body = trim((string) $node);
    $attribute = trim((string) ($node['message'] ?? ''));
    $text = $body !== '' ? $body : $attribute;
    if ($text === '') {
        $text = 'The test failed.';
    }

    $text = stripWorkspacePath($text, $root);
    if (mb_strlen($text) > 2000) {
        $text = mb_substr($text, 0, 2000);
    }

    return $text;
}

function stripWorkspacePath(string $text, string $root): string
{
    $prefixes = [];
    foreach ([$root, realpath($root) ?: ''] as $path) {
        if ($path === '') {
            continue;
        }
        $trimmed = rtrim($path, '/\\');
        $prefixes[] = $trimmed;
        $prefixes[] = str_replace('\\', '/', $trimmed);
        $prefixes[] = str_replace('/', '\\', $trimmed);
    }

    $prefixes = array_values(array_unique($prefixes));
    usort($prefixes, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

    foreach ($prefixes as $prefix) {
        $text = str_ireplace([$prefix . '\\', $prefix . '/', $prefix], '', $text);
    }

    return $text;
}

function envOr(string $name, string $fallback): string
{
    $value = getenv($name);
    if (is_string($value) && $value !== '') {
        return $value;
    }

    return $fallback;
}

function gitValue(string $root, string $args): string
{
    if (!function_exists('exec')) {
        return '';
    }

    $command = 'git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>&1';
    $output = [];
    $code = 1;
    exec($command, $output, $code);
    if ($code !== 0) {
        return '';
    }

    return trim(implode("\n", $output));
}

function libxmlErrorText(): string
{
    $errors = libxml_get_errors();
    libxml_clear_errors();
    if ($errors === []) {
        return '';
    }

    return trim($errors[0]->message);
}

/**
 * @param array<string, mixed> $report
 */
function writeJsonFile(string $path, array $report): void
{
    $json = json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the report directory.');
    }

    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Could not write the report file.');
    }
}

/**
 * @param array<string, mixed> $report
 */
function appendStepSummary(array $report): void
{
    $path = getenv('GITHUB_STEP_SUMMARY');
    if (!is_string($path) || $path === '') {
        return;
    }

    $summary = $report['summary'] ?? [];
    $status = (string) ($report['status'] ?? 'error');
    $lines = [
        '## Test report: ' . $status,
        '',
        'Repository: `' . markdownCell((string) ($report['repository'] ?? '')) . '`',
        'Branch: `' . markdownCell((string) ($report['branch'] ?? '')) . '`',
        'Commit: `' . markdownCell((string) ($report['commitShort'] ?? '')) . '`',
        'Run: `' . markdownCell((string) ($report['runId'] ?? '')) . '`',
        '',
        '| Total | Passed | Failed | Skipped |',
        '| --- | --- | --- | --- |',
        '| ' . (int) ($summary['total'] ?? 0)
            . ' | ' . (int) ($summary['passed'] ?? 0)
            . ' | ' . (int) ($summary['failed'] ?? 0)
            . ' | ' . (int) ($summary['skipped'] ?? 0) . ' |',
        '',
        '| Test | Result | Failure |',
        '| --- | --- | --- |',
    ];

    $tests = $report['tests'] ?? [];
    if (is_array($tests)) {
        foreach ($tests as $test) {
            if (!is_array($test)) {
                continue;
            }
            $failure = $test['failureMessage'] ?? '';
            $lines[] = '| ' . markdownCell((string) ($test['name'] ?? ''))
                . ' | ' . markdownCell((string) ($test['result'] ?? ''))
                . ' | ' . markdownCell(is_string($failure) ? $failure : '') . ' |';
        }
    }

    if ($status === 'error') {
        $lines[] = '';
        $lines[] = 'Error: ' . markdownCell((string) ($report['errorMessage'] ?? ''));
    }

    $lines[] = '';
    if (file_put_contents($path, implode("\n", $lines), FILE_APPEND) === false) {
        fwrite(STDERR, 'Could not append the GitHub step summary.' . PHP_EOL);
    }
}

function markdownCell(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], '<br>', $value);
    $value = str_replace('|', '\\|', $value);

    return $value;
}
