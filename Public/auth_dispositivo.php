<?php
// auth_dispositivo.php

function obtenerTokenDispositivo(): ?string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'authorization') {
            if (preg_match('/Bearer\s+(.+)/i', trim($v), $m)) {
                return trim($m[1]);
            }
        }
        if (strtolower($k) === 'x-device-token') {
            return trim($v);
        }
    }

    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        return trim($m[1]);
    }
    if (!empty($_SERVER['HTTP_X_DEVICE_TOKEN'])) {
        return trim($_SERVER['HTTP_X_DEVICE_TOKEN']);
    }

    return null;
}

function autenticarDispositivoSms(PDO $pdo): array {
    $tokenPlano = obtenerTokenDispositivo();
    if (!$tokenPlano) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Token de dispositivo faltante']);
        exit;
    }

    $tokenHash = hash('sha256', $tokenPlano);

    $stmt = $pdo->prepare("
        SELECT id, negocio_id, dispositivo_id, nombre, activo
        FROM saas_sms_dispositivos
        WHERE token_hash = :hash
          AND activo = 1
        LIMIT 1
    ");
    $stmt->execute([':hash' => $tokenHash]);
    $dispositivo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispositivo) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Dispositivo no autorizado o inactivo']);
        exit;
    }

    $upd = $pdo->prepare("
        UPDATE saas_sms_dispositivos
        SET ultimo_visto_at = NOW(), actualizado_at = NOW()
        WHERE id = :id
    ");
    $upd->execute([':id' => $dispositivo['id']]);

    return $dispositivo;
}