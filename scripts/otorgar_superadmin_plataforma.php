<?php
declare(strict_types=1);

/**
 * Otorga (o quita) el acceso de "superadmin de plataforma" a una cuenta
 * de administrador YA EXISTENTE (creada antes con scripts/crear_admin.php).
 *
 * Uso desde la terminal, parado en la carpeta backend/:
 *   php scripts/otorgar_superadmin_plataforma.php admin@colegio.edu.pe activar
 *   php scripts/otorgar_superadmin_plataforma.php admin@colegio.edu.pe desactivar
 *
 * Esta cuenta seguirá funcionando normal en panel_admin.html para su
 * propio colegio, y ADEMÁS podrá entrar a panel_superadmin.html para ver
 * todos los colegios registrados. No existe esta opción por la web a
 * propósito: otorgarla debe ser una acción deliberada de alguien con
 * acceso al servidor, no un botón dentro del panel.
 */

require_once __DIR__ . '/../config/database.php';

[$email, $accion] = array_pad(array_slice($argv, 1), 2, null);

if ($email === null || !in_array($accion, ['activar', 'desactivar'], true)) {
    fwrite(STDERR, "Uso: php otorgar_superadmin_plataforma.php correo@colegio.edu.pe activar|desactivar\n");
    exit(1);
}

$pdo = getConnection();

$stmt = $pdo->prepare('
    UPDATE usuarios_admin
    SET es_superadmin_plataforma = :valor
    WHERE email = :email
    RETURNING id, nombre
');
$stmt->execute([
    'valor' => $accion === 'activar',
    'email' => $email,
]);
$fila = $stmt->fetch();

if (!$fila) {
    fwrite(STDERR, "No existe ningún administrador con el email \"{$email}\".\n");
    exit(1);
}

$verbo = $accion === 'activar' ? 'otorgado' : 'retirado';
echo "Acceso de superadmin de plataforma {$verbo} a {$fila['nombre']} (id {$fila['id']}).\n";
