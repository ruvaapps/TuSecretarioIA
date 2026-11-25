<?php
// vincular.php
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$codigo = trim($input['codigo'] ?? '');
$dispositivoId = trim($input['dispositivo_id'] ?? '');
$nombre = trim($input['nombre'] ?? '');

if ($codigo === '' || $dispositivoId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Faltan codigo o dispositivo_id']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, negocio_id, nombre_sugerido, vence_at, usado_at
        FROM saas_sms_codigos_vinculacion
        WHERE codigo = :codigo
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Código inexistente']);
        exit;
    }

    if (!empty($row['usado_at'])) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'El código ya fue utilizado']);
        exit;
    }

    if (strtotime($row['vence_at']) < time()) {
        $pdo->rollBack();
        http_response_code(410);
        echo json_encode(['ok' => false, 'error' => 'El código venció']);
        exit;
    }

    $negocioId = (int)$row['negocio_id'];
    $nombreFinal = $nombre !== '' ? $nombre : ($row['nombre_sugerido'] ?: 'Dispositivo SMS');

    // Token de 64 chars hex (32 bytes aleatorios)
    $deviceToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $deviceToken);

    // Si este dispositivo_id ya existía, lo actualizamos; si no, lo insertamos
    $chk = $pdo->prepare("SELECT id FROM saas_sms_dispositivos WHERE dispositivo_id = :disp_id LIMIT 1");
    $chk->execute([':disp_id' => $dispositivoId]);
    $existente = $chk->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        $updDisp = $pdo->prepare("
            UPDATE saas_sms_dispositivos
            SET negocio_id = :negocio_id,
                nombre = :nombre,
                token_hash = :token_hash,
                activo = 1,
                ultimo_visto_at = NOW(),
                actualizado_at = NOW()
            WHERE id = :id
        ");
        $updDisp->execute([
            ':negocio_id' => $negocioId,
            ':nombre'     => $nombreFinal,
            ':token_hash' => $tokenHash,
            ':id'         => $existente['id'],
        ]);
    } else {
        $insDisp = $pdo->prepare("
            INSERT INTO saas_sms_dispositivos
                (negocio_id, dispositivo_id, nombre, token_hash, activo, ultimo_visto_at, creado_at, actualizado_at)
            VALUES
                (:negocio_id, :dispositivo_id, :nombre, :token_hash, 1, NOW(), NOW(), NOW())
        ");
        $insDisp->execute([
            ':negocio_id'     => $negocioId,
            ':dispositivo_id' => $dispositivoId,
            ':nombre'         => $nombreFinal,
            ':token_hash'     => $tokenHash,
        ]);
    }

    $marcarUsado = $pdo->prepare("
        UPDATE saas_sms_codigos_vinculacion
        SET usado_at = NOW()
        WHERE id = :id
    ");
    $marcarUsado->execute([':id' => $row['id']]);

    $pdo->commit();

    echo json_encode([
        'ok'           => true,
        'device_token' => $deviceToken,
        'negocio_id'   => $negocioId,
        'nombre'       => $nombreFinal,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al vincular dispositivo']);
}