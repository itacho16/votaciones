<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$tokenAcceso = trim((string) ($input['token_acceso'] ?? ''));

if ($tokenAcceso === '') {
    jsonError('Debes ingresar tu código de acceso.', 422);
}

$pdo = getConnection();

// 1. Buscar al estudiante por su token. El token_acceso es único a nivel
//    global (por eso alcanza para identificar al estudiante sin pedir
//    ningún otro dato), y de esta fila sale el id_colegio que acota TODO
//    lo demás en este request.
$stmt = $pdo->prepare('
    SELECT id, nombres, apellidos, grado, seccion, activo, id_colegio
    FROM estudiantes
    WHERE token_acceso = :token
');
$stmt->execute(['token' => $tokenAcceso]);
$estudiante = $stmt->fetch();

if (!$estudiante) {
    jsonError('Código de acceso no válido.', 401);
}
if (!$estudiante['activo']) {
    jsonError('Este código de acceso fue desactivado. Consulta con tu docente.', 403);
}

$idColegio = (int) $estudiante['id_colegio'];

// 2. Buscar una elección activa vigente en este momento, SOLO del colegio
//    de este estudiante (así dos colegios pueden tener elecciones activas
//    al mismo tiempo sin pisarse).
$stmt = $pdo->prepare("
    SELECT id, titulo
    FROM elecciones
    WHERE estado = 'activa'
      AND id_colegio = :id_colegio
      AND NOW() BETWEEN fecha_inicio AND fecha_fin
    ORDER BY fecha_inicio DESC
    LIMIT 1
");
$stmt->execute(['id_colegio' => $idColegio]);
$eleccion = $stmt->fetch();

if (!$eleccion) {
    jsonError('No hay ninguna elección activa en este momento.', 404);
}

// 3. Verificar si aún tiene cargos pendientes por votar en esta elección
$stmt = $pdo->prepare('
    SELECT c.id
    FROM cargos c
    WHERE c.id_eleccion = :id_eleccion
      AND NOT EXISTS (
          SELECT 1 FROM votos v
          WHERE v.id_cargo = c.id AND v.id_estudiante = :id_estudiante
      )
');
$stmt->execute([
    'id_eleccion' => $eleccion['id'],
    'id_estudiante' => $estudiante['id'],
]);

if (count($stmt->fetchAll()) === 0) {
    jsonError('Ya emitiste tu voto en esta elección.', 409);
}

// 4. Traer cargos y candidatos de la elección
$stmt = $pdo->prepare('
    SELECT id, nombre, orden
    FROM cargos
    WHERE id_eleccion = :id_eleccion
    ORDER BY orden ASC, id ASC
');
$stmt->execute(['id_eleccion' => $eleccion['id']]);
$cargos = $stmt->fetchAll();

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

// 5. Guardar la sesión de votación en el servidor.
//    IMPORTANTE: votar.php NUNCA debe confiar en un id_estudiante que
//    venga del frontend; siempre debe leerlo de aquí.
$_SESSION['id_estudiante'] = $estudiante['id'];
$_SESSION['id_eleccion'] = $eleccion['id'];
$_SESSION['id_colegio'] = $idColegio;
$_SESSION['validado_en'] = time();

jsonResponse([
    'estudiante' => [
        'nombres' => $estudiante['nombres'],
        'apellidos' => $estudiante['apellidos'],
    ],
    'eleccion' => [
        'id' => $eleccion['id'],
        'titulo' => $eleccion['titulo'],
    ],
    'cargos' => $cargos,
]);
