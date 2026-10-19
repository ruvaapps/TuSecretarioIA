<?php
// webhook_mercadopago.php

// 1. Desactivar salida de errores HTML pero habilitar log de PHP
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 2. Responder 200 OK inmediatamente a Mercado Pago
http_response_code(200);

// 3. Función de Log
function logWebhookMP($msg) {
    $logFile = __DIR__ . '/log_webhook_mp.txt';
    $timestamp = "[" . date('Y-m-d H:i:s') . "] ";
    @file_put_contents($logFile, $timestamp . $msg . "\n", FILE_APPEND);
}

// Capturar errores fatales no contemplados
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        logWebhookMP("FATAL ERROR (Shutdown): " . $error['message'] . " en " . $error['file'] . " línea " . $error['line']);
    }
});

logWebhookMP("--- INICIO DE PETICION WEBHOOK ---");

// 4. Obtener el cuerpo de la petición
$body = file_get_contents('php://input');
logWebhookMP("RAW BODY: " . $body);

$data = json_decode($body, true) ?: [];

// Extraer tipo de evento e ID según formato de webhook/IPN de MP
$type = $_GET['type'] ?? ($data['type'] ?? ($_GET['topic'] ?? ($data['topic'] ?? '')));
$paymentId = $_GET['data_id'] ?? ($data['data']['id'] ?? ($data['id'] ?? null));
$resource = $data['resource'] ?? null;

logWebhookMP("Notificación procesada: Type = {$type} | Payment ID = {$paymentId} | Resource = {$resource}");

// 5. Inclusión segura de dependencias livianas
try {
    logWebhookMP("Por cargar conexion.php");
    $pathConexion = __DIR__ . '/../conexion.php';
    if (file_exists($pathConexion)) {
        require_once $pathConexion;
    } else {
        logWebhookMP("ERROR CRITICO: No se encontró conexion.php");
        exit;
    }

    $pathConfig = __DIR__ . '/../config.php';
    if (file_exists($pathConfig)) {
        require_once $pathConfig;
    }

    logWebhookMP("Por cargar funciones_whatsapp.php");
    $pathWA = __DIR__ . '/../funciones_whatsapp.php';
    if (file_exists($pathWA)) {
        require_once $pathWA;
        logWebhookMP("funciones_whatsapp.php cargado exitosamente.");
    } else {
        logWebhookMP("ADVERTENCIA: No se encontró funciones_whatsapp.php");
    }

    logWebhookMP("Terminó de cargar dependencias externas.");	
} catch (Throwable $t) {
    logWebhookMP("ERROR EN REQUIRES: " . $t->getMessage() . " en línea " . $t->getLine());
    exit;
}

