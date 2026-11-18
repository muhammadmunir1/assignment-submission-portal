<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

/**
 * Router for the built-in server: php -S localhost:4000 receiver/receiver.php
 * Stores the latest report and a history copy that is never overwritten.
 */

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Start the receiver with: php -S localhost:4000 receiver/receiver.php\n");
    exit(1);
}

$root = dirname(__DIR__);
$dotenv = loadDotEnv(__DIR__ . DIRECTORY_SEPARATOR . '.env');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';

try {
    if ($path === '/health' && $method === 'GET') {
        logLine($method, $path, 'OK');
        jsonResponse(200, ['ok' => true]);
    }

    if ($path === '/' && $method === 'GET') {
        logLine($method, $path, 'DASHBOARD');
        dashboard($root);
    }

    if ($path === '/report') {
        if ($method !== 'POST') {
            logLine($method, $path, 'REJECTED method');
            header('Allow: POST');
            jsonResponse(405, ['ok' => false, 'message' => 'Method not allowed']);
        }
        receiveReport($root, $dotenv);
    }

    if (str_starts_with($path, '/reports/') && $method === 'GET') {
        serveHistory($root, substr($path, strlen('/reports/')));
    }

    logLine($method, $path, 'NOT FOUND');
    jsonResponse(404, ['ok' => false, 'message' => 'Not found']);
} catch (Throwable $error) {
    logLine($method, $path, 'ERROR ' . $error->getMessage());
    jsonResponse(500, ['ok' => false, 'message' => 'The receiver could not finish the request.']);
}

/**
 * @param array<string, string> $dotenv
 */
function receiveReport(string $root, array $dotenv): void
{
    $token = setting('REPORT_TOKEN', $dotenv);
    if ($token === '') {
        logLine('POST', '/report', 'WARNING token is empty, accepting the request');
    } else {
        $provided = (string) ($_SERVER['HTTP_X_REPORT_TOKEN'] ?? '');
        if (!hash_equals($token, $provided)) {
            logLine('POST', '/report', 'REJECTED unauthorized');
            jsonResponse(401, ['ok' => false, 'message' => 'Invalid report token']);
        }
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }
    if (strlen($raw) > 1024 * 1024) {
        logLine('POST', '/report', 'REJECTED too large');
        jsonResponse(413, ['ok' => false, 'message' => 'Report body exceeds 1 MB']);
    }

    try {
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        logLine('POST', '/report', 'REJECTED invalid json');
        jsonResponse(400, ['ok' => false, 'message' => 'Invalid JSON']);
    }

    if (!is_array($payload)) {
        logLine('POST', '/report', 'REJECTED invalid json');
        jsonResponse(400, ['ok' => false, 'message' => 'Invalid JSON']);
    }

    $missing = missingKeys($payload);
    if ($missing !== []) {
        logLine('POST', '/report', 'REJECTED missing ' . implode(', ', $missing));
        jsonResponse(400, [
            'ok' => false,
            'message' => 'Missing required keys: ' . implode(', ', $missing),
            'missing' => $missing,
        ]);
    }

    $payload['receivedAt'] = gmdate('c');
    $status = preg_replace('/[^a-z]/', '', strtolower((string) $payload['status'])) ?: 'unknown';
    $sha = preg_replace('/[^a-fA-F0-9]/', '', (string) ($payload['commitShort'] ?? $payload['commitSha'] ?? ''));
    $sha7 = substr($sha !== '' ? $sha : 'unknown', 0, 7);
    if ($sha7 === '') {
        $sha7 = 'unknown';
    }

    $reportsDir = $root . DIRECTORY_SEPARATOR . 'reports';
    $stamp = gmdate('Ymd\THis\Z');
    $historyName = $stamp . '_' . $sha7 . '_' . $status . '.json';
    $suffix = 2;
    while (is_file($reportsDir . DIRECTORY_SEPARATOR . $historyName)) {
        $historyName = $stamp . '_' . $sha7 . '_' . $status . '-' . $suffix . '.json';
        $suffix++;
    }

    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n";

    $historyPath = $reportsDir . DIRECTORY_SEPARATOR . $historyName;
    $latestPath = $root . DIRECTORY_SEPARATOR . 'testreport.json';
    writeAtomic($historyPath, $json);
    writeAtomic($latestPath, $json);

    $passed = (int) ($payload['summary']['passed'] ?? 0);
    $total = (int) ($payload['summary']['total'] ?? 0);
    logLine('POST', '/report', 'REPORT SAVED ' . $status . ' ' . $passed . '/' . $total . ' ' . $sha7);

    jsonResponse(200, [
        'saved' => true,
        'file' => 'reports/' . $historyName,
        'status' => $payload['status'],
    ]);
}

