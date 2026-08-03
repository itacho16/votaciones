<?php
declare(strict_types=1);

function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $mensaje, int $statusCode = 400): void
{
    jsonResponse(['error' => $mensaje], $statusCode);
}
