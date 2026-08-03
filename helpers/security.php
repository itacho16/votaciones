<?php
declare(strict_types=1);

/**
 * Genera un token de acceso único con formato XXXX-XXXX
 * (mayúsculas, fácil de leer/transcribir pero difícil de adivinar).
 */
function generarTokenAcceso(): string
{
    $hex = strtoupper(bin2hex(random_bytes(4))); // 8 caracteres hex
    return substr($hex, 0, 4) . '-' . substr($hex, 4, 4);
}
