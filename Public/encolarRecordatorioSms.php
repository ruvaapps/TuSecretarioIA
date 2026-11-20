<?php
declare(strict_types=1);

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

function cancelarSmsPendientesDeTurno(PDO $pdo, int $turnoId): int
{
    $sql = "
        UPDATE saas_sms_cola
        SET estado = 'cancelado',
            reservado_hasta = NULL
        WHERE turno_id = :turno_id
          AND estado IN ('pendiente', 'reservado')
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':turno_id' => $turnoId]);

    return $stmt->rowCount();
}