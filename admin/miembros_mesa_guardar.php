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
$idEleccion = (int) ($input['id_eleccion'] ?? 0);
$miembros = $input['miembros'] ?? [];

if ($idEleccion <= 0) {
    jsonError('Debes indicar id_eleccion.', 422);
}
if (!is_array($miembros) || count($miembros) === 0) {
    jsonError('Debes registrar al menos un miembro de mesa.', 422);
}

$cargosValidos = ['Presidente', 'Secretario', 'Miembro'];
foreach ($miembros as $m) {
    $nombre = trim((string) ($m['nombre_completo'] ?? ''));
    $cargo = trim((string) ($m['cargo_mesa'] ?? ''));
    if ($nombre === '' || !in_array($cargo, $cargosValidos, true)) {
        jsonError('Cada miembro necesita un nombre y un cargo válido (Presidente, Secretario o Miembro).', 422);
    }
}

$pdo = getConnection();

$stmt = $pdo->prepare('SELECT id FROM elecciones WHERE id = :id AND id_colegio = :id_colegio');
$stmt->execute(['id' => $idEleccion, 'id_colegio' => $admin['id_colegio']]);
if (!$stmt->fetch()) {
    jsonError('La elección indicada no existe.', 404);
}

$pdo->beginTransaction();
try {
    // Enfoque "reemplazar todo": más simple de mantener sincronizado con el
    // formulario del panel que hacer diffs de altas/bajas/ediciones.
    // El filtro por id_colegio es redundante con la verificación de arriba,
    // pero se deja como segunda barrera (defensa en profundidad).
    $stmtBorrar = $pdo->prepare('DELETE FROM miembros_mesa WHERE id_eleccion = :id_eleccion AND id_colegio = :id_colegio');
    $stmtBorrar->execute(['id_eleccion' => $idEleccion, 'id_colegio' => $admin['id_colegio']]);

    $stmtInsertar = $pdo->prepare('
        INSERT INTO miembros_mesa (id_eleccion, nombre_completo, cargo_mesa, documento_identidad, orden, id_colegio)
        VALUES (:id_eleccion, :nombre, :cargo, :documento, :orden, :id_colegio)
    ');
    foreach ($miembros as $orden => $m) {
        $stmtInsertar->execute([
            'id_eleccion' => $idEleccion,
            'nombre' => trim((string) $m['nombre_completo']),
            'cargo' => trim((string) $m['cargo_mesa']),
            'documento' => trim((string) ($m['documento_identidad'] ?? '')) ?: null,
            'orden' => $orden,
            'id_colegio' => $admin['id_colegio'],
        ]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    jsonError('No se pudo guardar la mesa electoral.', 500);
}

jsonResponse(['mensaje' => 'Miembros de mesa guardados correctamente.']);
