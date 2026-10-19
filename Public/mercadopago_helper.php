<?php
// mercadopago_helper.php

/**
 * Genera una preferencia de pago en Mercado Pago para el cobro de seña.
 *
 * @param string $accessToken Token de Mercado Pago del negocio o sistema.
 * @param int|string $turnoId ID del turno reservado.
 * @param string $nombreServicio Nombre del servicio.
 * @param float $montoSena Monto a cobrar en concepto de seña.
 * @param string $emailCliente Email del cliente (opcional).
 * @param string $baseUrl URL base de tu dominio (ej: https://tudominio.lovar.com.ar).
 * @return array|null Retorna ['preference_id' => ..., 'init_point' => ...] o null si falla.
 */
function generarLinkSenaMercadoPago($accessToken, $turnoId, $nombreServicio, $montoSena, $emailCliente = '', $baseUrl = '') {
    // Si no se pasa baseUrl, podemos definir una por defecto o detectar el host
    if (empty($baseUrl)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . $host;
    }

    // Asegurar zona horaria correcta para Argentina/Córdoba
    date_default_timezone_set('America/Argentina/Cordoba');

    $url = "https://api.mercadopago.com/checkout/preferences";

    $payload = [
        "items" => [
            [
                "id"          => "TURNO-" . $turnoId,
                "title"       => "Seña: " . $nombreServicio,
                "quantity"    => 1,
                "currency_id" => "ARS",
                "unit_price"  => (float) $montoSena
            ]
        ],
        "payer" => [
            "email" => !empty($emailCliente) ? $emailCliente : "cliente@ejemplo.com"
        ],
        "external_reference" => (string) $turnoId,
        "notification_url"   => rtrim($baseUrl, '/') . "/mercadoPago/webhook_mercadopago.php",
        "back_urls" => [
            "success" => rtrim($baseUrl, '/') . "/mercadoPago/pago_exitoso.php?turno_id=" . $turnoId,
            "failure" => rtrim($baseUrl, '/') . "/mercadoPago/pago_fallido.php?turno_id=" . $turnoId,
            "pending" => rtrim($baseUrl, '/') . "/mercadoPago/pago_pendiente.php?turno_id=" . $turnoId
        ],
        "auto_return" => "approved",
        "expires" => true,
        "expiration_date_to" => date('Y-m-d\TH:i:s.000P', strtotime('+60 minutes'))
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $accessToken,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        if (function_exists('logAccionesPanel')) {
            logAccionesPanel("[MERCADO PAGO ERROR cURL]: " . $curlError);
        }
        return null;
    }

    $resData = json_decode($response, true);

    if (isset($resData['init_point'])) {
        // Verificar si el token empieza explícitamente con TEST-
        $isTestToken = (strpos($accessToken, 'TEST-') === 0);
        
        // Si es un token TEST usará sandbox_init_point; si es APP_USR-, usará init_point de Producción
        $linkPago = $isTestToken ? ($resData['sandbox_init_point'] ?? $resData['init_point']) : $resData['init_point'];

        if (function_exists('logAccionesPanel')) {
            logAccionesPanel("[MERCADO link pago]: " . $linkPago);
        }

        return [
            'preference_id' => $resData['id'],
            'init_point'    => $linkPago
        ];
    }

    if (function_exists('logAccionesPanel')) {
        logAccionesPanel("[MERCADO PAGO ERROR RESPUESTA]: " . json_encode($resData));
    }

    return null;
}