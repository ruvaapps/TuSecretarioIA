<?php

if (!defined('GRAPH_API_VERSION')) {
    define('GRAPH_API_VERSION', 'v20.0');
}

// Credenciales fijas de la Cuenta Madre (SaaS Principal)
if (!defined('WHATSAPP_MADRE_WABA_ID')) {
    define('WHATSAPP_MADRE_WABA_ID', '1347930493803633'); // Colocar aquí el WABA ID de tu cuenta madre
}
if (!defined('WHATSAPP_MADRE_ACCESS_TOKEN')) {
    define('WHATSAPP_MADRE_ACCESS_TOKEN', 'EAAUJIfJBzp0BS...'); // Token con permisos de tu cuenta madre
}

/**
 * Obtiene todas las plantillas aprobadas de la WABA Madre y las replica en la WABA del cliente.
 *
 * @param string $waba_cliente_id ID de la WABA del cliente
 * @param string $access_token_cliente Token del cliente
 * @return array Resumen del proceso de sincronización
 */
function crearPlantillasBaseMeta(string $waba_cliente_id, string $access_token_cliente): array {

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

    $json_madre = json_decode($response_madre, true);

    if ($http_code_madre !== 200 || empty($json_madre['data'])) {
        return [
            'status' => 'error',
            'message' => 'No se pudieron consultar las plantillas de la cuenta madre.',
            'raw' => $json_madre
        ];
    }

    // -------------------------------------------------------------------------
    // PASO 2: Replicar cada plantilla aprobada en la WABA del Cliente
    // -------------------------------------------------------------------------
    $url_post_cliente = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$waba_cliente_id}/message_templates";
    $resultados = [];

    foreach ($json_madre['data'] as $tpl_madre) {
        // Solo replicamos las plantillas que estén aprobadas
        if (isset($tpl_madre['status']) && $tpl_madre['status'] !== 'APPROVED') {
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
            $resultados[$tpl_madre['name']] = ['status' => 'creada_exitosamente'];
        } elseif (isset($res_json_cliente['error']['code']) && ($res_json_cliente['error']['code'] == 100 || $res_json_cliente['error']['code'] == 2388080)) {
            $resultados[$tpl_madre['name']] = ['status' => 'ya_existia'];
        } else {
            $resultados[$tpl_madre['name']] = ['status' => 'error', 'details' => $res_json_cliente];
        }
    }

    return $resultados;
}