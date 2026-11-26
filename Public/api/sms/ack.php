<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function logAckDebug(string $msg, array $ctx = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($ctx !== []) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents(__DIR__ . '/ack_debug.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        logAckDebug('FATAL_SHUTDOWN', $err);
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo json_encode([
            'ok'    => false,
            'error' => 'Error fatal en ack',
            'debug' => $err['message'] . ' en ' . $err['file'] . ':' . $err['line'],
        ]);
    }
});

try {
    logAckDebug('Inicio ack.php');
    require_once __DIR__ . '/../../config_sms.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logAckDebug('Método no permitido', ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
        exit;
    }

    $input = readJsonInput();
    $dispositivoId = trim((string)($input['dispositivo_id'] ?? ''));
    $resultados    = $input['resultados'] ?? null;

    logAckDebug('Input leído', [
        'dispositivo_id'    => $dispositivoId,
        'resultados_es_array' => is_array($resultados),
        'cantidad_items'    => is_array($resultados) ? count($resultados) : 0,
        'items_raw'         => is_array($resultados) ? $resultados : null,
    ]);

    if ($dispositivoId === '' || !is_array($resultados)) {
        logAckDebug('Validación 422 fallida', [
            'dispositivo_vacio' => $dispositivoId === '',
            'resultados_tipo'   => gettype($resultados),
        ]);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'dispositivo_id y resultados son obligatorios']);
        exit;
    }

    $pdo = getPdo();
    logAckDebug('PDO conectado');

    $dispositivo = verifyDeviceToken($pdo, $dispositivoId);
    $negocioId = (int)$dispositivo['negocio_id'];
    logAckDebug('Dispositivo verificado', [
        'dispositivo_id' => $dispositivoId,
        'negocio_id'     => $negocioId,
    ]);

    $estadosPermitidos = ['enviado', 'fallido'];
    $procesadosIds = [];
    $noActualizados = [];

    $pdo->beginTransaction();

    $sqlUpdate = "
        UPDATE saas_sms_cola
        SET
            estado = :estado,
            dispositivo_id = :dispositivo_id,
            reservado_hasta = NULL,
            intentos = :intentos,
            enviado_at = :enviado_at,
            ultimo_error = :ultimo_error,
            sincronizado_at = NOW()
        WHERE id = :id
          AND negocio_id = :negocio_id
          AND estado <> 'cancelado'
    ";
    $stmt = $pdo->prepare($sqlUpdate);

    foreach ($resultados as $idx => $item) {
        if (!is_array($item)) {
            logAckDebug('Item descartado: no es array', ['idx' => $idx, 'item' => $item]);
            continue;
        }

        $id     = (int)($item['id'] ?? 0);
        $estado = (string)($item['estado'] ?? '');
        if ($id <= 0 || !in_array($estado, $estadosPermitidos, true)) {
            logAckDebug('Item descartado: id o estado inválido', [
                'idx'    => $idx,
                'id'     => $id,
                'estado' => $estado,
            ]);
            continue;
        }

        $intentos    = max(0, min(255, (int)($item['intentos'] ?? 1)));
        $enviadoAt   = isset($item['enviado_at']) && $item['enviado_at'] !== ''
            ? (string)$item['enviado_at']
            : null;
        $ultimoError = isset($item['ultimo_error']) && $item['ultimo_error'] !== ''
            ? mb_substr((string)$item['ultimo_error'], 0, 255)
            : null;

        if ($estado === 'enviado' && $enviadoAt === null) {
            $enviadoAt = date('Y-m-d H:i:s');
        }

        $paramsUpdate = [
            ':estado'         => $estado,
            ':dispositivo_id' => $dispositivoId,
            ':negocio_id'     => $negocioId,
            ':intentos'       => $intentos,
            ':enviado_at'     => $enviadoAt,
            ':ultimo_error'   => $ultimoError,
            ':id'             => $id,
        ];

        $stmt->execute($paramsUpdate);
        $afectadas = $stmt->rowCount();

        logAckDebug('UPDATE ejecutado en saas_sms_cola', [
            'id'         => $id,
            'estado'     => $estado,
            'row_count'  => $afectadas,
            'enviado_at' => $enviadoAt,
            'intentos'   => $intentos,
        ]);

        if ($afectadas > 0) {
            $procesadosIds[] = $id;
        } else {
            $diag = $pdo->prepare('SELECT id, negocio_id, estado, dispositivo_id, reservado_hasta FROM saas_sms_cola WHERE id = ?');
            $diag->execute([$id]);
            $filaActual = $diag->fetch(PDO::FETCH_ASSOC);

            logAckDebug('Fila NO actualizada en saas_sms_cola', [
                'id'            => $id,
                'negocio_id_ack'=> $negocioId,
                'fila_actual'   => $filaActual ?: null,
            ]);
            $noActualizados[] = $id;
        }
    }

    if ($procesadosIds !== []) {
        $idsUnicos = array_values(array_unique($procesadosIds));
        $placeholders = implode(',', array_fill(0, count($idsUnicos), '?'));

        $sqlCampEnvio = "
            UPDATE saas_campanas_envios e
            INNER JOIN saas_sms_cola q
                ON q.referencia_id = e.id
               AND q.tipo = 'promo'
            SET
                e.estado = CASE
                    WHEN q.estado = 'enviado' THEN 'enviado'
                    WHEN q.estado = 'fallido' THEN 'fallido'
                    WHEN q.estado = 'cancelado' THEN 'cancelado'
                    ELSE e.estado
                END,
                e.enviado_el = CASE
                    WHEN q.estado = 'enviado' AND e.enviado_el IS NULL THEN COALESCE(q.enviado_at, NOW())
                    ELSE e.enviado_el
                END,
                e.error_mensaje = CASE
                    WHEN q.estado = 'fallido' THEN COALESCE(q.ultimo_error, e.error_mensaje)
                    ELSE e.error_mensaje
                END
            WHERE q.id IN ({$placeholders})
        ";
        $stmtCampEnvio = $pdo->prepare($sqlCampEnvio);
        $stmtCampEnvio->execute($idsUnicos);
        logAckDebug('Campañas envios actualizadas', [
            'ids'       => $idsUnicos,
            'row_count' => $stmtCampEnvio->rowCount(),
        ]);

        $afectadasPromos = $pdo->exec("
            UPDATE saas_campanas_promos p
            SET p.estado = 'completada'
            WHERE p.estado = 'en_proceso'
              AND NOT EXISTS (
                  SELECT 1
                  FROM saas_campanas_envios e
                  WHERE e.campana_id = p.id
                    AND e.estado IN ('pendiente', 'encolado')
              )
        ");
        logAckDebug('Campañas promos evaluadas', ['row_count' => $afectadasPromos]);
    }

    $pdo->commit();

    $respuesta = [
        'ok'              => true,
        'procesados_ids'  => array_values(array_unique($procesadosIds)),
        'no_actualizados' => array_values(array_unique($noActualizados)),
    ];
    logAckDebug('ACK completado', $respuesta);

    echo json_encode($respuesta);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logAckDebug('EXCEPCION en ack.php', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    error_log('SMS ack error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Error interno en ack',
        'debug' => $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine(),
    ]);
}