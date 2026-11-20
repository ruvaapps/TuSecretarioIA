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

        // Se considera procesado si existía y no estaba cancelado
        $check = $pdo->prepare('SELECT id FROM saas_sms_cola WHERE id = :id LIMIT 1');
        $check->execute([':id' => $id]);
        if ($check->fetchColumn() !== false) {
            $procesadosIds[] = $id;
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok'            => true,
        'procesados_ids'=> array_values(array_unique($procesadosIds)),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('SMS ack error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno en ack']);
}