<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

header('Content-Type: application/json; charset=utf-8');

$idEleccion = (int) ($_GET['id_eleccion'] ?? 0);
if ($idEleccion <= 0) {
    jsonError('Debes indicar id_eleccion.', 422);
}

$pdo = getConnection();

// La elección ya "sabe" a qué colegio pertenece: de ahí se deriva el
// id_colegio para acotar todo lo demás en este endpoint público, sin
// necesitar sesión ni parámetro adicional en la URL.
$stmtEleccion = $pdo->prepare('SELECT id_colegio FROM elecciones WHERE id = :id_eleccion');
$stmtEleccion->execute(['id_eleccion' => $idEleccion]);
$idColegio = $stmtEleccion->fetchColumn();

if ($idColegio === false) {
    jsonError('La elección indicada no existe.', 404);
}

$stmtCargos = $pdo->prepare('
    SELECT id, nombre
    FROM cargos
    WHERE id_eleccion = :id_eleccion
    ORDER BY orden ASC, id ASC
');
$stmtCargos->execute(['id_eleccion' => $idEleccion]);
$cargos = $stmtCargos->fetchAll();

$stmtResultados = $pdo->prepare('
    SELECT ca.id, ca.nombre, COUNT(v.id) AS votos
    FROM candidatos ca
    LEFT JOIN votos v ON v.id_candidato = ca.id
    WHERE ca.id_cargo = :id_cargo
    GROUP BY ca.id, ca.nombre
    ORDER BY votos DESC
');

// Estudiantes habilitados para votar (padrón activo), SOLO del colegio de
// esta elección. Se usa como base para el % de participación por cargo.
$stmtTotalEstudiantes = $pdo->prepare('SELECT COUNT(*) FROM estudiantes WHERE activo = TRUE AND id_colegio = :id_colegio');
$stmtTotalEstudiantes->execute(['id_colegio' => $idColegio]);
$totalEstudiantes = (int) $stmtTotalEstudiantes->fetchColumn();

foreach ($cargos as &$cargo) {
    $stmtResultados->execute(['id_cargo' => $cargo['id']]);
    $candidatos = $stmtResultados->fetchAll();

    $totalVotos = (int) array_sum(array_column($candidatos, 'votos'));

    foreach ($candidatos as &$c) {
        $c['votos'] = (int) $c['votos'];
        $c['porcentaje'] = $totalVotos > 0 ? round($c['votos'] / $totalVotos * 100, 1) : 0.0;
    }
    unset($c);

    $cargo['candidatos'] = $candidatos;
    $cargo['total_votos'] = $totalVotos;
    $cargo['total_estudiantes'] = $totalEstudiantes;
    $cargo['participacion_pct'] = $totalEstudiantes > 0
        ? round($totalVotos / $totalEstudiantes * 100, 1)
        : 0.0;
}
unset($cargo);

jsonResponse(['cargos' => $cargos]);
