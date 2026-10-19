<?php
// webhook_mercadopago.php[cite: 3]

// 1. Responder 200 OK inmediatamente a Mercado Pago[cite: 3]
http_response_code(200);[cite: 3]

// 2. Función de Log definida inmediatamente antes de cualquier require[cite: 3]
function logWebhookMP($msg) {[cite: 3]
    $logFile = __DIR__ . '/log_webhook_mp.txt';[cite: 3]
    $timestamp = "[" . date('Y-m-d H:i:s') . "] ";[cite: 3]
    @file_put_contents($logFile, $timestamp . $msg . "\n", FILE_APPEND);[cite: 3]
}

logWebhookMP("--- INICIO DE PETICION WEBHOOK ---");[cite: 3]

// 3. Obtener el cuerpo de la petición[cite: 3]
$body = file_get_contents('php://input');[cite: 3]
logWebhookMP("RAW BODY: " . $body);[cite: 3]

$data = json_decode($body, true);[cite: 3]

$type = $_GET['type'] ?? ($data['type'] ?? ($_GET['topic'] ?? ''));[cite: 3]
$paymentId = $_GET['data_id'] ?? ($data['data']['id'] ?? null);[cite: 3]

logWebhookMP("Notificación procesada: Type = {$type} | Payment ID = {$paymentId}");[cite: 3]

// 4. Inclusión segura de dependencias[cite: 3]
try {
    if (file_exists(__DIR__ . '/../conexion.php')) {[cite: 3]
        require_once __DIR__ . '/../conexion.php';[cite: 3]
    } else {
        logWebhookMP("ERROR CRITICO: No se encontró conexion.php");[cite: 3]
        exit;[cite: 3]
    }

    if (file_exists(__DIR__ . '/../acciones_panel.php')) {[cite: 3]
        require_once __DIR__ . '/../acciones_panel.php';[cite: 3]
    } else {
        logWebhookMP("ADVERTENCIA: No se encontró acciones_panel.php");[cite: 3]
    }
} catch (Throwable $t) {[cite: 3]
    logWebhookMP("ERROR EN REQUIRES: " . $t->getMessage());[cite: 3]
    exit;[cite: 3]
}

