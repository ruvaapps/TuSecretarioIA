<?php

if (!defined('GRAPH_API_VERSION')) {
    define('GRAPH_API_VERSION', 'v20.0');
}

// Credenciales fijas de la Cuenta Madre (SaaS Principal)
if (!defined('WHATSAPP_MADRE_WABA_ID')) {
    define('WHATSAPP_MADRE_WABA_ID', '1347930493803633'); // WABA ID de tu cuenta madre
}
if (!defined('WHATSAPP_MADRE_ACCESS_TOKEN')) {
    define('WHATSAPP_MADRE_ACCESS_TOKEN', 'EAAUJIfJBzp0BS1BEa4YOEH522RtGuSdZAqXd8VrQnDoDlnTRa8R0XG4ZBmPWsHZATgtmI9hgdEmZAJEJgxyPIRUMJajgpfMyv9N8X2NTsnwv0ttmtTMLNJFG0kFEECxuSm2VI90PpGtatJFRKN6xni9CyUnnVVwcs00ZCixkqsZCtV2iQTE2algXnlqGiy9KcuQyikM8hDSvxScgEcEg3RZC3mWENNEvVGOR0fW1ITB7TuxOnTZBp8EQ6QGbidYnHwLkz5yICIwqUOiBLPDZBr5dYbI6wnHgDMSFwNWNvJDrORi231HdvUTB3HdZBUeEbYf5m7LNMPRTeRJmgZD'); // Token con permisos de tu cuenta madre
}

/**
 * Función de log específica para el proceso de plantillas
 */
function logSetupTemplates($msg) {
    file_put_contents(__DIR__ . '/oauth_debug.log', date('Y-m-d H:i:s') . " - [PLANTILLAS] " . $msg . "\n", FILE_APPEND);
}

/**
 * Obtiene todas las plantillas aprobadas de la WABA Madre y las replica en la WABA del cliente.
 *
 * @param string $waba_cliente_id ID de la WABA del cliente
 * @param string $access_token_cliente Token del cliente
 * @return array Resumen del proceso de sincronización
 */
function crearPlantillasBaseMeta(string $waba_cliente_id, string $access_token_cliente): array {
    logSetupTemplates("=== INICIO CLONACIÓN DE PLANTILLAS ===");
    logSetupTemplates("Target WABA Cliente: {$waba_cliente_id}");

    // -------------------------------------------------------------------------
    // PASO 1: Obtener las plantillas aprobadas de la Cuenta Madre
    // -------------------------------------------------------------------------
    $url_get = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/" . WHATSAPP_MADRE_WABA_ID . "/message_templates?limit=100";

    $ch = curl_init($url_get);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . WHATSAPP_MADRE_ACCESS_TOKEN,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response_madre = curl_exec($ch);
    $http_code_madre = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logSetupTemplates("Consulta WABA Madre - HTTP Code: {$http_code_madre}");

    $json_madre = json_decode($response_madre, true);

    if ($http_code_madre !== 200 || empty($json_madre['data'])) {
        logSetupTemplates("[ERROR] No se pudieron obtener las plantillas de la WABA madre. Respuesta: " . $response_madre);
        return [
            'status' => 'error',
            'message' => 'No se pudieron consultar las plantillas de la cuenta madre.',
            'raw' => $json_madre
        ];
    }

    $total_plantillas = count($json_madre['data']);
    logSetupTemplates("Se encontraron {$total_plantillas} plantillas en la cuenta madre.");

    // -------------------------------------------------------------------------
    // PASO 2: Replicar cada plantilla aprobada en la WABA del Cliente
    // -------------------------------------------------------------------------
    $url_post_cliente = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$waba_cliente_id}/message_templates";
    $resultados = [];

    foreach ($json_madre['data'] as $tpl_madre) {
        $nombre_tpl = $tpl_madre['name'] ?? 'Sin nombre';

        // Solo replicamos las plantillas que estén aprobadas
        if (isset($tpl_madre['status']) && $tpl_madre['status'] !== 'APPROVED') {
            logSetupTemplates("Omitiendo '{$nombre_tpl}' por estado: " . ($tpl_madre['status'] ?? 'DESCONOCIDO'));
            continue;
        }

        // Estructurar el payload exactamente como Meta requiere para el alta
        $payload_cliente = [
            'name'       => $tpl_madre['name'],
            'category'   => $tpl_madre['category'],
            'language'   => $tpl_madre['language'],
            'components' => $tpl_madre['components'] ?? []
        ];

        // Enviar petición POST a la WABA del cliente
        $ch = curl_init($url_post_cliente);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token_cliente,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload_cliente));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $res_cliente = curl_exec($ch);
        $http_code_cliente = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res_json_cliente = json_decode($res_cliente, true);

        if ($http_code_cliente === 200 || $http_code_cliente === 201) {
            logSetupTemplates(" Plantilla '{$nombre_tpl}' creada exitosamente en la WABA cliente.");
            $resultados[$nombre_tpl] = ['status' => 'creada_exitosamente'];
        } elseif (isset($res_json_cliente['error']['code']) && ($res_json_cliente['error']['code'] == 100 || $res_json_cliente['error']['code'] == 2388080)) {
            logSetupTemplates("ℹ️ Plantilla '{$nombre_tpl}' ya existía previamente.");
            $resultados[$nombre_tpl] = ['status' => 'ya_existia'];
        } else {
            logSetupTemplates("❌ Error creando '{$nombre_tpl}' (HTTP {$http_code_cliente}): " . $res_cliente);
            $resultados[$nombre_tpl] = ['status' => 'error', 'details' => $res_json_cliente];
        }
    }

    logSetupTemplates("=== FIN CLONACIÓN DE PLANTILLAS ===");
    return $resultados;
}