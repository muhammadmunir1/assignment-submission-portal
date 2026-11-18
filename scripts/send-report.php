<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

/**
 * POSTs testreport.json to the receiver.
 * Five attempts, three seconds apart, ten-second timeout.
 */

$root = dirname(__DIR__);
$reportPath = $root . DIRECTORY_SEPARATOR . 'testreport.json';
$url = '';
if (isset($argv[1]) && $argv[1] !== '') {
    $url = $argv[1];
} else {
    $fromEnv = getenv('REPORT_URL');
    $url = is_string($fromEnv) ? $fromEnv : '';
}

if (trim($url) === '') {
    fwrite(STDERR, 'warning: REPORT_URL is empty, so the report was not sent.' . PHP_EOL);
    exit(0);
}

if (!extension_loaded('curl')) {
    failSend('PHP curl is not enabled, so the report could not be sent.');
}

if (!is_file($reportPath)) {
    failSend('testreport.json was not found at ' . $reportPath . '.');
}

$body = file_get_contents($reportPath);
if ($body === false || trim($body) === '') {
    failSend('testreport.json could not be read.');
}

$token = getenv('REPORT_TOKEN');
if (!is_string($token) || $token === '') {
    $token = dotenvValue($root . DIRECTORY_SEPARATOR . 'receiver' . DIRECTORY_SEPARATOR . '.env', 'REPORT_TOKEN');
    if ($token !== '') {
        fwrite(STDOUT, 'Using REPORT_TOKEN from receiver/.env' . PHP_EOL);
    }
}

$attempts = 5;
$lastReason = 'The receiver did not accept the report.';

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    $result = postReport($url, $body, $token);
    $line = 'Attempt ' . $attempt . '/' . $attempts . ': POST ' . $url . ' -> ' . $result['detail'];
    fwrite(STDOUT, $line . PHP_EOL);

    if ($result['ok'] === true) {
        exit(0);
    }

    $lastReason = $result['detail'];
    if ($attempt < $attempts) {
        sleep(3);
    }
}

failSend($lastReason);

/**
 * @return array{ok: bool, detail: string}
 */
function postReport(string $url, string $body, string $token): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'detail' => 'curl could not be started.'];
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Report-Token: ' . $token,
            'ngrok-skip-browser-warning: true',
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = curl_exec($handle);
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($errno !== 0) {
        return ['ok' => false, 'detail' => 'connection failed: ' . $error];
    }

    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'detail' => 'HTTP ' . $status];
    }

    $snippet = is_string($response) ? trim(preg_replace('/\s+/', ' ', $response) ?? '') : '';
    if (strlen($snippet) > 300) {
        $snippet = substr($snippet, 0, 300);
    }

    $detail = 'HTTP ' . $status;
    if ($snippet !== '') {
        $detail .= ' ' . $snippet;
    }

    return ['ok' => false, 'detail' => $detail];
}

function dotenvValue(string $path, string $key): string
{
    if (!is_file($path)) {
        return '';
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return '';
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $name = trim(substr($line, 0, $eq));
        if ($name !== $key) {
            continue;
        }
        $value = trim(substr($line, $eq + 1));
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    return '';
}

function failSend(string $reason): void
{
    $flat = str_replace(["\r", "\n"], ' ', $reason);
    fwrite(STDOUT, '::warning::' . $flat . PHP_EOL);
    fwrite(STDERR, $reason . PHP_EOL);
    exit(1);
}
