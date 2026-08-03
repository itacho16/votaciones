<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdminAuth();

if ($admin['rol'] !== 'superadmin') {
    jsonError('Solo un superadmin puede ver la bitácora de auditoría.', 403);
}

$pdo = getConnection();

$stmt = $pdo->prepare('
    SELECT
        al.id,
        al.accion,
        al.detalle,
        al.creado_en,
        ua.nombre AS admin_nombre,
        e.titulo AS eleccion_titulo
    FROM auditoria_log al
    LEFT JOIN usuarios_admin ua ON ua.id = al.id_admin
    LEFT JOIN elecciones e ON e.id = al.id_eleccion
    WHERE al.id_colegio = :id_colegio
    ORDER BY al.creado_en DESC
    LIMIT 200
');
$stmt->execute(['id_colegio' => $admin['id_colegio']]);

jsonResponse(['registros' => $stmt->fetchAll()]);
