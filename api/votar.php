<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido.', 405);
}

// El estudiante y la elección se leen de la SESIÓN (creada en login.php),
// nunca del cuerpo de la petición: así evitamos que alguien vote
// suplantando el id de otro estudiante manipulando el request.
if (!isset($_SESSION['id_estudiante'], $_SESSION['id_eleccion'], $_SESSION['id_colegio'])) {
    jsonError('Sesión no válida. Ingresa tu código de acceso nuevamente.', 401);
}

$idEstudiante = (int) $_SESSION['id_estudiante'];
$idEleccion = (int) $_SESSION['id_eleccion'];
$idColegio = (int) $_SESSION['id_colegio'];

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$selecciones = $input['selecciones'] ?? null; // { "id_cargo": id_candidato, ... }

if (!is_array($selecciones) || count($selecciones) === 0) {
    jsonError('No se recibió ninguna selección.', 422);
}

$pdo = getConnection();

// Validar que cada cargo pertenezca a la elección y cada candidato al cargo correcto.
// Esto evita que alguien vote por un candidato de otra elección/cargo manipulando el JSON.
$stmtCargo = $pdo->prepare('SELECT id FROM cargos WHERE id = :id_cargo AND id_eleccion = :id_eleccion AND id_colegio = :id_colegio');
$stmtCandidato = $pdo->prepare('SELECT id FROM candidatos WHERE id = :id_candidato AND id_cargo = :id_cargo AND id_colegio = :id_colegio');

foreach ($selecciones as $idCargo => $idCandidato) {
    $stmtCargo->execute(['id_cargo' => (int) $idCargo, 'id_eleccion' => $idEleccion, 'id_colegio' => $idColegio]);
    if (!$stmtCargo->fetch()) {
        jsonError('Selección inválida: el cargo no pertenece a esta elección.', 422);
    }

    $stmtCandidato->execute(['id_candidato' => (int) $idCandidato, 'id_cargo' => (int) $idCargo, 'id_colegio' => $idColegio]);
    if (!$stmtCandidato->fetch()) {
        jsonError('Selección inválida: el candidato no pertenece al cargo indicado.', 422);
    }
}

$pdo->beginTransaction();

try {
    $stmtInsertar = $pdo->prepare('
        INSERT INTO votos (id_estudiante, id_cargo, id_candidato, id_colegio)
        VALUES (:id_estudiante, :id_cargo, :id_candidato, :id_colegio)
    ');

    foreach ($selecciones as $idCargo => $idCandidato) {
        $stmtInsertar->execute([
            'id_estudiante' => $idEstudiante,
            'id_cargo' => (int) $idCargo,
            'id_candidato' => (int) $idCandidato,
            'id_colegio' => $idColegio,
        ]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();

    // 23505 = unique_violation en PostgreSQL: el estudiante ya tenía
    // un voto registrado para ese cargo (la restricción UNIQUE de la
    // tabla votos es la última línea de defensa contra doble voto).
    if ($e->getCode() === '23505') {
        jsonError('Ya registraste tu voto para uno de estos cargos.', 409);
    }

    jsonError('No se pudo registrar el voto. Intenta nuevamente.', 500);
}

$comprobante = 'VOT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

// Cerrar la sesión de votación: aunque alguien reintente el request,
// ya no habrá id_estudiante/id_eleccion en sesión para volver a votar.
session_unset();
session_destroy();

jsonResponse([
    'mensaje' => 'Tu voto ha sido registrado correctamente.',
    'comprobante' => $comprobante,
]);
