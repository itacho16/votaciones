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

$stmtCargos = $pdo->prepare('
    SELECT id, nombre, orden
    FROM cargos
    WHERE id_eleccion = :id_eleccion AND id_colegio = :id_colegio
    ORDER BY orden ASC, id ASC
');
$stmtCargos->execute(['id_eleccion' => $idEleccion, 'id_colegio' => $admin['id_colegio']]);
$cargos = $stmtCargos->fetchAll();

$stmtCandidatos = $pdo->prepare('
    SELECT id, nombre, descripcion, foto_url, logo_url, orden
    FROM candidatos
    WHERE id_cargo = :id_cargo
    ORDER BY orden ASC, id ASC
');

foreach ($cargos as &$cargo) {
    $stmtCandidatos->execute(['id_cargo' => $cargo['id']]);
    $cargo['candidatos'] = $stmtCandidatos->fetchAll();
}
unset($cargo);

jsonResponse(['cargos' => $cargos]);
