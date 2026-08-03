<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/security.php';
require_once __DIR__ . '/../helpers/auth.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido.', 405);
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    jsonError('Debes adjuntar un archivo Excel (.xlsx).', 422);
}

// Se asume el formato definido con el usuario:
// columna A=codigo_matricula, B=nombres, C=apellidos, D=grado, E=seccion
// con encabezados en la fila 1.
try {
    $hoja = IOFactory::load($_FILES['archivo']['tmp_name'])->getActiveSheet();
} catch (\Throwable $e) {
    jsonError('No se pudo leer el archivo. Verifica que sea un .xlsx válido.', 422);
}

$filas = $hoja->toArray(null, true, true, false);
array_shift($filas); // descartar fila de encabezados

$pdo = getConnection();
$pdo->beginTransaction();

// codigo_matricula es único por colegio (no global): dos colegios pueden
// tener, cada uno, un estudiante con el mismo código interno.
$stmtExiste = $pdo->prepare('SELECT id FROM estudiantes WHERE codigo_matricula = :codigo AND id_colegio = :id_colegio');
$stmtInsertar = $pdo->prepare('
    INSERT INTO estudiantes (codigo_matricula, nombres, apellidos, grado, seccion, token_acceso, id_colegio)
    VALUES (:codigo, :nombres, :apellidos, :grado, :seccion, :token, :id_colegio)
');

$leidos = 0;
$importados = 0;
$duplicados = 0;
$errores = [];
$importadosDetalle = [];

foreach ($filas as $indice => $fila) {
    $codigo = trim((string) ($fila[0] ?? ''));
    $nombres = trim((string) ($fila[1] ?? ''));
    $apellidos = trim((string) ($fila[2] ?? ''));
    $grado = trim((string) ($fila[3] ?? ''));
    $seccion = trim((string) ($fila[4] ?? ''));

    if ($codigo === '' || $nombres === '' || $apellidos === '') {
        continue; // fila vacía o incompleta: se ignora silenciosamente
    }

    $leidos++;

    $stmtExiste->execute(['codigo' => $codigo, 'id_colegio' => $admin['id_colegio']]);
    if ($stmtExiste->fetch()) {
        $duplicados++;
        continue;
    }

    // Se reintenta la generación del token en el raro caso de colisión
    // (token_acceso es UNIQUE en la tabla).
    $intentos = 0;
    do {
        $token = generarTokenAcceso();
        $stmtTokenExiste = $pdo->prepare('SELECT 1 FROM estudiantes WHERE token_acceso = :token');
        $stmtTokenExiste->execute(['token' => $token]);
        $intentos++;
    } while ($stmtTokenExiste->fetch() && $intentos < 5);

    try {
        $stmtInsertar->execute([
            'codigo' => $codigo,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'grado' => $grado,
            'seccion' => $seccion,
            'token' => $token,
            'id_colegio' => $admin['id_colegio'],
        ]);
        $importados++;
        $importadosDetalle[] = [
            'codigo' => $codigo,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'grado' => $grado,
            'seccion' => $seccion,
            'token_acceso' => $token,
        ];
    } catch (PDOException $e) {
        $numeroFilaExcel = $indice + 2; // +1 por encabezado, +1 por índice base 0
        $errores[] = "Fila {$numeroFilaExcel}: no se pudo importar.";
    }
}

$pdo->commit();

jsonResponse([
    'leidos' => $leidos,
    'importados' => $importados,
    'duplicados' => $duplicados,
    'errores' => $errores,
    'importados_detalle' => $importadosDetalle,
]);