/**
 * @param array<string, mixed> $payload
 * @return list<string>
 */
function missingKeys(array $payload): array
{
    $missing = [];
    foreach (['repository', 'branch', 'commitSha', 'runId', 'timestamp', 'status'] as $key) {
        if (!isset($payload[$key]) || !is_string($payload[$key]) || $payload[$key] === '') {
            $missing[] = $key;
        }
    }

    if (!isset($payload['summary']) || !is_array($payload['summary'])) {
        $missing[] = 'summary';
        $missing[] = 'summary.total';
        $missing[] = 'summary.passed';
        $missing[] = 'summary.failed';
    } else {
        foreach (['total', 'passed', 'failed'] as $key) {
            if (!array_key_exists($key, $payload['summary']) || !is_numeric($payload['summary'][$key])) {
                $missing[] = 'summary.' . $key;
            }
        }
    }

    if (!isset($payload['tests']) || !is_array($payload['tests'])) {
        $missing[] = 'tests';
    }

    return $missing;
}

function serveHistory(string $root, string $name): void
{
    $name = rawurldecode($name);
    if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
        logLine('GET', '/reports/' . $name, 'REJECTED path');
        jsonResponse(404, ['ok' => false, 'message' => 'Not found']);
    }
    if (!preg_match('/^[A-Za-z0-9._-]+\\.json$/', $name)) {
        logLine('GET', '/reports/' . $name, 'REJECTED path');
        jsonResponse(404, ['ok' => false, 'message' => 'Not found']);
    }

    $path = $root . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        logLine('GET', '/reports/' . $name, 'NOT FOUND');
        jsonResponse(404, ['ok' => false, 'message' => 'Not found']);
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        logLine('GET', '/reports/' . $name, 'NOT FOUND');
        jsonResponse(404, ['ok' => false, 'message' => 'Not found']);
    }

    logLine('GET', '/reports/' . $name, 'HISTORY');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $raw;
    exit;
}

function dashboard(string $root): void
{
    $latestPath = $root . DIRECTORY_SEPARATOR . 'testreport.json';
    $report = null;
    if (is_file($latestPath)) {
        $raw = file_get_contents($latestPath);
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $report = $decoded;
                }
            } catch (JsonException) {
                $report = null;
            }
        }
    }

    $history = historyFiles($root);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo renderDashboard($report, $history);
    exit;
}

/**
 * @return list<string>
 */
function historyFiles(string $root): array
{
    $dir = $root . DIRECTORY_SEPARATOR . 'reports';
    if (!is_dir($dir)) {
        return [];
    }

    $paths = glob($dir . DIRECTORY_SEPARATOR . '*.json');
    if ($paths === false) {
        return [];
    }

    usort($paths, static function (string $left, string $right): int {
        return filemtime($right) <=> filemtime($left);
    });

    $names = [];
    foreach (array_slice($paths, 0, 10) as $path) {
        $names[] = basename($path);
    }

    return $names;
}

/**
 * @param array<string, mixed>|null $report
 * @param list<string> $history
 */
