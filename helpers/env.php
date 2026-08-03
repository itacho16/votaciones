<?php
declare(strict_types=1);

/**
 * Carga variables desde un archivo .env al entorno de PHP,
 * sin depender de ninguna librería externa (vlucas/phpdotenv, etc).
 * Si la variable ya existe en el entorno (ej. configurada en el servidor),
 * no la sobreescribe.
 */
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lineas = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $linea) {
        $linea = trim($linea);

        if ($linea === '' || str_starts_with($linea, '#')) {
            continue;
        }

        [$clave, $valor] = array_pad(explode('=', $linea, 2), 2, '');
        $clave = trim($clave);
        $valor = trim($valor, " \t\n\r\0\x0B\"'");

        if ($clave !== '' && getenv($clave) === false) {
            putenv("{$clave}={$valor}");
        }
    }
}
