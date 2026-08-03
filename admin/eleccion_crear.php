<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$titulo = trim((string) ($input['titulo'] ?? ''));
$descripcion = trim((string) ($input['descripcion'] ?? ''));
$fechaInicio = trim((string) ($input['fecha_inicio'] ?? ''));
$fechaFin = trim((string) ($input['fecha_fin'] ?? ''));
$cargos = $input['cargos'] ?? [];

if ($titulo === '' || $fechaInicio === '' || $fechaFin === '') {
    jsonError('Faltan datos obligatorios (título, fecha de inicio, fecha de fin).', 422);
}

if (strtotime($fechaInicio) === false || strtotime($fechaFin) === false) {
    jsonError('Las fechas no tienen un formato válido.', 422);
}
if (strtotime($fechaFin) <= strtotime($fechaInicio)) {
    jsonError('La fecha de fin debe ser posterior a la fecha de inicio.', 422);
}

// Limpiar la lista de cargos: quitar vacíos y duplicados
$cargos = array_values(array_unique(array_filter(array_map('trim', (array) $cargos))));
if (count($cargos) === 0) {
    jsonError('Debes agregar al menos un cargo (ej. "Alcalde Escolar").', 422);
}

$pdo = getConnection();
$pdo->beginTransaction();

try {
    $stmtEleccion = $pdo->prepare("
        INSERT INTO elecciones (titulo, descripcion, fecha_inicio, fecha_fin, estado, id_admin, id_colegio)
        VALUES (:titulo, :descripcion, :fecha_inicio, :fecha_fin, 'borrador', :id_admin, :id_colegio)
        RETURNING id
    ");
    $stmtEleccion->execute([
        'titulo' => $titulo,
        'descripcion' => $descripcion,
        'fecha_inicio' => $fechaInicio,
        'fecha_fin' => $fechaFin,
        'id_admin' => $admin['id'],
        'id_colegio' => $admin['id_colegio'],
    ]);
    $idEleccion = $stmtEleccion->fetchColumn();

    $stmtCargo = $pdo->prepare('
        INSERT INTO cargos (id_eleccion, nombre, orden, id_colegio)
        VALUES (:id_eleccion, :nombre, :orden, :id_colegio)
    ');
    foreach ($cargos as $orden => $nombreCargo) {
        $stmtCargo->execute([
            'id_eleccion' => $idEleccion,
            'nombre' => $nombreCargo,
            'orden' => $orden,
            'id_colegio' => $admin['id_colegio'],
        ]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    jsonError('No se pudo crear la elección. Intenta nuevamente.', 500);
}

jsonResponse([
    'id' => $idEleccion,
    'mensaje' => 'Elección creada como borrador. Actívala cuando esté lista.',
], 201);
