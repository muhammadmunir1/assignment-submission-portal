<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

/**
 * Builds testreport.json when it is missing, then writes email.html.
 */

$root = dirname(__DIR__);
chdir($root);

$php = PHP_BINARY;
$report = $root . DIRECTORY_SEPARATOR . 'testreport.json';
$email = $root . DIRECTORY_SEPARATOR . 'email.html';

if (!is_file($report)) {
    $build = escapeshellarg($php) . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build-report.php');
    passthru($build, $buildCode);
    if ($buildCode !== 0) {
        exit($buildCode);
    }
}

$emailScript = escapeshellarg($php) . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build-email.php');
passthru($emailScript, $emailCode);
if ($emailCode !== 0) {
    exit($emailCode);
}

fwrite(STDOUT, $email . PHP_EOL);
exit(0);