function renderDashboard(?array $report, array $history): string
{
    $status = is_array($report) ? strtolower((string) ($report['status'] ?? '')) : '';
    $badgeClass = in_array($status, ['passed', 'failed', 'error'], true) ? $status : 'waiting';
    $badgeText = $status !== '' ? strtoupper($status) : 'WAITING';
    $summary = is_array($report) && is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $tests = is_array($report) && is_array($report['tests'] ?? null) ? $report['tests'] : [];

    $rows = '';
    if ($tests === []) {
        $rows = '<tr><td colspan="4">No tests in this report.</td></tr>';
    }
    foreach ($tests as $test) {
        if (!is_array($test)) {
            continue;
        }
        $result = strtolower((string) ($test['result'] ?? ''));
        if (!in_array($result, ['passed', 'failed', 'skipped'], true)) {
            $result = 'unknown';
        }
        $failure = $test['failureMessage'] ?? null;
        $failureHtml = is_string($failure) && $failure !== ''
            ? '<pre>' . e($failure) . '</pre>'
            : '';
        $rows .= '<tr class="result-' . e($result) . '">'
            . '<td data-label="Test">' . e((string) ($test['name'] ?? '')) . '<span class="class-name">' . e((string) ($test['class'] ?? '')) . '</span></td>'
            . '<td data-label="Result">' . e((string) ($test['result'] ?? '')) . '</td>'
            . '<td data-label="ms">' . e((string) ($test['durationMs'] ?? '')) . '</td>'
            . '<td data-label="Failure">' . $failureHtml . '</td>'
            . '</tr>';
    }

    $historyHtml = '';
    if ($history === []) {
        $historyHtml = '<p class="muted">No earlier reports.</p>';
    } else {
        $historyHtml = '<ul>';
        foreach ($history as $name) {
            $historyHtml .= '<li><a href="reports/' . e($name) . '">' . e($name) . '</a></li>';
        }
        $historyHtml .= '</ul>';
    }

    $valueOf = static function (string $key) use ($report): string {
        if (!is_array($report) || !isset($report[$key])) {
            return '';
        }

        return is_scalar($report[$key]) ? (string) $report[$key] : '';
    };
    $run = $valueOf('runId');
    $runNumber = $valueOf('runNumber');
    if ($runNumber !== '') {
        $run = trim($run . ' #' . $runNumber);
    }
    $received = $valueOf('receivedAt');
    if ($received === '') {
        $received = $valueOf('timestamp');
    }
    $facts = '';
    foreach ([
        'Repository' => $valueOf('repository'),
        'Branch' => $valueOf('branch'),
        'Commit' => $valueOf('commitSha'),
        'Run' => $run,
        'Received' => $received,
    ] as $label => $value) {
        $facts .= '<div><dt>' . e($label) . '</dt><dd>' . e($value !== '' ? $value : '—') . '</dd></div>';
    }

    $errorNote = '';
    if (is_array($report) && isset($report['errorMessage']) && is_string($report['errorMessage']) && $report['errorMessage'] !== '') {
        $errorNote = '<p class="error-note">' . e($report['errorMessage']) . '</p>';
    }

    $empty = $report === null
        ? '<p class="muted">No report has been received yet.</p>'
        : '';

    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="3">
  <meta name="color-scheme" content="light">
  <title>Test report</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
  <style>
    :root { --bg:#F5F7FA; --surface:#FFFFFF; --border:#E3E8EF; --text:#0F1B2D; --muted:#5B6B7F; --primary:#12305C; --danger:#B42318; --danger-bg:#FEF3F2; --success:#067647; --success-bg:#ECFDF3; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text); font-family: Inter, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.5; }
    h1, h2, p { margin: 0; }
    h1 { font-size: 28px; line-height: 1.2; font-weight: 600; }
    h2 { font-size: 20px; font-weight: 600; margin-bottom: 16px; }
    a { color: #1F6FEB; font-weight: 600; }
    a:focus-visible, button:focus-visible { outline: 2px solid #1F6FEB; outline-offset: 2px; }
    .site-header { background: var(--primary); color: #fff; }
    .shell { width: min(1120px, calc(100% - 32px)); margin: 0 auto; }
    .header-inner { min-height: 72px; padding: 16px 0; }
    .wordmark { font-size: 16px; font-weight: 600; }
    .brand-sub { margin-top: 4px; font-size: 12px; color: #D6DEE8; }
    main { padding: 32px 0 48px; }
    .intro { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 24px; }
    .badge { display: inline-flex; align-items: center; height: 32px; padding: 0 12px; border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; }
    .badge.passed { background: var(--success-bg); color: var(--success); }
    .badge.failed, .badge.error { background: var(--danger-bg); color: var(--danger); }
    .badge.waiting { background: var(--surface); color: var(--muted); border: 1px solid var(--border); }
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 1px 2px rgba(16,24,40,.06); padding: 24px; margin-bottom: 24px; }
    .facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin: 0; }
    .facts dt { font-size: 12px; font-weight: 600; color: var(--muted); }
    .facts dd { margin: 4px 0 0; font-size: 14px; overflow-wrap: anywhere; }
    .counts { display: flex; gap: 16px; flex-wrap: wrap; }
    .count { min-width: 96px; }
    .count strong { display: block; font-size: 28px; line-height: 1.2; }
    .count span { font-size: 12px; color: var(--muted); }
    table { width: 100%; border-collapse: collapse; font-size: 14px; table-layout: fixed; }
    th { text-align: left; font-size: 12px; color: var(--muted); padding: 8px 12px 8px 0; border-bottom: 1px solid var(--border); }
    td { vertical-align: top; padding: 12px 12px 12px 0; border-bottom: 1px solid var(--border); overflow-wrap: anywhere; }
    th:nth-child(1), td:nth-child(1) { width: 34%; }
    th:nth-child(2), td:nth-child(2) { width: 14%; }
    th:nth-child(3), td:nth-child(3) { width: 10%; }
    .class-name { display: block; margin-top: 4px; font-size: 12px; color: var(--muted); font-weight: 400; }
    tr.result-failed td:nth-child(2) { color: var(--danger); font-weight: 600; }
    pre { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; font-family: inherit; font-size: 12px; color: var(--danger); }
    .muted { color: var(--muted); font-size: 14px; }
    .error-note { margin-top: 16px; color: var(--danger); font-weight: 600; }
    ul { margin: 0; padding-left: 20px; }
    li { margin: 8px 0; overflow-wrap: anywhere; }
    .site-footer { border-top: 1px solid var(--border); background: var(--surface); }
    .site-footer p { padding: 16px 0; font-size: 12px; color: var(--muted); }
    @media (max-width: 700px) {
      .intro { flex-direction: column; }
      table, thead, tbody, tr, td { display: block; width: 100%; }
      table td:nth-child(n), table th:nth-child(n) { width: 100%; }
      thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
      tr { padding: 12px 0; border-bottom: 1px solid var(--border); }
      td { border: 0; padding: 4px 0; }
      td::before { content: attr(data-label); display: block; font-size: 12px; font-weight: 600; color: var(--muted); }
    }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="shell header-inner">
      <p class="wordmark">Department of Computer Science</p>
      <p class="brand-sub">Test report receiver</p>
    </div>
  </header>
  <main class="shell">
    <div class="intro">
      <h1>Test report</h1>
      <p class="badge ' . e($badgeClass) . '">' . e($badgeText) . '</p>
    </div>
    ' . $empty . '
    <section class="card">
      <h2>Run</h2>
      <dl class="facts">' . $facts . '</dl>
      ' . $errorNote . '
    </section>
    <section class="card">
      <h2>Counts</h2>
      <div class="counts">
        <div class="count"><strong>' . e((string) ($summary['total'] ?? 0)) . '</strong><span>Total</span></div>
        <div class="count"><strong>' . e((string) ($summary['passed'] ?? 0)) . '</strong><span>Passed</span></div>
        <div class="count"><strong>' . e((string) ($summary['failed'] ?? 0)) . '</strong><span>Failed</span></div>
        <div class="count"><strong>' . e((string) ($summary['skipped'] ?? 0)) . '</strong><span>Skipped</span></div>
      </div>
    </section>
    <section class="card">
      <h2>Tests</h2>
      <table>
        <thead><tr><th>Test</th><th>Result</th><th>ms</th><th>Failure</th></tr></thead>
        <tbody>' . $rows . '</tbody>
      </table>
    </section>
    <section class="card">
      <h2>Recent reports</h2>
      ' . $historyHtml . '
    </section>
  </main>
  <footer class="site-footer">
    <div class="shell"><p>Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK</p></div>
  </footer>
</body>
</html>';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param array<string, mixed> $payload
 */
function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function logLine(string $method, string $path, string $result): void
{
    $line = '[' . gmdate('c') . '] ' . $method . ' ' . $path . ' ' . $result . PHP_EOL;
    $stderr = @fopen('php://stderr', 'ab');
    if ($stderr === false) {
        return;
    }
    fwrite($stderr, $line);
    fclose($stderr);
}

/**
 * @return array<string, string>
 */
function loadDotEnv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }
    $vars = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $vars[$key] = $value;
        }
    }

    return $vars;
}

/**
 * @param array<string, string> $dotenv
 */
function setting(string $key, array $dotenv): string
{
    $fromEnv = getenv($key);
    if (is_string($fromEnv)) {
        return $fromEnv;
    }

    return $dotenv[$key] ?? '';
}

function writeAtomic(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the reports directory.');
    }

    $tmp = $dir . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Could not write a temporary report file.');
    }

    if (is_file($path) && PHP_OS_FAMILY === 'Windows' && !unlink($path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace the report file.');
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace the report file.');
    }
}
