<?php
declare(strict_types=1);

/**
 * Corta la ejecución con un 401 si no hay sesión de administrador activa.
 * Debe llamarse justo después de session_start() al inicio de cada
 * endpoint dentro de admin/.
 *
 * @return array{id:int,nombre:string,rol:string,id_colegio:int,es_plataforma:bool} datos del admin en sesión
 */
function requireAdminAuth(): array
{
    if (!isset($_SESSION['admin_id'], $_SESSION['admin_id_colegio'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No autenticado. Inicia sesión como administrador.']);
        exit;
    }

    return [
        'id' => (int) $_SESSION['admin_id'],
        'nombre' => $_SESSION['admin_nombre'] ?? '',
        'rol' => $_SESSION['admin_rol'] ?? '',
        'id_colegio' => (int) $_SESSION['admin_id_colegio'],
        'es_plataforma' => (bool) ($_SESSION['admin_es_plataforma'] ?? false),
    ];
}

/**
 * Corta la ejecución con un 401/403 si la sesión activa no tiene el
 * acceso de "superadmin de plataforma". Debe llamarse justo después de
 * session_start() al inicio de cada endpoint dentro de plataforma/.
 *
 * A diferencia de requireAdminAuth(), esto NO debe devolver un
 * id_colegio utilizable para filtrar datos: quien pasa este guard puede
 * ver información de TODOS los colegios a propósito.
 *
 * @return array{id:int,nombre:string} datos del admin en sesión
 */
function requirePlataformaAuth(): array
{
    $admin = requireAdminAuth(); // primero, que sea un admin válido

    if (!$admin['es_plataforma']) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Esta cuenta no tiene acceso al panel de plataforma.']);
        exit;
    }

    return ['id' => $admin['id'], 'nombre' => $admin['nombre']];
}