// 5. Procesar la notificación si es de tipo payment[cite: 3]
if (($type === 'payment' || $type === 'topic_payment' || $type === 'payment.updated') && $paymentId) {[cite: 3]

    try {
        // Consultar los negocios pendientes para validar el token[cite: 3]
        $stmtTurno = $pdo->prepare("
            SELECT t.id AS turno_id, t.negocio_id, n.mp_access_token 
            FROM saas_turnos t
            INNER JOIN saas_negocios n ON t.negocio_id = n.id
            WHERE t.estado = 'pendiente_pago'
            ORDER BY t.id DESC LIMIT 50
        ");[cite: 3]
        $stmtTurno->execute();[cite: 3]
        $candidatos = $stmtTurno->fetchAll(PDO::FETCH_ASSOC);[cite: 3]

        $paymentInfo = null;[cite: 3]
        $accessTokenValido = null;

        foreach ($candidatos as $cand) {[cite: 3]
            if (empty($cand['mp_access_token'])) continue;[cite: 3]

            $ch = curl_init("https://api.mercadopago.com/v1/payments/{$paymentId}");[cite: 3]
            curl_setopt($ch, CURLOPT_HTTPHEADER, [[cite: 3]
                "Authorization: Bearer " . $cand['mp_access_token'],[cite: 3]
                "Content-Type: application/json"[cite: 3]
            ]);[cite: 3]
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);[cite: 3]
            $res = curl_exec($ch);[cite: 3]
            curl_close($ch);[cite: 3]

            $info = json_decode($res, true);[cite: 3]
            if (isset($info['status'])) {[cite: 3]
                $paymentInfo = $info;[cite: 3]
                $accessTokenValido = $cand['mp_access_token']; // Guardamos el token que funcionó
                break;[cite: 3]
            }
        }

        if ($paymentInfo && isset($paymentInfo['status']) && $paymentInfo['status'] === 'approved') {[cite: 3]
            $turnoId = $paymentInfo['external_reference'] ?? null;[cite: 3]

            logWebhookMP("Pago APROBADO para el Turno ID: {$turnoId}");[cite: 3]

            // ----------------------------------------------------
            // NUEVO: EXPIRAR/CERRAR LA PREFERENCIA DE MERCADO PAGO
            // ----------------------------------------------------
            $preferenceId = $paymentInfo['preference_id'] ?? null;

            if ($preferenceId && $accessTokenValido) {
                $payloadExpira = json_encode([
                    "expires" => true,
                    "expiration_date_to" => date('Y-m-d\TH:i:s.000P') // Expirar en este preciso instante
                ]);

                $chPref = curl_init("https://api.mercadopago.com/checkout/preferences/{$preferenceId}");
                curl_setopt($chPref, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt($chPref, CURLOPT_POSTFIELDS, $payloadExpira);
                curl_setopt($chPref, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer " . $accessTokenValido,
                    "Content-Type: application/json"
                ]);
                curl_setopt($chPref, CURLOPT_RETURNTRANSFER, true);
                $resPref = curl_exec($chPref);
                curl_close($chPref);

                logWebhookMP("Preferencia {$preferenceId} marcada como expirada/cerrada.");
            }
            // ----------------------------------------------------

            if ($turnoId) {[cite: 3]
                $stmtUpdate = $pdo->prepare("
                    UPDATE saas_turnos 
                    SET estado = 'confirmado', mp_payment_id = ? 
                    WHERE id = ? AND estado = 'pendiente_pago'
                ");[cite: 3]
                $stmtUpdate->execute([$paymentId, $turnoId]);[cite: 3]

                $stmtInfo = $pdo->prepare("
                    SELECT t.fecha_hora_inicio, c.nombre AS cliente_nombre, c.telefono AS cliente_telefono, 
                           s.nombre AS servicio_nombre, n.nombre AS negocio_nombre, n.direccion, n.localidad, n.instagram
                    FROM saas_turnos t
                    INNER JOIN saas_clientes c ON t.cliente_id = c.id
                    INNER JOIN saas_servicios s ON t.servicio_id = s.id
                    INNER JOIN saas_negocios n ON t.negocio_id = n.id
                    WHERE t.id = ?
                ");[cite: 3]
                $stmtInfo->execute([$turnoId]);[cite: 3]
                $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);[cite: 3]

                if ($info && !empty($info['cliente_telefono'])) {[cite: 3]
                    $fechaFmt = date('d/m/Y', strtotime($info['fecha_hora_inicio']));[cite: 3]
                    $horaFmt  = date('H:i', strtotime($info['fecha_hora_inicio']));[cite: 3]
                    
                    $direccion_full = trim(($info['direccion'] ?? '') . ' ' . ($info['localidad'] ?? ''));[cite: 3]
                    $negocioConDetalles = !empty($direccion_full) ? "{$info['negocio_nombre']} - Ubicación: {$direccion_full}" : $info['negocio_nombre'];[cite: 3]
                    $instagramFmt = !empty($info['instagram']) ? '@' . ltrim($info['instagram'], '@') : '';[cite: 3]

                    if (function_exists('enviarPlantillaConfirmacion')) {[cite: 3]
                        enviarPlantillaConfirmacion([cite: 3]
                            $info['cliente_telefono'],[cite: 3]
                            $info['cliente_nombre'],[cite: 3]
                            $negocioConDetalles,[cite: 3]
                            $instagramFmt,[cite: 3]
                            $info['servicio_nombre'],[cite: 3]
                            $fechaFmt,[cite: 3]
                            $horaFmt,[cite: 3]
                            WHATSAPP_PHONE_NUMBER_ID[cite: 3]
                        );[cite: 3]
                        logWebhookMP("Plantilla WhatsApp enviada con éxito.");[cite: 3]
                    }
                }
            }
        } else {
            logWebhookMP("Resultado de consulta API MP: " . json_encode($paymentInfo));[cite: 3]
        }

    } catch (Exception $e) {[cite: 3]
        logWebhookMP("EXCEPCION WEBHOOK: " . $e->getMessage());[cite: 3]
    }
}