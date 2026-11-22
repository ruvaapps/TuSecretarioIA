<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../turnos_helper.php';

const PUENTE_SMS_TOKEN = 'CAMBIA_ESTE_TOKEN_SECRETO';

function logSmsEntrante(string $msg): void {
    $linea = "[" . date('Y-m-d H:i:s') . "] [SMS_ENTRANTE] " . $msg . PHP_EOL;
    @file_put_contents(__DIR__ . '/../logs/sms_entrante.log', $linea, FILE_APPEND | LOCK_EX);
}
/*
$tokenHeader = $_SERVER['HTTP_X_PUENTE_TOKEN'] ?? '';
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '[]', true);
if (!is_array($data)) {
    $data = $_POST;
}

$tokenBody = (string)($data['token'] ?? '');
if (!hash_equals(PUENTE_SMS_TOKEN, $tokenHeader ?: $tokenBody)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token no válido']);
    exit;
}
*/
$telefonoRaw = trim((string)($data['telefono'] ?? ''));
$mensajeRaw  = trim((string)($data['mensaje'] ?? ''));

$digits = preg_replace('/\D+/', '', $telefonoRaw) ?? '';
if ($digits === '' || $mensajeRaw === '') {
    echo json_encode(['status' => 'error', 'message' => 'Teléfono y mensaje requeridos']);
    exit;
}

// Variantes habituales de número en AR (con/sin 54, con/sin 9) para hacer match con saas_clientes
$candidatosTel = [$digits];
if (preg_match('/^549(\d{10})$/', $digits, $m)) {
    $candidatosTel[] = '54' . $m[1];
    $candidatosTel[] = $m[1];
} elseif (preg_match('/^54(\d{10})$/', $digits, $m)) {
    $candidatosTel[] = '549' . $m[1];
    $candidatosTel[] = $m[1];
} elseif (strlen($digits) === 10) {
    $candidatosTel[] = '54' . $digits;
    $candidatosTel[] = '549' . $digits;
}
$candidatosTel = array_values(array_unique($candidatosTel));

$textoNorm = mb_strtolower($mensajeRaw, 'UTF-8');
$esCancelar = (bool)preg_match('/\b(cancelar|cancela|anular|anula|no voy|no puedo|suspender)\b/u', $textoNorm);
$esConfirmar = (bool)preg_match('/\b(confirmo|confirmar|confirma|voy|ok|si)\b/u', $textoNorm);

if (!$esCancelar && !$esConfirmar) {
    logSmsEntrante("Mensaje sin comando reconocido de {$digits}: {$mensajeRaw}");
    echo json_encode(['status' => 'ignored', 'message' => 'Sin comando reconocido']);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($candidatosTel), '?'));

    // Si hay múltiples turnos/negocios, priorizamos el negocio del último SMS enviado a ese número
    $sql = "
        SELECT
            t.id AS turno_id,
            t.negocio_id,
            t.cliente_id,
            t.fecha_hora_inicio,
            t.estado,
            c.nombre AS cliente_nombre,
            c.telefono AS cliente_telefono,
            n.nombre AS negocio_nombre
        FROM saas_turnos t
        INNER JOIN saas_clientes c ON c.id = t.cliente_id
        INNER JOIN saas_negocios n ON n.id = t.negocio_id
        WHERE REPLACE(REPLACE(REPLACE(c.telefono, '+', ''), ' ', ''), '-', '') IN ($placeholders)
          AND LOWER(COALESCE(t.estado, '')) NOT IN ('cancelado', 'cancelada', 'completado', 'completada')
          AND t.fecha_hora_inicio >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
        ORDER BY (
            SELECT MAX(q.id)
            FROM saas_sms_cola q
            WHERE q.negocio_id = t.negocio_id
              AND q.turno_id = t.id
              AND q.estado = 'enviado'
        ) DESC, t.fecha_hora_inicio ASC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($candidatosTel);
    $turno = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$turno) {
        logSmsEntrante("No se encontró turno activo para {$digits} (msj: {$mensajeRaw})");
        echo json_encode(['status' => 'not_found', 'message' => 'No se encontró turno activo']);
        exit;
    }

    $turnoId   = (int)$turno['turno_id'];
    $negocioId = (int)$turno['negocio_id'];

    if ($esCancelar) {
        $resCancel = cancelarTurnoCompleto($pdo, $turnoId, $negocioId);
        logSmsEntrante("Turno {$turnoId} (negocio {$negocioId}) cancelado por SMS de {$digits}");
        echo json_encode([
            'status'   => 'success',
            'accion'   => 'cancelado',
            'turno_id' => $turnoId,
            'detalle'  => $resCancel
        ]);
        exit;
    }

    if ($esConfirmar) {
        $stmtUpd = $pdo->prepare("UPDATE saas_turnos SET estado = 'confirmado' WHERE id = ? AND negocio_id = ?");
        $stmtUpd->execute([$turnoId, $negocioId]);
        logSmsEntrante("Turno {$turnoId} (negocio {$negocioId}) confirmado por SMS de {$digits}");
        echo json_encode([
            'status'   => 'success',
            'accion'   => 'confirmado',
            'turno_id' => $turnoId
        ]);
        exit;
    }
} catch (Throwable $e) {
    logSmsEntrante("Error procesando SMS de {$digits}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error interno']);
}