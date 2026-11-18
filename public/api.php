<?php

// Author: Muhammad Munir | Reg No: CIIT/FA22-BCS-071/ATK

declare(strict_types=1);

use App\Api;
use App\Store;
use App\SubmissionService;
use App\Validator;

require __DIR__ . '/../vendor/autoload.php';

// php -S uses public/ as the working directory. Keep data/ at the project root.
chdir(dirname(__DIR__));

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$api = new Api(new SubmissionService(new Validator(), new Store()));
[$status, $payload, $headers] = $api->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $rawBody,
    (string) ($_SERVER['CONTENT_TYPE'] ?? '')
);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

foreach ($headers as $name => $value) {
    header($name . ': ' . $value);
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
