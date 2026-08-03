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
$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($email === '' || $password === '') {
    jsonError('Debes ingresar tu correo y contraseña.', 422);
}

$pdo = getConnection();

$stmt = $pdo->prepare('
    SELECT ua.id, ua.nombre, ua.email, ua.password_hash, ua.rol, ua.id_colegio,
           ua.es_superadmin_plataforma, c.nombre AS colegio_nombre
    FROM usuarios_admin ua
    JOIN colegios c ON c.id = ua.id_colegio
    WHERE ua.email = :email AND c.activo = TRUE
');
$stmt->execute(['email' => $email]);
$admin = $stmt->fetch();

// Mensaje idéntico si el correo no existe o si la contraseña es incorrecta:
// no revelamos cuál de las dos cosas falló, para no facilitar
// la enumeración de correos registrados.
if (!$admin || !password_verify($password, $admin['password_hash'])) {
    jsonError('Correo o contraseña incorrectos.', 401);
}

// Regenerar el id de sesión al iniciar sesión previene ataques
// de fijación de sesión (session fixation).
session_regenerate_id(true);

$_SESSION['admin_id'] = $admin['id'];
$_SESSION['admin_nombre'] = $admin['nombre'];
$_SESSION['admin_rol'] = $admin['rol'];
$_SESSION['admin_id_colegio'] = $admin['id_colegio'];
$_SESSION['admin_es_plataforma'] = (bool) $admin['es_superadmin_plataforma'];

jsonResponse([
    'admin' => [
        'nombre' => $admin['nombre'],
        'email' => $admin['email'],
        'rol' => $admin['rol'],
        'colegio' => $admin['colegio_nombre'],
        'es_plataforma' => (bool) $admin['es_superadmin_plataforma'],
    ],
]);
