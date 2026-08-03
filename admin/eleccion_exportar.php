<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

$admin = requireAdminAuth();

// Acción sensible (expone qué estudiante votó por quién, con fines de
// auditoría interna): restringida a superadmin, igual que antes.
if ($admin['rol'] !== 'superadmin') {
    jsonError('Solo un superadmin puede exportar y archivar una elección.', 403);
}

$idEleccion = (int) ($_GET['id_eleccion'] ?? 0);
if ($idEleccion <= 0) {
    jsonError('Debes indicar id_eleccion.', 422);
}

$pdo = getConnection();

$stmt = $pdo->prepare('SELECT * FROM elecciones WHERE id = :id AND id_colegio = :id_colegio');
$stmt->execute(['id' => $idEleccion, 'id_colegio' => $admin['id_colegio']]);
$eleccion = $stmt->fetch();

if (!$eleccion) {
    jsonError('La elección indicada no existe.', 404);
}
if ($eleccion['estado'] !== 'cerrada') {
    jsonError('Solo se pueden exportar elecciones en estado "cerrada".', 422);
}

// 1. Cargos + candidatos con conteo de votos (mismo cálculo que api/resultados.php)
$stmtCargos = $pdo->prepare('SELECT id, nombre, orden FROM cargos WHERE id_eleccion = :id ORDER BY orden ASC, id ASC');
$stmtCargos->execute(['id' => $idEleccion]);
$cargos = $stmtCargos->fetchAll();

$stmtCandidatos = $pdo->prepare('
    SELECT ca.id, ca.nombre, ca.descripcion, ca.foto_url, ca.logo_url, COUNT(v.id) AS votos
    FROM candidatos ca
    LEFT JOIN votos v ON v.id_candidato = ca.id
    WHERE ca.id_cargo = :id_cargo
    GROUP BY ca.id, ca.nombre, ca.descripcion, ca.foto_url, ca.logo_url
    ORDER BY votos DESC
');
foreach ($cargos as &$cargo) {
    $stmtCandidatos->execute(['id_cargo' => $cargo['id']]);
    $cargo['candidatos'] = array_map(function ($c) {
        $c['votos'] = (int) $c['votos'];
        return $c;
    }, $stmtCandidatos->fetchAll());
}
unset($cargo);

// 2. Miembros de mesa
$stmt = $pdo->prepare('
    SELECT nombre_completo, cargo_mesa, documento_identidad
    FROM miembros_mesa WHERE id_eleccion = :id ORDER BY orden ASC
');
$stmt->execute(['id' => $idEleccion]);
$miembrosMesa = $stmt->fetchAll();

// 3. Detalle de votos con identidad del estudiante — ESTE es el motivo por el
//    que este endpoint exige superadmin: rompe el anonimato entre estudiante
//    y candidato, a propósito, porque es justamente el registro que una
//    auditoría interna necesita para trazabilidad y rendición de cuentas.
$stmt = $pdo->prepare('
    SELECT
        v.fecha_hora,
        e.codigo_matricula,
        e.nombres AS estudiante_nombres,
        e.apellidos AS estudiante_apellidos,
        c.nombre AS cargo,
        ca.nombre AS candidato
    FROM votos v
    JOIN estudiantes e ON e.id = v.id_estudiante
    JOIN cargos c ON c.id = v.id_cargo
    JOIN candidatos ca ON ca.id = v.id_candidato
    WHERE c.id_eleccion = :id
    ORDER BY v.fecha_hora ASC
');
$stmt->execute(['id' => $idEleccion]);
$detalleVotos = $stmt->fetchAll();

$exportacion = [
    'generado_en' => (new DateTime())->format(DateTime::ATOM),
    'generado_por' => $admin['nombre'],
    'eleccion' => $eleccion,
    'cargos' => $cargos,
    'miembros_mesa' => $miembrosMesa,
    'total_votos_registrados' => count($detalleVotos),
    'detalle_votos' => $detalleVotos,
    'advertencia' => 'Este archivo contiene el detalle de qué estudiante votó por qué candidato. Es información confidencial para uso exclusivo de auditoría interna: no debe compartirse ni publicarse.',
];

$pdo->beginTransaction();
try {
    // Archivar (no borra nada, solo marca la elección como archivada)
    $stmt = $pdo->prepare('UPDATE elecciones SET archivada = TRUE WHERE id = :id');
    $stmt->execute(['id' => $idEleccion]);

    // Registrar en la bitácora de auditoría quién exportó/archivó y cuándo
    $stmt = $pdo->prepare('
        INSERT INTO auditoria_log (id_admin, accion, id_eleccion, detalle, id_colegio)
        VALUES (:id_admin, :accion, :id_eleccion, :detalle, :id_colegio)
    ');
    $stmt->execute([
        'id_admin' => $admin['id'],
        'accion' => 'exportar_archivar_eleccion',
        'id_eleccion' => $idEleccion,
        'detalle' => "Exportó y archivó \"{$eleccion['titulo']}\" ({$exportacion['total_votos_registrados']} votos registrados).",
        'id_colegio' => $admin['id_colegio'],
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    jsonError('No se pudo completar el archivado. Intenta nuevamente.', 500);
}

// Entregar el archivo como descarga
$nombreArchivo = 'auditoria_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $eleccion['titulo']) . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
echo json_encode($exportacion, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
