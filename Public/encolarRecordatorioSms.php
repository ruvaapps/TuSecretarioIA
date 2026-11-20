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
    // Si viene como 549XXXXXXXXXX (13 dígitos), normaliza a 54XXXXXXXXXX para SMS
    if (preg_match('/^549(\d{10})$/', $digits, $m)) {
        return '54' . $m[1];
    }
    return $digits;
}

/**
 * Inserción genérica en saas_sms_cola para recordatorios, señas o promos.
 */
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

/**
 * Wrapper de compatibilidad para recordatorios de turno.
 */
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

/**
 * Encola SMS de recordatorio para turnos próximos confirmados/programados.
 */
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

/**
 * Encola SMS con datos para pagar la seña (link Mercado Pago o datos de transferencia)
 * para turnos pendientes de pago que aún no tienen SMS de seña en cola.
 */
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

/**
 * Gancho para encolar promos en saas_sms_cola usando la misma tabla y lógica.
 * Ajustá la consulta según el nombre de tu tabla/columnas de promos cuando lo tengas definido.
 */
function encolarPromosActivas(PDO $pdo, int $limitePorCorrida = 50): int
{
    logCronSms('INFO', 'Evaluando promos activas para encolar SMS', [
        'limite_por_corrida' => $limitePorCorrida,
    ]);

    // Ejemplo listo para adaptar cuando tengas tabla de promos/campañas:
    // SELECT p.id AS promo_id, p.negocio_id, c.id AS cliente_id, c.telefono, p.mensaje, p.fecha_fin
    // FROM saas_promos p ...
    // WHERE p.activa = 1 AND NOT EXISTS (SELECT 1 FROM saas_sms_cola q WHERE q.tipo = 'promo' AND q.referencia_id = p.id AND q.cliente_id = c.id)

    return 0;
}

if (PHP_SAPI === 'cli') {
    try {
        $pdo = getPdo();
        $recCount   = encolarTurnosProximos($pdo, 2, 6);
        $senaCount  = encolarSenasPendientes($pdo, 100);
        $promoCount = encolarPromosActivas($pdo, 50);

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
/*
####[cite: 2, 3, 4, 5]

### Dos detalles para revisar cuando lo pruebes
* **Vencimiento de seña (`expires_at`):** hoy lo dejé con `fecha_hora_inicio` del turno (o podés ponerle un margen de horas, por ejemplo `+2 hours` desde la creación) para que el despachador no mande un pedido de seña de un turno que ya pasó[cite: 3, 4, 5].
* **Acentos en SMS:** en los mensajes de seña dejé acentos (`aboná`, `transferí`) igual que en tu saludo de recordatorio[cite: 5]; si algún operador o dispositivo corta o reemplaza caracteres no ASCII, conviene pasarlo a texto plano sin tildes.

¿Cómo tenés pensada la estructura de promos (si es por negocio a todos sus clientes, por segmento, o una campaña con texto y vigencia) para dejar `encolarPromosActivas` andando también?

*/