<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdminAuth();

$idEleccion = (int) ($_GET['id_eleccion'] ?? 0);
if ($idEleccion <= 0) {
    jsonError('Debes indicar id_eleccion.', 422);
}

$pdo = getConnection();
$stmt = $pdo->prepare('
    SELECT id, nombre_completo, cargo_mesa, documento_identidad, orden
    FROM miembros_mesa
    WHERE id_eleccion = :id_eleccion AND id_colegio = :id_colegio
    ORDER BY orden ASC, id ASC
');
$stmt->execute(['id_eleccion' => $idEleccion, 'id_colegio' => $admin['id_colegio']]);

jsonResponse(['miembros' => $stmt->fetchAll()]);
