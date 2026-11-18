<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

/**
 * Turns testreport.json into an HTML email.
 * Always writes email.html, including when the report is missing.
 */

$root = dirname(__DIR__);
$reportPath = $root . DIRECTORY_SEPARATOR . 'testreport.json';
$outputPath = isset($argv[1]) && $argv[1] !== ''
    ? $argv[1]
    : $root . DIRECTORY_SEPARATOR . 'email.html';

try {
    $report = readReport($reportPath);
    $subject = emailSubject($report);
    $html = renderEmail($report, $subject);
    writeEmail($outputPath, $html);
    writeSubjectOutput($subject);
    fwrite(STDOUT, 'Wrote ' . $outputPath . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    $report = emptyReport('Could not build the email: ' . $error->getMessage());
    $subject = emailSubject($report);
    try {
        writeEmail($outputPath, renderEmail($report, $subject));
        writeSubjectOutput($subject);
        fwrite(STDOUT, 'Wrote ' . $outputPath . PHP_EOL);
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(0);
    } catch (Throwable $writeError) {
        fwrite(STDERR, $writeError->getMessage() . PHP_EOL);
        exit(1);
    }
}

/**
 * @return array<string, mixed>
 */
function readReport(string $path): array
{
    if (!is_file($path)) {
        return emptyReport('testreport.json was not found.');
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return emptyReport('testreport.json was empty or could not be read.');
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return emptyReport('testreport.json could not be parsed. ' . $error->getMessage());
    }

    if (!is_array($decoded)) {
        return emptyReport('testreport.json did not contain a report object.');
    }

    return $decoded;
}

/**
 * @return array<string, mixed>
 */
function emptyReport(string $message): array
{
    return [
        'repository' => 'unknown',
        'branch' => 'unknown',
        'commitSha' => 'unknown',
        'commitShort' => 'unknown',
        'runId' => '',
        'runUrl' => '',
        'timestamp' => '',
        'status' => 'error',
        'summary' => [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ],
        'tests' => [],
        'errorMessage' => $message,
    ];
}

/**
 * @param array<string, mixed> $report
 */
function emailSubject(array $report): string
{
    $status = reportStatus($report);
    $repository = textValue($report, 'repository');
    if ($repository === '') {
        $repository = 'unknown';
    }
    $short = textValue($report, 'commitShort');
    if ($short === '') {
        $sha = textValue($report, 'commitSha');
        $short = $sha !== '' ? substr($sha, 0, 7) : 'unknown';
    }

    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $total = numberValue($summary, 'total');
    $passed = numberValue($summary, 'passed');
    $failed = numberValue($summary, 'failed');

    if ($status === 'passed') {
        $detail = $passed . '/' . $total . ' tests passed';
    } else {
        $detail = $failed . '/' . $total . ' tests failed';
    }

    $subject = '[' . strtoupper($status) . '] ' . $repository . ' @ ' . $short . ' - ' . $detail;

    return str_replace(["\r", "\n"], ' ', $subject);
}

/**
 * @param array<string, mixed> $report
 */
function renderEmail(array $report, string $subject): string
{
    $status = reportStatus($report);
    $banner = bannerColors($status);
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $tests = is_array($report['tests'] ?? null) ? $report['tests'] : [];
    $runUrl = textValue($report, 'runUrl');
    $runLink = runLink($runUrl);
    $error = textValue($report, 'errorMessage');
    $errorRow = $error === ''
        ? ''
        : '<tr><td style="padding:0 24px 16px;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;color:#B42318;">'
            . escapeText($error) . '</td></tr>';

    $rows = '';
    $durationMs = 0;
    $shown = 0;
    foreach ($tests as $test) {
        if (!is_array($test)) {
            continue;
        }
        $shown++;
        $durationMs += numberValue($test, 'durationMs');
        $result = strtolower(textValue($test, 'result'));
        if (!in_array($result, ['passed', 'failed', 'skipped'], true)) {
            $result = 'unknown';
        }
        $resultColor = match ($result) {
            'passed' => '#067647',
            'failed' => '#B42318',
            'skipped' => '#5B6B7F',
            default => '#0F1B2D',
        };
        $failure = $test['failureMessage'] ?? null;
        $failureHtml = is_string($failure) && $failure !== ''
            ? '<div style="margin-top:6px;font-size:12px;line-height:1.45;color:#B42318;">' . escapeMultiline($failure) . '</div>'
            : '';
        $rows .= '<tr>'
            . '<td style="padding:12px 12px 12px 0;border-bottom:1px solid #E3E8EF;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.45;color:#0F1B2D;vertical-align:top;">'
            . escapeText(textValue($test, 'name'))
            . '<div style="margin-top:4px;font-size:12px;color:#5B6B7F;">' . escapeText(textValue($test, 'class')) . '</div>'
            . '</td>'
            . '<td style="padding:12px 12px 12px 0;border-bottom:1px solid #E3E8EF;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.45;font-weight:600;color:' . $resultColor . ';vertical-align:top;">'
            . escapeText($result) . '</td>'
            . '<td style="padding:12px 0;border-bottom:1px solid #E3E8EF;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.45;color:#0F1B2D;vertical-align:top;">'
            . $failureHtml . '</td>'
            . '</tr>';
    }
    if ($shown === 0) {
        $rows = '<tr><td colspan="3" style="padding:12px 0;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#5B6B7F;">No tests in this report.</td></tr>';
    }

    $executed = textValue($report, 'timestamp');
    $execution = ($executed !== '' ? $executed . ' · ' : '') . formatDuration($durationMs);

    $facts = [
        'Repository' => escapeText(textValue($report, 'repository') !== '' ? textValue($report, 'repository') : 'unknown'),
        'Branch' => escapeText(textValue($report, 'branch') !== '' ? textValue($report, 'branch') : 'unknown'),
        'Commit' => escapeText(commitLine($report)),
        'Run' => $runLink,
        'Execution time' => escapeText($execution),
    ];
    $factRows = '';
    foreach ($facts as $label => $value) {
        $factRows .= '<tr>'
            . '<td style="padding:6px 16px 6px 0;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#5B6B7F;vertical-align:top;white-space:nowrap;">' . escapeText($label) . '</td>'
            . '<td style="padding:6px 0;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.45;color:#0F1B2D;word-break:break-all;">' . $value . '</td>'
            . '</tr>';
    }

    $counts = [
        'Total' => numberValue($summary, 'total'),
        'Passed' => numberValue($summary, 'passed'),
        'Failed' => numberValue($summary, 'failed'),
        'Skipped' => numberValue($summary, 'skipped'),
    ];
    $countCells = '';
    foreach ($counts as $label => $value) {
        $countCells .= '<td style="padding:8px 16px 8px 0;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
            . '<div style="font-size:28px;line-height:1.2;font-weight:600;color:#0F1B2D;">' . escapeText((string) $value) . '</div>'
            . '<div style="font-size:12px;color:#5B6B7F;">' . escapeText($label) . '</div>'
            . '</td>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . escapeText($subject) . '</title>
</head>
<body style="margin:0;padding:0;background:#F5F7FA;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F7FA;margin:0;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#FFFFFF;border:1px solid #E3E8EF;">
          <tr>
            <td style="background:#12305C;padding:20px 24px;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
              <div style="font-size:16px;font-weight:600;color:#FFFFFF;">Department of Computer Science</div>
              <div style="margin-top:4px;font-size:12px;color:#D6DEE8;">Test report</div>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 24px;background:' . $banner['background'] . ';font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:20px;line-height:1.2;font-weight:700;letter-spacing:0.04em;color:' . $banner['color'] . ';">'
            . escapeText(strtoupper($status)) . '</td>
          </tr>
          ' . $errorRow . '
          <tr>
            <td style="padding:8px 24px 0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $factRows . '</table>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 24px 16px;">
              <table role="presentation" cellpadding="0" cellspacing="0"><tr>' . $countCells . '</tr></table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 24px 24px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <th align="left" style="padding:8px 12px 8px 0;border-bottom:1px solid #E3E8EF;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#5B6B7F;">Test</th>
                  <th align="left" style="padding:8px 12px 8px 0;border-bottom:1px solid #E3E8EF;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#5B6B7F;">Result</th>
                  <th align="left" style="padding:8px 0;border-bottom:1px solid #E3E8EF;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#5B6B7F;">Failure</th>
                </tr>
                ' . $rows . '
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 24px;border-top:1px solid #E3E8EF;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#5B6B7F;">Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK · CSC418 DevOps Lab Mid</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
';
}

/**
 * @param array<string, mixed> $report
 */
function reportStatus(array $report): string
{
    $status = strtolower(textValue($report, 'status'));
    if (!in_array($status, ['passed', 'failed', 'error'], true)) {
        return 'error';
    }

    return $status;
}

/**
 * @return array{background: string, color: string}
 */
function bannerColors(string $status): array
{
    return match ($status) {
        'passed' => ['background' => '#ECFDF3', 'color' => '#067647'],
        'failed' => ['background' => '#FEF3F2', 'color' => '#B42318'],
        default => ['background' => '#FFFAEB', 'color' => '#B54708'],
    };
}

/**
 * @param array<string, mixed> $report
 */
function commitLine(array $report): string
{
    $short = textValue($report, 'commitShort');
    $full = textValue($report, 'commitSha');
    if ($short === '') {
        $short = $full !== '' ? substr($full, 0, 7) : 'unknown';
    }
    if ($full === '') {
        return $short;
    }
    if ($short === $full) {
        return $full;
    }

    return $short . ' (' . $full . ')';
}

function runLink(string $url): string
{
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '<span style="color:#5B6B7F;">' . escapeText($url !== '' ? $url : 'No run URL') . '</span>';
    }

    $safe = escapeText($url);

    return '<a href="' . $safe . '" style="color:#1F6FEB;font-weight:600;">' . $safe . '</a>';
}

/**
 * @param array<string, mixed> $source
 */
function textValue(array $source, string $key): string
{
    if (!isset($source[$key]) || !is_scalar($source[$key])) {
        return '';
    }

    return trim((string) $source[$key]);
}

/**
 * @param array<string, mixed> $source
 */
function numberValue(array $source, string $key): int
{
    if (!isset($source[$key]) || !is_numeric($source[$key])) {
        return 0;
    }

    return (int) $source[$key];
}

function formatDuration(int $milliseconds): string
{
    if ($milliseconds < 1000) {
        return $milliseconds . ' ms';
    }

    return sprintf('%.1f s', $milliseconds / 1000);
}

function escapeText(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function escapeMultiline(string $value): string
{
    return str_replace(["\r\n", "\r", "\n"], '<br>', escapeText($value));
}

function writeEmail(string $path, string $html): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the email directory.');
    }
    if (file_put_contents($path, $html) === false) {
        throw new RuntimeException('Could not write the email file.');
    }
}

function writeSubjectOutput(string $subject): void
{
    $path = getenv('GITHUB_OUTPUT');
    if (!is_string($path) || $path === '') {
        return;
    }

    $line = 'subject=' . $subject . "\n";
    if (file_put_contents($path, $line, FILE_APPEND) === false) {
        fwrite(STDERR, 'Could not write the subject step output.' . PHP_EOL);
    }
}
