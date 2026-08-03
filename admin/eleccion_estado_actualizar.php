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
$nuevoEstado = trim((string) ($input['estado'] ?? ''));

$estadosValidos = ['borrador', 'activa', 'cerrada'];
if ($idEleccion <= 0 || !in_array($nuevoEstado, $estadosValidos, true)) {
    jsonError('Datos inválidos.', 422);
}

$pdo = getConnection();

// No se puede activar una elección sin al menos un cargo con candidatos:
// evita que un estudiante entre a votar y se encuentre una boleta vacía.
if ($nuevoEstado === 'activa') {
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM cargos c
        WHERE c.id_eleccion = :id_eleccion
          AND c.id_colegio = :id_colegio
          AND EXISTS (SELECT 1 FROM candidatos ca WHERE ca.id_cargo = c.id)
    ');
    $stmt->execute(['id_eleccion' => $idEleccion, 'id_colegio' => $admin['id_colegio']]);
    if ((int) $stmt->fetchColumn() === 0) {
        jsonError('No puedes activar una elección sin candidatos registrados en al menos un cargo.', 422);
    }
}

// El filtro por id_colegio aquí es la barrera clave: sin él, un admin
// podría cambiar el estado de una elección de OTRO colegio adivinando su id.
$stmt = $pdo->prepare('UPDATE elecciones SET estado = :estado WHERE id = :id AND id_colegio = :id_colegio RETURNING id');
$stmt->execute(['estado' => $nuevoEstado, 'id' => $idEleccion, 'id_colegio' => $admin['id_colegio']]);

if (!$stmt->fetchColumn()) {
    jsonError('La elección indicada no existe.', 404);
}

jsonResponse(['mensaje' => 'Estado de la elección actualizado.', 'estado' => $nuevoEstado]);
