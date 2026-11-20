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
$resultados    = $input['resultados'] ?? null;

if ($dispositivoId === '' || !is_array($resultados)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'dispositivo_id y resultados son obligatorios']);
    exit;
}

$pdo = getPdo();
verifyDeviceToken($pdo, $dispositivoId);

$estadosPermitidos = ['enviado', 'fallido'];
$procesadosIds = [];

try {
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
          AND estado <> 'cancelado'
    ";
    $stmt = $pdo->prepare($sqlUpdate);

    foreach ($resultados as $item) {
        if (!is_array($item)) {
            continue;
        }

        $id     = (int)($item['id'] ?? 0);
        $estado = (string)($item['estado'] ?? '');
        if ($id <= 0 || !in_array($estado, $estadosPermitidos, true)) {
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

        $stmt->execute([
            ':estado'         => $estado,
            ':dispositivo_id' => $dispositivoId,
            ':intentos'       => $intentos,
            ':enviado_at'     => $enviadoAt,
            ':ultimo_error'   => $ultimoError,
            ':id'             => $id,
        ]);

        $check = $pdo->prepare('SELECT id FROM saas_sms_cola WHERE id = :id LIMIT 1');
        $check->execute([':id' => $id]);
        if ($check->fetchColumn() !== false) {
            $procesadosIds[] = $id;
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

    $pdo->commit();

    echo json_encode([
        'ok'             => true,
        'procesados_ids' => array_values(array_unique($procesadosIds)),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('SMS ack error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno en ack']);
}