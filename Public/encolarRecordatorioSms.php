<?php
declare(strict_types=1);

require_once __DIR__ . '/config_sms.php';

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
    // Ajustá 'saas_turnos', 'fecha_hora' y el estado según tu tabla real de turnos
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

    foreach ($turnos as $turno) {
        $fechaTurnoTs = strtotime((string)$turno['fecha_hora']);
        if ($fechaTurnoTs === false) {
            continue;
        }

        // Cuándo debe disparar el SMS (ej. 2 horas antes del turno; si falta menos, sale cuanto antes)
        $sendAtTs = $fechaTurnoTs - ($horasAnticipacion * 3600);
        $ahoraTs  = time();
        if ($sendAtTs < $ahoraTs) {
            $sendAtTs = $ahoraTs;
        }

        // Expira a la hora del turno para no mandar recordatorios vencidos
        $expiresAtTs = $fechaTurnoTs;

        $sendAtStr    = date('Y-m-d H:i:s', $sendAtTs);
        $expiresAtStr = date('Y-m-d H:i:s', $expiresAtTs);
        $horaLegible  = date('H:i', $fechaTurnoTs);

        $nombreCliente = trim((string)($turno['cliente_nombre'] ?? ''));
        $saludo = $nombreCliente !== '' ? "Hola {$nombreCliente}! " : "Hola! ";
        $mensaje = "{$saludo}Te recordamos tu turno hoy a las {$horaLegible} en {$turno['negocio_nombre']}.";

        encolarRecordatorioSms(
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
    }

    return $encolados;
}

// Ejemplo de ejecución desde CLI/cron:
if (PHP_SAPI === 'cli') {
    $pdo = getPdo();
    $cantidad = encolarTurnosProximos($pdo, 2, 6);
    echo "Turnos encolados: {$cantidad}\n";
}