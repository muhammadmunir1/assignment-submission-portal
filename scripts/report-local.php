<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

/**
 * Local pipeline: PHPUnit (failure allowed), then build the report, then send it.
 */

$root = dirname(__DIR__);
chdir($root);

$php = PHP_BINARY;
$phpunit = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'phpunit'
    . DIRECTORY_SEPARATOR . 'phpunit' . DIRECTORY_SEPARATOR . 'phpunit';

$test = escapeshellarg($php) . ' ' . escapeshellarg($phpunit)
    . ' --log-junit ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'junit.xml');
passthru($test, $testCode);
fwrite(STDOUT, 'phpunit exited with ' . $testCode . PHP_EOL);

$build = escapeshellarg($php) . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build-report.php');
passthru($build, $buildCode);
if ($buildCode !== 0) {
    exit($buildCode);
}

$send = escapeshellarg($php) . ' '
    . escapeshellarg($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'send-report.php')
    . ' ' . escapeshellarg('http://localhost:4000/report');
passthru($send, $sendCode);
exit($sendCode);
