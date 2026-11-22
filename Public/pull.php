<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function logPullDebug(string $msg, array $ctx = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($ctx !== []) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents(__DIR__ . '/pull_debug.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        logPullDebug('FATAL_SHUTDOWN', $err);
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo json_encode([
            'ok' => false,
            'error' => 'Error fatal en pull',
            'debug' => $err['message'] . ' en ' . $err['file'] . ':' . $err['line'],
        ]);
    }
});

try {
    logPullDebug('Inicio pull.php');
    require_once __DIR__ . '/config_sms.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
        exit;
    }

    $input = readJsonInput();
    logPullDebug('Input leído', ['has_device' => !empty($input['dispositivo_id'])]);

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
    logPullDebug('PDO conectado');

    verifyDeviceToken($pdo, $dispositivoId);
    logPullDebug('Token verificado', ['dispositivo_id' => $dispositivoId]);

    $pdo->beginTransaction();

    $sqlSelect = "
        SELECT
            id,
            negocio_id,
            turno_id,
            tipo,
            prioridad,
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
        ORDER BY prioridad ASC, send_at ASC, id ASC
        LIMIT :limite
        FOR UPDATE SKIP LOCKED
    ";

    $stmtSelect = $pdo->prepare($sqlSelect);
    $stmtSelect->bindValue(':ventana', $ventanaMinutos, PDO::PARAM_INT);
    $stmtSelect->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmtSelect->execute();
    $mensajes = $stmtSelect->fetchAll();
    logPullDebug('Select ejecutado', ['count' => count($mensajes)]);

    if ($mensajes !== []) {
        $idsParaReservar = array_map(static fn(array $m): int => (int)$m['id'], $mensajes);
        $placeholders = implode(',', array_fill(0, count($idsParaReservar), '?'));

        $sqlReserve = "
            UPDATE saas_sms_cola
            SET
                estado = 'reservado',
                dispositivo_id = ?,
                reservado_hasta = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
            WHERE id IN ({$placeholders})
        ";

        $stmtReserve = $pdo->prepare($sqlReserve);
        $params = array_merge([$dispositivoId], $idsParaReservar);
        $stmtReserve->execute($params);
        logPullDebug('Reserva actualizada', ['ids' => $idsParaReservar]);
    }

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
        'tipo'       => (string)($m['tipo'] ?? 'recordatorio'),
        'prioridad'  => (int)($m['prioridad'] ?? 10),
        'telefono'   => (string)$m['telefono'],
        'mensaje'    => (string)$m['mensaje'],
        'send_at'    => (string)$m['send_at'],
        'expires_at' => $m['expires_at'] !== null ? (string)$m['expires_at'] : null,
        'intentos'   => (int)$m['intentos'],
    ], $mensajes);

    echo json_encode([
        'ok'             => true,
        'servidor_hora'  => (string)$horaServidor,
        'mensajes'       => $mensajesFormateados,
        'cancelados_ids' => $canceladosIds,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logPullDebug('EXCEPCION', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Error interno en pull',
        'debug' => $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine(),
    ]);
}