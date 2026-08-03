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

$nombre = trim((string) ($_POST['nombre'] ?? ''));
$descripcion = trim((string) ($_POST['descripcion'] ?? ''));
$idCargo = (int) ($_POST['id_cargo'] ?? 0);

if ($nombre === '' || $idCargo <= 0) {
    jsonError('Faltan datos obligatorios (nombre, id_cargo).', 422);
}

const TIPOS_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp'];
const TAMANO_MAXIMO = 3 * 1024 * 1024; // 3 MB

/**
 * Valida y mueve un archivo subido a la carpeta de uploads.
 * Devuelve la ruta relativa guardable en BD, o null si no se envió archivo.
 */
function guardarImagen(?array $archivo, string $directorioDestino): ?string
{
    if ($archivo === null || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        jsonError('Ocurrió un error al subir la imagen.', 422);
    }
    if ($archivo['size'] > TAMANO_MAXIMO) {
        jsonError('La imagen supera el tamaño máximo permitido (3 MB).', 422);
    }

    // No confiar en la extensión ni en el mime que manda el navegador:
    // se valida el tipo real leyendo los bytes del archivo.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $archivo['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, TIPOS_PERMITIDOS, true)) {
        jsonError('Formato de imagen no permitido. Usa JPG, PNG o WEBP.', 422);
    }

    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'jpg',
    };

    // Nombre de archivo aleatorio: evita colisiones y no expone el nombre original.
    $nombreArchivo = bin2hex(random_bytes(8)) . '.' . $extension;
    $rutaCompleta = $directorioDestino . $nombreArchivo;

    if (!move_uploaded_file($archivo['tmp_name'], $rutaCompleta)) {
        jsonError('No se pudo guardar la imagen en el servidor.', 500);
    }

    return 'uploads/candidatos/' . $nombreArchivo;
}

$directorioDestino = __DIR__ . '/../uploads/candidatos/';
if (!is_dir($directorioDestino)) {
    mkdir($directorioDestino, 0755, true);
}

$fotoUrl = guardarImagen($_FILES['foto'] ?? null, $directorioDestino);
$logoUrl = guardarImagen($_FILES['logo'] ?? null, $directorioDestino);

$pdo = getConnection();

// Verificar que el cargo exista Y pertenezca al colegio del admin en sesión
// (si no, cualquiera podría adjuntar candidatos a cargos de otro colegio
// adivinando el id_cargo).
$stmt = $pdo->prepare('SELECT id FROM cargos WHERE id = :id_cargo AND id_colegio = :id_colegio');
$stmt->execute(['id_cargo' => $idCargo, 'id_colegio' => $admin['id_colegio']]);
if (!$stmt->fetch()) {
    jsonError('El cargo indicado no existe.', 404);
}

$stmt = $pdo->prepare('
    INSERT INTO candidatos (id_cargo, nombre, descripcion, foto_url, logo_url, id_colegio)
    VALUES (:id_cargo, :nombre, :descripcion, :foto_url, :logo_url, :id_colegio)
    RETURNING id
');
$stmt->execute([
    'id_cargo' => $idCargo,
    'nombre' => $nombre,
    'descripcion' => $descripcion,
    'foto_url' => $fotoUrl,
    'logo_url' => $logoUrl,
    'id_colegio' => $admin['id_colegio'],
]);

jsonResponse([
    'id' => $stmt->fetchColumn(),
    'foto_url' => $fotoUrl,
    'logo_url' => $logoUrl,
    'mensaje' => 'Candidato guardado correctamente.',
], 201);
