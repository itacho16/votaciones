<?php
declare(strict_types=1);

/**
 * Uso desde la terminal, parado en la carpeta backend/:
 *   php scripts/crear_colegio.php "IE 1234 San Martín" san-martin
 *
 * El slug es solo un identificador interno corto (para logs, reportes,
 * futuras urls); no se usa para login. Debe ser único.
 */

require_once __DIR__ . '/../config/database.php';

[$nombre, $slug] = array_pad(array_slice($argv, 1), 2, null);

if ($nombre === null || $slug === null) {
    fwrite(STDERR, "Uso: php crear_colegio.php \"Nombre del colegio\" slug-corto\n");
    exit(1);
}

if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
    fwrite(STDERR, "El slug solo puede tener minúsculas, números y guiones (ej. san-martin).\n");
    exit(1);
}

$pdo = getConnection();

try {
    $stmt = $pdo->prepare('
        INSERT INTO colegios (nombre, slug)
        VALUES (:nombre, :slug)
        RETURNING id
    ');
    $stmt->execute(['nombre' => $nombre, 'slug' => $slug]);
    $id = $stmt->fetchColumn();
    echo "Colegio creado correctamente (id {$id}, slug \"{$slug}\").\n";
    echo "Ahora crea su primer administrador con:\n";
    echo "  php scripts/crear_admin.php correo@colegio.edu.pe \"contraseña\" \"Nombre Completo\" {$slug}\n";
} catch (PDOException $e) {
    if ($e->getCode() === '23505') {
        fwrite(STDERR, "Ya existe un colegio con ese slug.\n");
    } else {
        fwrite(STDERR, "Error al crear el colegio: {$e->getMessage()}\n");
    }
    exit(1);
}
