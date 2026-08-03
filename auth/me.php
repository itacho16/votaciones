<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdminAuth(); // corta con 401 si no hay sesión

$pdo = getConnection();
$stmt = $pdo->prepare('SELECT nombre FROM colegios WHERE id = :id');
$stmt->execute(['id' => $admin['id_colegio']]);
$nombreColegio = $stmt->fetchColumn();

jsonResponse([
    'admin' => [
        'nombre' => $admin['nombre'],
        'rol' => $admin['rol'],
        'id_colegio' => $admin['id_colegio'],
        'colegio' => $nombreColegio ?: null,
        'es_plataforma' => $admin['es_plataforma'],
    ],
]);
