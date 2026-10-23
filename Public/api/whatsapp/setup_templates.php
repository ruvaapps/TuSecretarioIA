<?php

if (!defined('GRAPH_API_VERSION')) {
    define('GRAPH_API_VERSION', 'v20.0');
}

/**
 * Registra automáticamente las plantillas estándar del sistema en la WABA del cliente.
 *
 * @param string $waba_id ID de la cuenta de WhatsApp Business
 * @param string $access_token Token del cliente con permisos whatsapp_business_management
 * @return array Resumen de la creación de las plantillas
 */
function crearPlantillasBaseMeta(string $waba_id, string $access_token): array {
    $url = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$waba_id}/message_templates";

    // DEFINICIÓN DE PLANTILLAS DEL SISTEMA
    $plantillas = [
        // 1. Plantilla para Confirmación de Turno
        [
            'name' => 'secretario_ia_confirmacion_turno',
            'category' => 'UTILITY',
            'language' => 'es',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => "🎉 *¡Turno Agendado con Éxito!*\n\n🏢 *Lugar:* {{1}}\n💇 *Servicio:* {{2}}\n📅 *Fecha:* {{3}}\n⏰ *Hora:* {{4}}\n\n¡Te esperamos!",
                    'example' => [
                        'body_text' => [
                            ['Peluquería Clau', 'Corte de Pelo', '25/10/2026', '16:00 hs']
                        ]
                    ]
                ]
            ]
        ],

        // 2. Plantilla para Recordatorio de Turno
        [
            'name' => 'secretario_ia_recordatorio_turno',
            'category' => 'UTILITY',
            'language' => 'es',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => "⏰ *Recordatorio de Turno*\n\nHola {{1}}, te recordamos que tenés un turno reservado para el día {{2}} a las {{3}} hs en *{{4}}*.\n\nPor favor, respondenos a este mensaje si necesitás reprogramar o cancelar.",
                    'example' => [
                        'body_text' => [
                            ['Juan', '25/10/2026', '16:00', 'Peluquería Clau']
                        ]
                    ]
                ]
            ]
        ],

        // 3. Plantilla para Cancelación / Reprogramación
        [
            'name' => 'secretario_ia_cancelacion_turno',
            'category' => 'UTILITY',
            'language' => 'es',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => "❌ *Turno Cancelado*\n\nHola {{1}}, tu turno para el {{2}} a las {{3}} hs ha sido cancelado con éxito.\n\nSi querés agendar un nuevo horario, escribinos nuevamente.",
                    'example' => [
                        'body_text' => [
                            ['Juan', '25/10/2026', '16:00']
                        ]
                    ]
                ]
            ]
        ]
    ];

    $resultados = [];

    foreach ($plantillas as $template) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($template));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res_json = json_decode($response, true);

        // Si ya existía o se creó con éxito lo registramos como correcto
        if ($http_code === 200 || $http_code === 201) {
            $resultados[$template['name']] = ['status' => 'success', 'data' => $res_json];
        } elseif (isset($res_json['error']['code']) && $res_json['error']['code'] == 100) {
            // El error 100 en Meta suele indicar que el template con ese nombre ya existe
            $resultados[$template['name']] = ['status' => 'exists', 'message' => 'La plantilla ya existe en Meta.'];
        } else {
            $resultados[$template['name']] = ['status' => 'error', 'details' => $res_json];
        }
    }

    return $resultados;
}