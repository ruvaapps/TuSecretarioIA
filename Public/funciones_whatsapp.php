<?php
// funciones_whatsapp.php

// Definir credenciales globales de WhatsApp Cloud API (Meta)
if (!defined('WHATSAPP_PHONE_NUMBER_ID')) {
    define('WHATSAPP_PHONE_NUMBER_ID', '1297893416744537');
}

if (!defined('WHATSAPP_ACCESS_TOKEN')) {
    define('WHATSAPP_ACCESS_TOKEN', 'EAAUJIfJBzp0BSdlljUGVo0bDbADhLC6I6ilZCVZBBtBqXmWMZBckitoaIfdlQKqXEZCIM7hRFESbifOTgFkF9CmiIwFB4rYxrTYhP2HTGpRZAuHbX8594DCgnE64PHjVxTEMYBGvsi8UvUtROzB1VioWurXTctZAeVg7xAxbDYLgdcoBSzFUCn5G4IZABNvtZAUZApQZDZD');
}

/**
 * Normaliza números de teléfono para la API de Meta (limpia espacios, guiones y signos +)
 */
if (!function_exists('normalizarTelefonoMeta')) {
    function normalizarTelefonoMeta($numero) {
        return preg_replace('/[^0-9]/', '', $numero);
    }
}

/**
 * Función para registrar logs específicos de WhatsApp
 */
if (!function_exists('logWhatsApp')) {
    function logWhatsApp($msg) {
        $logFile = __DIR__ . '/log_whatsapp.txt';
        $timestamp = "[" . date('Y-m-d H:i:s') . "] ";
        @file_put_contents($logFile, $timestamp . $msg . "\n", FILE_APPEND);
    }
}

function enviarPlantillaConfirmacion($to, $nombreCliente, $nombreNegocio, $instagram, $nombreServicio, $fechaFmt, $horaFmt, $phoneNumberId = WHATSAPP_PHONE_NUMBER_ID) {
    $to  = normalizarTelefonoMeta($to);
    $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";
    
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => 'confirmacion_turno_manual',
            'language' => ['code' => 'es_AR'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreCliente],
                        ['type' => 'text', 'text' => $nombreNegocio],
                        ['type' => 'text', 'text' => $instagram],
                        ['type' => 'text', 'text' => $nombreServicio],
                        ['type' => 'text', 'text' => $fechaFmt],
                        ['type' => 'text', 'text' => $horaFmt]
                    ]
                ]
            ]
        ]
    ];

    return ejecutarCurlWhatsApp($url, $payload, $to);
}

function enviarMensajeSenaWhatsApp($to, $nombreCliente, $nombreServicio, $nombreNegocio, $fechaFmt, $horaFmt, $montoSena, $linkPago, $phoneNumberId = WHATSAPP_PHONE_NUMBER_ID) {
    $to  = normalizarTelefonoMeta($to);
    $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";
    
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => 'envio_link_sena',
            'language' => ['code' => 'es_AR'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreCliente],
                        ['type' => 'text', 'text' => $nombreServicio],
                        ['type' => 'text', 'text' => $nombreNegocio],
                        ['type' => 'text', 'text' => $fechaFmt],
                        ['type' => 'text', 'text' => $horaFmt],
                        ['type' => 'text', 'text' => number_format($montoSena, 2, ',', '.')],
                        ['type' => 'text', 'text' => $linkPago ?: 'Consultar']
                    ]
                ]
            ]
        ]
    ];

    return ejecutarCurlWhatsApp($url, $payload, $to);
}

function enviarMensajeTransferenciaWhatsApp($to, $nombreCliente, $nombreServicio, $nombreNegocio, $fechaFmt, $horaFmt, $montoSena, $datosBanco, $phoneNumberId = WHATSAPP_PHONE_NUMBER_ID) {
    $to  = normalizarTelefonoMeta($to);
    $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";

    $bancoNombre  = !empty($datosBanco['banco_nombre']) ? $datosBanco['banco_nombre'] : 'Consultar';
    $bancoTitular = !empty($datosBanco['banco_titular']) ? $datosBanco['banco_titular'] : 'Consultar';
    $bancoAlias   = !empty($datosBanco['banco_alias']) ? $datosBanco['banco_alias'] : 'No especificado';
    $bancoCbu     = !empty($datosBanco['banco_cbu_cvu']) ? $datosBanco['banco_cbu_cvu'] : 'No especificado';

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => 'envio_datos_transferencia',
            'language' => ['code' => 'en'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreCliente],
                        ['type' => 'text', 'text' => $nombreServicio],
                        ['type' => 'text', 'text' => $nombreNegocio],
                        ['type' => 'text', 'text' => $fechaFmt],
                        ['type' => 'text', 'text' => $horaFmt],
                        ['type' => 'text', 'text' => number_format($montoSena, 2, ',', '.')],
                        ['type' => 'text', 'text' => $bancoNombre],
                        ['type' => 'text', 'text' => $bancoTitular],
                        ['type' => 'text', 'text' => $bancoAlias],
                        ['type' => 'text', 'text' => $bancoCbu]
                    ]
                ]
            ]
        ]
    ];

    return ejecutarCurlWhatsApp($url, $payload, $to);
}

function enviarPlantillaCancelacion($to, $nombreCliente, $fechaFmt, $horaFmt, $nombreNegocio, $phoneNumberId = WHATSAPP_PHONE_NUMBER_ID) {
    $to  = normalizarTelefonoMeta($to);
    $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";
    
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => 'cancelacion_turno_manual',
            'language' => ['code' => 'es_AR'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreCliente],
                        ['type' => 'text', 'text' => $fechaFmt],
                        ['type' => 'text', 'text' => $horaFmt],
                        ['type' => 'text', 'text' => $nombreNegocio]
                    ]
                ]
            ]
        ]
    ];

    return ejecutarCurlWhatsApp($url, $payload, $to);
}

function ejecutarCurlWhatsApp($url, $payload, $telefono) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . WHATSAPP_ACCESS_TOKEN,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    $msgLog = $err ? "ERROR cURL enviando Plantilla WhatsApp a {$telefono}: {$err}" : "Respuesta API Plantilla WhatsApp para {$telefono}: {$res}";

    if (function_exists('logAccionesPanel')) {
        logAccionesPanel($msgLog);
    } elseif (function_exists('logClientes')) {
        logClientes($msgLog);
    } else {
        logWhatsApp($msgLog);
    }

    return $res;
}