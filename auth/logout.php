<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../helpers/response.php';

header('Content-Type: application/json; charset=utf-8');

session_unset();
session_destroy();

jsonResponse(['mensaje' => 'Sesión cerrada correctamente.']);
