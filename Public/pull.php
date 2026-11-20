<?php
declare(strict_types=1);

require_once __DIR__ . '/config_sms.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$input = readJsonInput();
$dispositivoId = trim((string)($input['dispositivo_id'] ?? ''));
if ($dispositivoId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'dispositivo_id es obligatorio']);
    exit;
}

$ventanaMinutos = max(1, min(1440, (int)($input['ventana_minutos'] ?? 180)));
$limite         = max(1, min(200, (int)($input['limite'] ?? 50)));
$idsLocales     = array_values(array_unique(array_filter(
    array_map('intval', (array)($input['ids_locales_activos'] ?? [])),
    static fn(int $id): bool => $id > 0
)));

$pdo = getPdo();
verifyDeviceToken($pdo, $dispositivoId);

try {
    $pdo->beginTransaction();

    // 1. Buscar mensajes listos para reservar dentro de la ventana
    // Incluye 'pendiente' y 'reservado' cuya reserva venció (por corte del teléfono)
    $sqlSelect = "
        SELECT
            id,
            negocio_id,
            turno_id,
            telefono,
            mensaje,
            send_at,
            expires_at,
            intentos
        FROM saas_sms_cola
        WHERE (
                estado = 'pendiente'
                OR (estado = 'reservado' AND reservado_hasta IS NOT NULL AND reservado_hasta < NOW())
              )
          AND send_at <= DATE_ADD(NOW(), INTERVAL :ventana MINUTE)
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY send_at ASC, id ASC
        LIMIT :limite
        FOR UPDATE SKIP LOCKED
    ";

    $stmtSelect = $pdo->prepare($sqlSelect);
    $stmtSelect->bindValue(':ventana', $ventanaMinutos, PDO::PARAM_INT);
    $stmtSelect->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmtSelect->execute();
    $mensajes = $stmtSelect->fetchAll();

    if ($mensajes !== []) {
        $idsParaReservar = array_map(static fn(array $m): int => (int)$m['id'], $mensajes);
        $placeholders = implode(',', array_fill(0, count($idsParaReservar), '?'));

        $sqlReserve = "
            UPDATE saas_sms_cola
            SET
                estado = 'reservado',
                dispositivo_id = ?,
                reservado_hasta = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
            WHERE id IN ({$placeholders})
        ";

        $stmtReserve = $pdo->prepare($sqlReserve);
        $params = array_merge([$dispositivoId], $idsParaReservar);
        $stmtReserve->execute($params);
    }

    // 2. Detectar cuáles de los IDs activos en el teléfono ya fueron cancelados en el servidor
    $canceladosIds = [];
    if ($idsLocales !== []) {
        $placeholdersCancel = implode(',', array_fill(0, count($idsLocales), '?'));
        $sqlCancel = "
            SELECT id
            FROM saas_sms_cola
            WHERE id IN ({$placeholdersCancel})
              AND estado = 'cancelado'
        ";
        $stmtCancel = $pdo->prepare($sqlCancel);
        $stmtCancel->execute($idsLocales);
        $canceladosIds = array_map(
            static fn(array $r): int => (int)$r['id'],
            $stmtCancel->fetchAll()
        );
    }

    $horaServidor = $pdo->query('SELECT NOW()')->fetchColumn();
    $pdo->commit();

    $mensajesFormateados = array_map(static fn(array $m): array => [
        'id'         => (int)$m['id'],
        'negocio_id' => (int)$m['negocio_id'],
        'turno_id'   => $m['turno_id'] !== null ? (int)$m['turno_id'] : null,
        'telefono'   => (string)$m['telefono'],
        'mensaje'    => (string)$m['mensaje'],
        'send_at'    => (string)$m['send_at'],
        'expires_at' => $m['expires_at'] !== null ? (string)$m['expires_at'] : null,
        'intentos'   => (int)$m['intentos'],
    ], $mensajes);

    echo json_encode([
        'ok'            => true,
        'servidor_hora' => (string)$horaServidor,
        'mensajes'      => $mensajesFormateados,
        'cancelados_ids'=> $canceladosIds,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('SMS pull error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno en pull']);
}