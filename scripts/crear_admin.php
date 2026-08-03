<?php
declare(strict_types=1);

/**
 * Uso desde la terminal, parado en la carpeta backend/:
 *   php scripts/crear_admin.php correo@colegio.edu.pe "MiContraseñaSegura123" "Nombre Completo" slug-del-colegio
 *
 * El colegio debe existir previamente (ver scripts/crear_colegio.php).
 * El admin queda asociado a ese colegio: en el login solo pide
 * email+password, y de ahí en adelante todo lo que haga (elecciones,
 * candidatos, estudiantes, etc.) queda automáticamente aislado a ese
 * colegio, sin que el panel tenga que preguntar nada más.
 *
 * No existe un endpoint web de "registro" a propósito: los administradores
 * se crean a mano por alguien con acceso al servidor, para evitar que
 * cualquiera pueda darse de alta como admin del sistema de votación.
 */

require_once __DIR__ . '/../config/database.php';

[$email, $password, $nombre, $slugColegio] = array_pad(array_slice($argv, 1), 4, null);

if ($email === null || $password === null || $slugColegio === null) {
    fwrite(STDERR, "Uso: php crear_admin.php correo@colegio.edu.pe contraseña \"Nombre Completo\" slug-del-colegio\n");
    fwrite(STDERR, "(Si el colegio todavía no existe, créalo primero con scripts/crear_colegio.php)\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "La contraseña debe tener al menos 8 caracteres.\n");
    exit(1);
}

$nombre = $nombre ?? 'Administrador';
$pdo = getConnection();

$stmtColegio = $pdo->prepare('SELECT id FROM colegios WHERE slug = :slug');
$stmtColegio->execute(['slug' => $slugColegio]);
$idColegio = $stmtColegio->fetchColumn();

if (!$idColegio) {
    fwrite(STDERR, "No existe ningún colegio con el slug \"{$slugColegio}\". Créalo primero con scripts/crear_colegio.php.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare('
        INSERT INTO usuarios_admin (nombre, email, password_hash, rol, id_colegio)
        VALUES (:nombre, :email, :hash, :rol, :id_colegio)
        RETURNING id
    ');
    $stmt->execute([
        'nombre' => $nombre,
        'email' => $email,
        'hash' => $hash,
        'rol' => 'superadmin',
        'id_colegio' => $idColegio,
    ]);
    $id = $stmt->fetchColumn();
    echo "Administrador creado correctamente (id {$id}, colegio \"{$slugColegio}\").\n";
} catch (PDOException $e) {
    if ($e->getCode() === '23505') {
        fwrite(STDERR, "Ya existe un administrador con ese correo.\n");
    } else {
        fwrite(STDERR, "Error al crear el administrador: {$e->getMessage()}\n");
    }
    exit(1);
}
