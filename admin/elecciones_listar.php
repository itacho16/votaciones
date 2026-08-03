<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdminAuth();

$pdo = getConnection();

$stmt = $pdo->prepare('
    SELECT
        e.id,
        e.titulo,
        e.descripcion,
        e.fecha_inicio,
        e.fecha_fin,
        e.estado,
        e.archivada,
        (SELECT COUNT(*) FROM cargos c WHERE c.id_eleccion = e.id) AS total_cargos,
        (SELECT COUNT(*) FROM candidatos ca
            JOIN cargos c ON c.id = ca.id_cargo
            WHERE c.id_eleccion = e.id) AS total_candidatos
    FROM elecciones e
    WHERE e.id_colegio = :id_colegio
    ORDER BY e.fecha_inicio DESC, e.id DESC
');
$stmt->execute(['id_colegio' => $admin['id_colegio']]);
$elecciones = $stmt->fetchAll();

foreach ($elecciones as &$eleccion) {
    $eleccion['total_cargos'] = (int) $eleccion['total_cargos'];
    $eleccion['total_candidatos'] = (int) $eleccion['total_candidatos'];
}
unset($eleccion);

jsonResponse(['elecciones' => $elecciones]);
