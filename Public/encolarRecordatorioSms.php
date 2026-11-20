<?php
declare(strict_types=1);

require_once __DIR__ . '/config_sms.php';

function logCronSms(string $level, string $message, array $context = []): void
{
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $logFile = $logDir . '/cron_sms_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $ctxStr = $context !== []
        ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';

    $linea = "[{$timestamp}] [{$level}] {$message}{$ctxStr}" . PHP_EOL;
    @file_put_contents($logFile, $linea, FILE_APPEND | LOCK_EX);

    if (PHP_SAPI === 'cli') {
        if ($level === 'ERROR') {
            fwrite(STDERR, $linea);
        } else {
            fwrite(STDOUT, $linea);
        }
    }
}

function encolarRecordatorioSms(
    PDO $pdo,
    int $negocioId,
    ?int $turnoId,
    ?int $clienteId,
    string $telefono,
    string $mensaje,
    string $sendAt,
    ?string $expiresAt = null
): int {
    $telefonoLimpio = preg_replace('/[^0-9]/', '', $telefono) ?? '';

    $sql = "
        INSERT INTO saas_sms_cola (
            negocio_id,
            turno_id,
            cliente_id,
            telefono,
            mensaje,
            send_at,
            expires_at,
            estado
        ) VALUES (
            :negocio_id,
            :turno_id,
            :cliente_id,
            :telefono,
            :mensaje,
            :send_at,
            :expires_at,
            'pendiente'
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':negocio_id' => $negocioId,
        ':turno_id'   => $turnoId,
        ':cliente_id' => $clienteId,
        ':telefono'   => $telefonoLimpio,
        ':mensaje'    => $mensaje,
        ':send_at'    => $sendAt,
        ':expires_at' => $expiresAt,
    ]);

    return (int)$pdo->lastInsertId();
}

function encolarTurnosProximos(PDO $pdo, int $horasAnticipacion = 2, int $ventanaConsultaHoras = 6): int
{
    logCronSms('INFO', 'Buscando turnos próximos para encolar SMS', [
        'horas_anticipacion'   => $horasAnticipacion,
        'ventana_consulta_hs'  => $ventanaConsultaHoras,
    ]);

    $sql = "
        SELECT
            t.id AS turno_id,
            t.negocio_id,
            t.cliente_id,
            t.fecha_hora,
            c.nombre AS cliente_nombre,
            c.telefono,
            n.nombre AS negocio_nombre
        FROM saas_turnos t
        INNER JOIN saas_clientes c ON c.id = t.cliente_id
        INNER JOIN saas_negocios n ON n.id = t.negocio_id
        WHERE t.estado IN ('confirmado', 'programado')
          AND t.fecha_hora > NOW()
          AND t.fecha_hora <= DATE_ADD(NOW(), INTERVAL :ventana_horas HOUR)
          AND c.telefono IS NOT NULL
          AND c.telefono <> ''
          AND NOT EXISTS (
              SELECT 1
              FROM saas_sms_cola q
              WHERE q.turno_id = t.id
                AND q.estado IN ('pendiente', 'reservado', 'enviado')
          )
        ORDER BY t.fecha_hora ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':ventana_horas' => $ventanaConsultaHoras]);
    $turnos = $stmt->fetchAll();

    $encolados = 0;
    $omitidos = 0;

    foreach ($turnos as $turno) {
        $fechaTurnoTs = strtotime((string)$turno['fecha_hora']);
        if ($fechaTurnoTs === false) {
            $omitidos++;
            logCronSms('WARN', 'Turno omitido por fecha_hora inválida', [
                'turno_id'   => $turno['turno_id'] ?? null,
                'fecha_hora' => $turno['fecha_hora'] ?? null,
            ]);
            continue;
        }

        $sendAtTs = $fechaTurnoTs - ($horasAnticipacion * 3600);
        $ahoraTs  = time();
        if ($sendAtTs < $ahoraTs) {
            $sendAtTs = $ahoraTs;
        }

        $expiresAtTs = $fechaTurnoTs;
        $sendAtStr    = date('Y-m-d H:i:s', $sendAtTs);
        $expiresAtStr = date('Y-m-d H:i:s', $expiresAtTs);
        $horaLegible  = date('H:i', $fechaTurnoTs);

        $nombreCliente = trim((string)($turno['cliente_nombre'] ?? ''));
        $saludo = $nombreCliente !== '' ? "Hola {$nombreCliente}! " : "Hola! ";
        $mensaje = "{$saludo}Te recordamos tu turno hoy a las {$horaLegible} en {$turno['negocio_nombre']}.";

        $colaId = encolarRecordatorioSms(
            $pdo,
            (int)$turno['negocio_id'],
            (int)$turno['turno_id'],
            $turno['cliente_id'] !== null ? (int)$turno['cliente_id'] : null,
            (string)$turno['telefono'],
            $mensaje,
            $sendAtStr,
            $expiresAtStr
        );

        $encolados++;
        logCronSms('INFO', 'SMS encolado', [
            'cola_id'  => $colaId,
            'turno_id' => (int)$turno['turno_id'],
            'send_at'  => $sendAtStr,
        ]);
    }

    logCronSms('INFO', 'Fin encolado de turnos próximos', [
        'candidatos' => count($turnos),
        'encolados'  => $encolados,
        'omitidos'   => $omitidos,
    ]);

    return $encolados;
}

if (PHP_SAPI === 'cli') {
    try {
        $pdo = getPdo();
        $cantidad = encolarTurnosProximos($pdo, 2, 6);
        logCronSms('INFO', "Ejecución CLI finalizada. Turnos encolados: {$cantidad}");
    } catch (Throwable $e) {
        logCronSms('ERROR', 'Fallo crítico en cron_encolar_sms', [
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
        ]);
        exit(1);
    }
}