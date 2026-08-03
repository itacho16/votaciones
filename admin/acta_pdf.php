<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$admin = requireAdminAuth();

$idEleccion = (int) ($_GET['id_eleccion'] ?? 0);
if ($idEleccion <= 0) {
    jsonError('Debes indicar id_eleccion.', 422);
}

$pdo = getConnection();

// 1. Datos de la elección (solo si pertenece al colegio del admin en sesión)
$stmt = $pdo->prepare('SELECT id, titulo, descripcion, fecha_inicio, fecha_fin, estado FROM elecciones WHERE id = :id AND id_colegio = :id_colegio');
$stmt->execute(['id' => $idEleccion, 'id_colegio' => $admin['id_colegio']]);
$eleccion = $stmt->fetch();

if (!$eleccion) {
    jsonError('La elección indicada no existe.', 404);
}
if ($eleccion['estado'] !== 'cerrada') {
    jsonError('El acta solo puede generarse cuando la elección está cerrada.', 422);
}

// 2. Miembros de mesa — exige Presidente y Secretario, como en una mesa real
$stmt = $pdo->prepare('
    SELECT nombre_completo, cargo_mesa, documento_identidad
    FROM miembros_mesa
    WHERE id_eleccion = :id_eleccion
    ORDER BY orden ASC, id ASC
');
$stmt->execute(['id_eleccion' => $idEleccion]);
$miembrosMesa = $stmt->fetchAll();

$cargosPresentes = array_column($miembrosMesa, 'cargo_mesa');
if (!in_array('Presidente', $cargosPresentes, true) || !in_array('Secretario', $cargosPresentes, true)) {
    jsonError('Debes registrar al menos un Presidente y un Secretario de mesa antes de generar el acta.', 422);
}

// 3. Cargos, candidatos y resultados (misma lógica que api/resultados.php)
$stmtCargos = $pdo->prepare('SELECT id, nombre FROM cargos WHERE id_eleccion = :id ORDER BY orden ASC, id ASC');
$stmtCargos->execute(['id' => $idEleccion]);
$cargos = $stmtCargos->fetchAll();

$stmtResultados = $pdo->prepare('
    SELECT ca.id, ca.nombre, COUNT(v.id) AS votos
    FROM candidatos ca
    LEFT JOIN votos v ON v.id_candidato = ca.id
    WHERE ca.id_cargo = :id_cargo
    GROUP BY ca.id, ca.nombre
    ORDER BY votos DESC
');

$stmtTotalEstudiantes = $pdo->prepare('SELECT COUNT(*) FROM estudiantes WHERE activo = TRUE AND id_colegio = :id_colegio');
$stmtTotalEstudiantes->execute(['id_colegio' => $admin['id_colegio']]);
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
}
unset($cargo);

// 4. Construir el HTML del acta (Dompdf soporta un subconjunto de CSS,
//    por eso se usa maquetación simple basada en tablas/bloques)
function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$fechaGeneracion = (new DateTime())->format('d/m/Y H:i');
$fechaCierre = (new DateTime($eleccion['fecha_fin']))->format('d/m/Y H:i');

$cargosLabel = ['Presidente' => 'Presidente de Mesa', 'Secretario' => 'Secretario de Mesa', 'Miembro' => 'Miembro de Mesa'];

$html = '<html><head><meta charset="UTF-8"><style>
    body{ font-family: DejaVu Sans, sans-serif; color:#262524; font-size:11px; }
    h1{ font-size:18px; color:#17223B; margin:0 0 4px; text-align:center; }
    .subtitulo{ text-align:center; font-size:11px; color:#565248; margin-bottom:18px; }
    .sello{ text-align:center; font-size:9px; letter-spacing:2px; color:#B8933F; text-transform:uppercase; margin-bottom:6px; }
    table{ width:100%; border-collapse:collapse; margin-bottom:16px; }
    th, td{ border:1px solid #D9D4C4; padding:6px 8px; text-align:left; font-size:10.5px; }
    th{ background:#EFEDE3; color:#17223B; text-transform:uppercase; font-size:9px; letter-spacing:0.5px; }
    .cargo-titulo{ background:#17223B; color:#F1EFE6; padding:6px 10px; font-size:12px; font-weight:bold; margin-top:14px; }
    .ganador{ font-weight:bold; color:#9E2A2B; }
    .footer{ margin-top:30px; }
    .firma{ display:inline-block; width:30%; text-align:center; margin-right:2%; vertical-align:top; }
    .firma .linea{ border-top:1px solid #262524; margin-top:40px; padding-top:4px; font-size:9.5px; }
    .meta-box{ background:#F8F6EF; border:1px solid #D9D4C4; padding:8px 12px; margin-bottom:16px; font-size:10px; }
</style></head><body>';

$html .= '<div class="sello">Acta Oficial de Resultados</div>';
$html .= '<h1>' . esc($eleccion['titulo']) . '</h1>';
$html .= '<div class="subtitulo">' . esc($eleccion['descripcion'] ?? '') . '</div>';

$html .= '<div class="meta-box">
    <strong>Fecha de cierre:</strong> ' . esc($fechaCierre) . ' &nbsp;&nbsp;|&nbsp;&nbsp;
    <strong>Total de estudiantes hábiles:</strong> ' . $totalEstudiantes . ' &nbsp;&nbsp;|&nbsp;&nbsp;
    <strong>Acta generada el:</strong> ' . esc($fechaGeneracion) . '
</div>';

// Miembros de mesa
$html .= '<table><tr><th>Cargo en mesa</th><th>Nombre completo</th><th>Documento de identidad</th></tr>';
foreach ($miembrosMesa as $m) {
    $html .= '<tr><td>' . esc($cargosLabel[$m['cargo_mesa']] ?? $m['cargo_mesa']) . '</td><td>' . esc($m['nombre_completo']) . '</td><td>' . esc($m['documento_identidad'] ?? '—') . '</td></tr>';
}
$html .= '</table>';

// Resultados por cargo
foreach ($cargos as $cargo) {
    $html .= '<div class="cargo-titulo">' . esc($cargo['nombre']) . ' — Total de votos: ' . $cargo['total_votos'] . '</div>';
    $html .= '<table><tr><th>Candidato</th><th>Votos</th><th>Porcentaje</th></tr>';
    foreach ($cargo['candidatos'] as $i => $c) {
        $claseGanador = ($i === 0 && $c['votos'] > 0) ? ' class="ganador"' : '';
        $html .= "<tr{$claseGanador}><td>" . esc($c['nombre']) . '</td><td>' . $c['votos'] . '</td><td>' . $c['porcentaje'] . '%</td></tr>';
    }
    $html .= '</table>';
}

// Firmas de los miembros de mesa
$html .= '<div class="footer">';
foreach ($miembrosMesa as $m) {
    $html .= '<div class="firma"><div class="linea">' . esc($m['nombre_completo']) . '<br>' . esc($cargosLabel[$m['cargo_mesa']] ?? $m['cargo_mesa']) . '</div></div>';
}
$html .= '</div>';

$html .= '</body></html>';

// 5. Renderizar el PDF
$options = new Options();
$options->set('isRemoteEnabled', false); // no cargar recursos externos, por seguridad

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$nombreArchivo = 'acta_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $eleccion['titulo']) . '.pdf';

$dompdf->stream($nombreArchivo, ['Attachment' => true]);
