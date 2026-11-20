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

function normalizarTelefonoCola(string $telefono): string
{
    $digits = preg_replace('/[^0-9]/', '', $telefono) ?? '';
    if ($digits === '') {
        return '';
    }
    if (preg_match('/^549(\d{10})$/', $digits, $m)) {
        return '54' . $m[1];
    }
    return $digits;
}

function encolarSmsCola(
    PDO $pdo,
    string $tipo,
    int $negocioId,
    ?int $turnoId,
    ?int $clienteId,
    string $telefono,
    string $mensaje,
    string $sendAt,
    ?string $expiresAt = null,
    ?int $referenciaId = null,
    int $prioridad = 10
): int {
    $telefonoLimpio = normalizarTelefonoCola($telefono);

    $sql = "
        INSERT INTO saas_sms_cola (
            negocio_id,
            cliente_id,
            tipo,
            turno_id,
            referencia_id,
            telefono,
            mensaje,
            send_at,
            expires_at,
            estado,
            prioridad,
            created_at
        ) VALUES (
            :negocio_id,
            :cliente_id,
            :tipo,
            :turno_id,
            :referencia_id,
            :telefono,
            :mensaje,
            :send_at,
            :expires_at,
            'pendiente',
            :prioridad,
            NOW()
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':negocio_id'    => $negocioId,
        ':cliente_id'    => $clienteId,
        ':tipo'          => $tipo,
        ':turno_id'      => $turnoId,
        ':referencia_id' => $referenciaId,
        ':telefono'      => $telefonoLimpio,
        ':mensaje'       => $mensaje,
        ':send_at'       => $sendAt,
        ':expires_at'    => $expiresAt,
        ':prioridad'     => $prioridad,
    ]);

    return (int)$pdo->lastInsertId();
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
    return encolarSmsCola(
        $pdo,
        'recordatorio',
        $negocioId,
        $turnoId,
        $clienteId,
        $telefono,
        $mensaje,
        $sendAt,
        $expiresAt,
        null,
        5
    );
}