logWebhookMP("Por procesar la notificacion tipo: ".$type);

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        logWebhookMP("ERROR CRITICO: Variable \$pdo de conexión no válida.");
        exit;
    }

    // Obtener los tokens de los negocios pendientes
    $stmtTurno = $pdo->prepare("
        SELECT t.id AS turno_id, t.negocio_id, n.mp_access_token 
        FROM saas_turnos t
        INNER JOIN saas_negocios n ON t.negocio_id = n.id
        WHERE t.estado = 'pendiente_pago'
        ORDER BY t.id DESC LIMIT 50
    ");
    $stmtTurno->execute();
    $candidatos = $stmtTurno->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // --- MANEJO DE MERCHANT_ORDER ---
    if ($type === 'merchant_order' && ($resource || $paymentId)) {
        $merchantOrderId = $paymentId;
        if (!$merchantOrderId && $resource) {
            $parts = explode('/', rtrim($resource, '/'));
            $merchantOrderId = end($parts);
        }

        logWebhookMP("Procesando Merchant Order ID: {$merchantOrderId}");

        foreach ($candidatos as $cand) {
            if (empty($cand['mp_access_token'])) continue;

            $ch = curl_init("https://api.mercadopago.com/merchant_orders/{$merchantOrderId}");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $cand['mp_access_token'],
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $res = curl_exec($ch);
            curl_close($ch);

            $orderInfo = json_decode($res, true);
            if (is_array($orderInfo) && isset($orderInfo['payments'])) {
                // Buscar el pago aprobado dentro de la orden
                foreach ($orderInfo['payments'] as $pago) {
                    if (isset($pago['status']) && $pago['status'] === 'approved') {
                        $paymentId = $pago['id'];
                        $type = 'payment'; // Forzar continuación como evento de pago
                        logWebhookMP("Pago {$paymentId} encontrado dentro de Merchant Order {$merchantOrderId}");
                        break 2;
                    }
                }
            }
        }
    }

    // --- PROCESAMIENTO DEL PAGO ---
    if (($type === 'payment' || $type === 'topic_payment' || $type === 'payment.updated') && $paymentId) {

        $paymentInfo = null;
        $accessTokenValido = null;

        logWebhookMP("Payment ID a consultar: " . $paymentId);

        // MODO TEST: ID simulado
        if (strpos((string)$paymentId, '999') === 0) {
            logWebhookMP("🧪 MODO TEST DETECTADO: Simulando pago aprobado para Payment ID {$paymentId}");
            $paymentInfo = [
                'status' => 'approved',
                'external_reference' => '122',
                'preference_id' => null
            ];
        } else {
            foreach ($candidatos as $cand) {
                if (empty($cand['mp_access_token'])) continue;

                $ch = curl_init("https://api.mercadopago.com/v1/payments/{$paymentId}");
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer " . $cand['mp_access_token'],
                    "Content-Type: application/json"
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $res = curl_exec($ch);
                curl_close($ch);

                $info = json_decode($res, true);
                if (is_array($info) && isset($info['status'])) {
                    $paymentInfo = $info;
                    $accessTokenValido = $cand['mp_access_token'];
                    break;
                }
            }
        }

        if ($paymentInfo && isset($paymentInfo['status']) && $paymentInfo['status'] === 'approved') {
            $turnoId = $paymentInfo['external_reference'] ?? null;

            logWebhookMP("Pago APROBADO para el Turno ID: {$turnoId}");

            // Expirar la preferencia si corresponde
            $preferenceId = $paymentInfo['preference_id'] ?? null;
            if ($preferenceId && $accessTokenValido) {
                $payloadExpira = json_encode([
                    "expires" => true,
                    "expiration_date_to" => date('Y-m-d\TH:i:s.000P')
                ]);

                $chPref = curl_init("https://api.mercadopago.com/checkout/preferences/{$preferenceId}");
                curl_setopt($chPref, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt($chPref, CURLOPT_POSTFIELDS, $payloadExpira);
                curl_setopt($chPref, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer " . $accessTokenValido,
                    "Content-Type: application/json"
                ]);
                curl_setopt($chPref, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chPref, CURLOPT_TIMEOUT, 10);
                $resPref = curl_exec($chPref);
                curl_close($chPref);

                logWebhookMP("Preferencia {$preferenceId} marcada como expirada.");
            }

            if ($turnoId) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE saas_turnos 
                    SET estado = 'confirmado', mp_payment_id = ? 
                    WHERE id = ? AND estado = 'pendiente_pago'
                ");
                $stmtUpdate->execute([$paymentId, $turnoId]);

                $stmtInfo = $pdo->prepare("
                    SELECT t.fecha_hora_inicio, c.nombre AS cliente_nombre, c.telefono AS cliente_telefono, 
                           s.nombre AS servicio_nombre, n.nombre AS negocio_nombre, n.direccion, n.localidad, n.instagram
                    FROM saas_turnos t
                    INNER JOIN saas_clientes c ON t.cliente_id = c.id
                    INNER JOIN saas_servicios s ON t.servicio_id = s.id
                    INNER JOIN saas_negocios n ON t.negocio_id = n.id
                    WHERE t.id = ?
                ");
                $stmtInfo->execute([$turnoId]);
                $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

                if ($info && !empty($info['cliente_telefono'])) {
                    $fechaFmt = date('d/m/Y', strtotime($info['fecha_hora_inicio']));
                    $horaFmt  = date('H:i', strtotime($info['fecha_hora_inicio']));
                    
                    $direccion_full = trim(($info['direccion'] ?? '') . ' ' . ($info['localidad'] ?? ''));
                    $negocioConDetalles = !empty($direccion_full) ? "{$info['negocio_nombre']} - Ubicación: {$direccion_full}" : $info['negocio_nombre'];
                    $instagramFmt = !empty($info['instagram']) ? '@' . ltrim($info['instagram'], '@') : '';

                    $phone_number_id = defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '';

                    if (function_exists('enviarPlantillaConfirmacion')) {
                        $resWA = enviarPlantillaConfirmacion(
                            $info['cliente_telefono'],
                            $info['cliente_nombre'],
                            $negocioConDetalles,
                            $instagramFmt,
                            $info['servicio_nombre'],
                            $fechaFmt,
                            $horaFmt,
                            $phone_number_id
                        );
                        logWebhookMP("Respuesta de envio WhatsApp: " . json_encode($resWA));
                    } else {
                        logWebhookMP("ERROR: La función enviarPlantillaConfirmacion no está definida.");
                    }
                }
            }
        } else {
            logWebhookMP("Resultado de consulta API MP: " . json_encode($paymentInfo));
        }
    }

} catch (Throwable $e) {
    logWebhookMP("EXCEPCION WEBHOOK: " . $e->getMessage() . " en línea " . $e->getLine());
}