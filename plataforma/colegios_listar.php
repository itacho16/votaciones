<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Corta con 401/403 si la sesión no tiene el flag es_superadmin_plataforma.
// A propósito, esto NO usa requireAdminAuth() a solas: cualquier admin de
// cualquier colegio pasaría ese guard, pero solo quien tenga el flag de
// plataforma debe poder ver datos de TODOS los colegios.
requirePlataformaAuth();

$pdo = getConnection();

$colegios = $pdo->query('
    SELECT
        c.id,
        c.nombre,
        c.slug,
        c.activo,
        c.creado_en,
        (SELECT COUNT(*) FROM elecciones e WHERE e.id_colegio = c.id) AS total_elecciones,
        (SELECT COUNT(*) FROM estudiantes es WHERE es.id_colegio = c.id) AS total_estudiantes
    FROM colegios c
    ORDER BY c.creado_en DESC
')->fetchAll();

$stmtAdmins = $pdo->prepare('
    SELECT id, nombre, email, rol, es_superadmin_plataforma, creado_en
    FROM usuarios_admin
    WHERE id_colegio = :id_colegio
    ORDER BY creado_en ASC
');

foreach ($colegios as &$colegio) {
    $stmtAdmins->execute(['id_colegio' => $colegio['id']]);
    $colegio['admins'] = array_map(function ($a) {
        $a['es_superadmin_plataforma'] = (bool) $a['es_superadmin_plataforma'];
        return $a;
    }, $stmtAdmins->fetchAll());
}
unset($colegio);

jsonResponse(['colegios' => $colegios]);