function encolarTurnosProximos(PDO $pdo, int $horasAnticipacion = 2, int $ventanaConsultaHoras = 6): int
{
    logCronSms('INFO', 'Buscando turnos próximos para encolar SMS de recordatorio', [
        'horas_anticipacion'  => $horasAnticipacion,
        'ventana_consulta_hs' => $ventanaConsultaHoras,
    ]);

    $sql = "
        SELECT
            t.id AS turno_id,
            t.negocio_id,
            t.cliente_id,
            t.fecha_hora_inicio,
            c.nombre AS cliente_nombre,
            c.telefono,
            n.nombre AS negocio_nombre
        FROM saas_turnos t
        INNER JOIN saas_clientes c ON c.id = t.cliente_id
        INNER JOIN saas_negocios n ON n.id = t.negocio_id
        WHERE t.estado IN ('confirmado', 'programado')
          AND t.fecha_hora_inicio > NOW()
          AND t.fecha_hora_inicio <= DATE_ADD(NOW(), INTERVAL :ventana_horas HOUR)
          AND c.telefono IS NOT NULL
          AND c.telefono <> ''
          AND NOT EXISTS (
              SELECT 1
              FROM saas_sms_cola q
              WHERE q.turno_id = t.id
                AND q.tipo = 'recordatorio'
                AND q.estado IN ('pendiente', 'reservado', 'enviado')
          )
        ORDER BY t.fecha_hora_inicio ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':ventana_horas' => $ventanaConsultaHoras]);
    $turnos = $stmt->fetchAll();

    $encolados = 0;
    $omitidos  = 0;

    foreach ($turnos as $turno) {
        $fechaTurnoTs = strtotime((string)$turno['fecha_hora_inicio']);
        if ($fechaTurnoTs === false) {
            $omitidos++;
            logCronSms('WARN', 'Turno omitido por fecha_hora inválida', [
                'turno_id'          => $turno['turno_id'] ?? null,
                'fecha_hora_inicio' => $turno['fecha_hora_inicio'] ?? null,
            ]);
            continue;
        }

        $sendAtTs = $fechaTurnoTs - ($horasAnticipacion * 3600);
        $ahoraTs  = time();
        if ($sendAtTs < $ahoraTs) {
            $sendAtTs = $ahoraTs;
        }

        $expiresAtTs  = $fechaTurnoTs;
        $sendAtStr    = date('Y-m-d H:i:s', $sendAtTs);
        $expiresAtStr = date('Y-m-d H:i:s', $expiresAtTs);
        $horaLegible  = date('H:i', $fechaTurnoTs);

        $nombreCliente = trim((string)($turno['cliente_nombre'] ?? ''));
        $saludo = $nombreCliente !== '' ? "Hola {$nombreCliente}! " : "Hola! ";
        $mensaje = "{$saludo}Te recordamos tu turno hoy a las {$horaLegible} en {$turno['negocio_nombre']}. Responde CONFIRMO o CANCELAR.";

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
        logCronSms('INFO', 'SMS recordatorio encolado', [
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

function encolarSenasPendientes(PDO $pdo, int $limite = 100): int
{
    logCronSms('INFO', 'Buscando turnos pendientes de seña para encolar SMS', [
        'limite' => $limite,
    ]);

    $sql = "
        SELECT
            t.id AS turno_id,
            t.negocio_id,
            t.cliente_id,
            t.fecha_hora_inicio,
            t.monto_sena,
            t.metodo_pago,
            t.link_pago,
            c.nombre AS cliente_nombre,
            c.telefono,
            s.nombre AS servicio_nombre,
            n.nombre AS negocio_nombre,
            n.permite_mercadopago,
            n.permite_transferencia,
            n.banco_nombre,
            n.banco_titular,
            n.banco_cbu_cvu,
            n.banco_alias,
            n.banco_instrucciones
        FROM saas_turnos t
        INNER JOIN saas_clientes c ON c.id = t.cliente_id
        INNER JOIN saas_negocios n ON n.id = t.negocio_id
        LEFT JOIN saas_servicios s ON s.id = t.servicio_id
        WHERE t.estado = 'pendiente_pago'
          AND t.monto_sena > 0
          AND t.fecha_hora_inicio > NOW()
          AND c.telefono IS NOT NULL
          AND c.telefono <> ''
          AND NOT EXISTS (
              SELECT 1
              FROM saas_sms_cola q
              WHERE q.turno_id = t.id
                AND q.tipo = 'sena'
                AND q.estado IN ('pendiente', 'reservado', 'enviado')
          )
        ORDER BY t.id ASC
        LIMIT :limite
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->execute();
    $turnos = $stmt->fetchAll();

    $encolados = 0;
    $omitidos  = 0;

    foreach ($turnos as $turno) {
        $telLimpio = normalizarTelefonoCola((string)($turno['telefono'] ?? ''));
        if ($telLimpio === '') {
            $omitidos++;
            continue;
        }

        $fechaTurnoTs = strtotime((string)$turno['fecha_hora_inicio']);
        $fechaFmt = $fechaTurnoTs !== false ? date('d/m/Y', $fechaTurnoTs) : '';
        $horaFmt  = $fechaTurnoTs !== false ? date('H:i', $fechaTurnoTs) : '';
        $detalleFecha = ($fechaFmt !== '' && $horaFmt !== '')
            ? " del {$fechaFmt} a las {$horaFmt} hs"
            : ($fechaFmt !== '' ? " del {$fechaFmt}" : "");

        $nombreCliente = trim((string)($turno['cliente_nombre'] ?? ''));
        $saludo = $nombreCliente !== '' ? "Hola {$nombreCliente}! " : "Hola! ";
        $negocioNombre = trim((string)($turno['negocio_nombre'] ?? ''));
        $montoFmt = '$' . number_format((float)$turno['monto_sena'], 0, ',', '.');

        $metodo = (string)($turno['metodo_pago'] ?? '');
        if ($metodo === '') {
            if ((int)($turno['permite_transferencia'] ?? 0) === 1) {
                $metodo = 'transferencia';
            } elseif ((int)($turno['permite_mercadopago'] ?? 0) === 1) {
                $metodo = 'mercadopago';
            }
        }

        $mensaje = '';
        if ($metodo === 'mercadopago' && !empty($turno['link_pago'])) {
            $link = trim((string)$turno['link_pago']);
            $mensaje = "{$saludo}Para confirmar tu turno{$detalleFecha}"
                . ($negocioNombre !== '' ? " en {$negocioNombre}" : "")
                . ", aboná la seña de {$montoFmt} aqui: {$link}";
        } else {
            $partesBanco = [];
            if (!empty($turno['banco_alias']))   $partesBanco[] = "Alias: " . trim((string)$turno['banco_alias']);
            if (!empty($turno['banco_cbu_cvu'])) $partesBanco[] = "CBU/CVU: " . trim((string)$turno['banco_cbu_cvu']);
            if (!empty($turno['banco_titular'])) $partesBanco[] = "Titular: " . trim((string)$turno['banco_titular']);
            if (!empty($turno['banco_nombre']))  $partesBanco[] = "Banco: " . trim((string)$turno['banco_nombre']);

            if ($partesBanco === []) {
                $omitidos++;
                logCronSms('WARN', 'Turno pendiente de seña sin link MP ni datos bancarios', [
                    'turno_id' => (int)$turno['turno_id'],
                    'metodo'   => $metodo,
                ]);
                continue;
            }

            $mensaje = "{$saludo}Para confirmar tu turno{$detalleFecha}"
                . ($negocioNombre !== '' ? " en {$negocioNombre}" : "")
                . ", transferí la seña de {$montoFmt}. "
                . implode(" | ", $partesBanco);
        }

        $ahoraStr = date('Y-m-d H:i:s');
        $expiresAtStr = $fechaTurnoTs !== false ? date('Y-m-d H:i:s', $fechaTurnoTs) : null;

        $colaId = encolarSmsCola(
            $pdo,
            'sena',
            (int)$turno['negocio_id'],
            (int)$turno['turno_id'],
            $turno['cliente_id'] !== null ? (int)$turno['cliente_id'] : null,
            $telLimpio,
            $mensaje,
            $ahoraStr,
            $expiresAtStr,
            null,
            3
        );

        $encolados++;
        logCronSms('INFO', 'SMS de seña encolado', [
            'cola_id'  => $colaId,
            'turno_id' => (int)$turno['turno_id'],
            'metodo'   => $metodo,
        ]);
    }

    logCronSms('INFO', 'Fin encolado de señas pendientes', [
        'candidatos' => count($turnos),
        'encolados'  => $encolados,
        'omitidos'   => $omitidos,
    ]);

    return $encolados;
}

function encolarPromosDesdeCampanas(PDO $pdo, int $limitePorCorrida = 25): int
{
    $sql = "
        SELECT
            e.id AS envio_id,
            e.campana_id,
            e.cliente_id,
            p.negocio_id,
            p.mensaje AS mensaje_promo,
            c.nombre AS cliente_nombre,
            c.telefono AS cliente_telefono,
            n.nombre AS negocio_nombre,
            n.slug AS negocio_slug
        FROM saas_campanas_envios e
        INNER JOIN saas_campanas_promos p ON e.campana_id = p.id
        INNER JOIN saas_clientes c ON e.cliente_id = c.id
        INNER JOIN saas_negocios n ON p.negocio_id = n.id
        WHERE e.estado = 'pendiente'
          AND p.estado = 'en_proceso'
          AND c.telefono IS NOT NULL
          AND c.telefono <> ''
          AND NOT EXISTS (
              SELECT 1
              FROM saas_sms_cola q
              WHERE q.tipo = 'promo'
                AND q.referencia_id = e.id
                AND q.estado IN ('pendiente', 'reservado', 'enviado')
          )
        ORDER BY e.id ASC
        LIMIT :limite
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limite', $limitePorCorrida, PDO::PARAM_INT);
    $stmt->execute();
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $encolados = 0;

    foreach ($pendientes as $item) {
        $telLimpio = normalizarTelefonoCola((string)$item['cliente_telefono']);
        if ($telLimpio === '') {
            $stmtErr = $pdo->prepare("
                UPDATE saas_campanas_envios
                SET estado = 'fallido', error_mensaje = 'Teléfono inválido o vacío'
                WHERE id = :id
            ");
            $stmtErr->execute([':id' => (int)$item['envio_id']]);
            continue;
        }

        $nombreCliente = trim((string)($item['cliente_nombre'] ?? ''));
        $saludo        = $nombreCliente !== '' ? "Hola {$nombreCliente}! " : "Hola! ";
        $negocioNombre = trim((string)($item['negocio_nombre'] ?? ''));
        $textoPromo    = trim((string)($item['mensaje_promo'] ?? ''));
        $slug          = trim((string)($item['negocio_slug'] ?? ''));

        $mensaje = $saludo
            . ($negocioNombre !== '' ? "{$negocioNombre}: " : "")
            . $textoPromo;

        if ($slug !== '') {
            $mensaje .= " Reservá en: https://tusecretarioia.lovar.com.ar/reserva.php?slug=" . $slug;
        }

        $ahora  = date('Y-m-d H:i:s');
        $expira = date('Y-m-d H:i:s', strtotime('+7 days'));

        encolarSmsCola(
            $pdo,
            'promo',
            (int)$item['negocio_id'],
            null,
            (int)$item['cliente_id'],
            $telLimpio,
            $mensaje,
            $ahora,
            $expira,
            (int)$item['envio_id'],
            20
        );

        $encolados++;
    }

    return $encolados;
}

function sincronizarEstadoCampanasDesdeCola(PDO $pdo): void
{
    $pdo->exec("
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
        WHERE q.estado IN ('enviado', 'fallido', 'cancelado')
          AND e.estado IN ('pendiente', 'encolado')
    ");

    $pdo->exec("
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
}

if (PHP_SAPI === 'cli') {
    try {
        $pdo = getPdo();
        $recCount   = encolarTurnosProximos($pdo, 2, 6);
        $senaCount  = encolarSenasPendientes($pdo, 100);
        $promoCount = encolarPromosDesdeCampanas($pdo, 25);
        sincronizarEstadoCampanasDesdeCola($pdo);

        logCronSms('INFO', 'Ejecución CLI finalizada', [
            'recordatorios_encolados' => $recCount,
            'senas_encoladas'         => $senaCount,
            'promos_encoladas'        => $promoCount,
        ]);
    } catch (Throwable $e) {
        logCronSms('ERROR', 'Fallo crítico en cron_encolar_sms', [
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
        ]);
        exit(1);
    }
}