<?php
// funciones_whatsapp.php - Motor Central Híbrido de WhatsApp Cloud API

// Definir credenciales globales por defecto (Modo Shared - Canal de la App)
if (!defined('WHATSAPP_PHONE_NUMBER_ID')) {
    define('WHATSAPP_PHONE_NUMBER_ID', '1297893416744537');
}

if (!defined('WHATSAPP_ACCESS_TOKEN')) {
    define('WHATSAPP_ACCESS_TOKEN', 'EAAUJIfJBzp0BSdlljUGVo0bDbADhLC6I6ilZCVZBBtBqXmWMZBckitoaIfdlQKqXEZCIM7hRFESbifOTgFkF9CmiIwFB4rYxrTYhP2HTGpRZAuHbX8594DCgnE64PHjVxTEMYBGvsi8UvUtROzB1VioWurXTctZAeVg7xAxbDYLgdcoBSzFUCn5G4IZABNvtZAUZApQZDZD');
}

/**
 * Normaliza números de teléfono para la API de Meta
 */
if (!function_exists('normalizarTelefonoMeta')) {
    function normalizarTelefonoMeta($numero) {
        return preg_replace('/[^0-9]/', '', $numero);
    }
}

/**
 * Función de logging específica para WhatsApp
 */
if (!function_exists('logWhatsApp')) {
    function logWhatsApp($msg) {
        $logFile = __DIR__ . '/log_whatsapp.txt';
        $timestamp = "[" . date('Y-m-d H:i:s') . "] ";
        @file_put_contents($logFile, $timestamp . $msg . "\n", FILE_APPEND);
    }
}

/**
 * FUNCIÓN CENTRALIZADA INTELIGENTE: Decide qué credenciales usar y registra el log si es compartido.
 */
function ejecutarCurlWhatsAppInteligente($pdo, $negocio_id, $payload, $telefono, $tipo_mensaje = 'text') {
    $telefono      = normalizarTelefonoMeta($telefono);
    $modo          = 'shared';
    $accessToken   = WHATSAPP_ACCESS_TOKEN;
    $phoneNumberId = WHATSAPP_PHONE_NUMBER_ID;
    $origenLog     = 'app_shared';

    // 1. Consultar si el negocio tiene configurado su propio canal de WhatsApp ('own')
    if ($pdo && $negocio_id) {
        try {
            $stmt = $pdo->prepare("SELECT whatsapp_mode, wa_phone_number_id, wa_access_token FROM saas_negocios WHERE id = ?");
            $stmt->execute([$negocio_id]);
            $negocioWa = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($negocioWa && ($negocioWa['whatsapp_mode'] ?? 'shared') === 'own') {
                if (!empty($negocioWa['wa_access_token']) && !empty($negocioWa['wa_phone_number_id'])) {
                    $accessToken   = trim($negocioWa['wa_access_token']);
                    $phoneNumberId = trim($negocioWa['wa_phone_number_id']);
                    $origenLog     = 'business_own';
                }
            }
        } catch (Exception $e) {
            logWhatsApp("Error consultando modo WhatsApp para negocio ID {$negocio_id}: " . $e->getMessage());
        }
    }

    // Configurar destinatario y URL de la API de Meta
    $payload['to'] = $telefono;
    $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";

    // 2. Ejecutar petición cURL a Meta
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . trim($accessToken),
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    $msgLog = $err ? "ERROR cURL enviando WhatsApp a {$telefono} [Modo: {$origenLog}]: {$err}" : "Respuesta API WhatsApp para {$telefono} [Modo: {$origenLog}]: {$res}";

    if (function_exists('logAccionesPanel')) {
        logAccionesPanel($msgLog);
    } elseif (function_exists('logClientes')) {
        logClientes($msgLog);
    } else {
        logWhatsApp($msgLog);
    }

    // 3. Si se usó el canal compartido de la App, registrar automáticamente el consumo para facturación
    if ($origenLog === 'app_shared' && $pdo && $negocio_id) {
        try {
            $stmtCli = $pdo->prepare("SELECT id FROM saas_clientes WHERE negocio_id = ? AND telefono = ? LIMIT 1");
            $stmtCli->execute([$negocio_id, $telefono]);
            $clienteId = $stmtCli->fetchColumn() ?: null;

            $stmtLog = $pdo->prepare("
                INSERT INTO saas_whatsapp_mensajes_log
                (negocio_id, cliente_id, telefono_destino, tipo_mensaje, origen, costo_unitario)
                VALUES (?, ?, ?, ?, 'app_shared', 0.00)
            ");
            $stmtLog->execute([$negocio_id, $clienteId, $telefono, $tipo_mensaje]);
        } catch (Exception $e) {
            logWhatsApp("Error registrando log de mensaje en saas_whatsapp_mensajes_log: " . $e->getMessage());
        }
    }

    return $res;
}

// Wrapper retrocompatible por si algún script viejo llama a ejecutarCurlWhatsApp directamente
function ejecutarCurlWhatsApp($url, $payload, $telefono) {
    global $pdo;
    $negocio_id = $GLOBALS['negocio_id_activo'] ?? null;
    return ejecutarCurlWhatsAppInteligente($pdo, $negocio_id, $payload, $telefono, 'template');
}

// -----------------------------------------------------------------------------
// FUNCIONES DE PLANTILLAS (Actualizadas para recibir $pdo y $negocio_id)
// -----------------------------------------------------------------------------

function enviarPlantillaConfirmacion($to, $nombreCliente, $nombreNegocio, $instagram, $nombreServicio, $fechaFmt, $horaFmt, $pdo = null, $negocio_id = null) {
    $payload = [
        'messaging_product' => 'whatsapp',
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

    return ejecutarCurlWhatsAppInteligente($pdo, $negocio_id, $payload, $to, 'template');
}

function enviarMensajeSenaWhatsApp($to, $nombreCliente, $nombreServicio, $nombreNegocio, $fechaFmt, $horaFmt, $montoSena, $linkPago, $pdo = null, $negocio_id = null) {
    $payload = [
        'messaging_product' => 'whatsapp',
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

    return ejecutarCurlWhatsAppInteligente($pdo, $negocio_id, $payload, $to, 'template');
}

function enviarMensajeTransferenciaWhatsApp($to, $nombreCliente, $nombreServicio, $nombreNegocio, $fechaFmt, $horaFmt, $montoSena, $datosBanco, $pdo = null, $negocio_id = null) {
    $bancoNombre  = !empty($datosBanco['banco_nombre']) ? $datosBanco['banco_nombre'] : 'Consultar';
    $bancoTitular = !empty($datosBanco['banco_titular']) ? $datosBanco['banco_titular'] : 'Consultar';
    $bancoAlias   = !empty($datosBanco['banco_alias']) ? $datosBanco['banco_alias'] : 'No especificado';
    $bancoCbu     = !empty($datosBanco['banco_cbu_cvu']) ? $datosBanco['banco_cbu_cvu'] : 'No especificado';

    $payload = [
        'messaging_product' => 'whatsapp',
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

    return ejecutarCurlWhatsAppInteligente($pdo, $negocio_id, $payload, $to, 'template');
}

function enviarPlantillaCancelacion($to, $nombreCliente, $fechaFmt, $horaFmt, $nombreNegocio, $pdo = null, $negocio_id = null) {
    $payload = [
        'messaging_product' => 'whatsapp',
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

    return ejecutarCurlWhatsAppInteligente($pdo, $negocio_id, $payload, $to, 'template');
}