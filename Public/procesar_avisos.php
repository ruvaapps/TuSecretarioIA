<?php
// procesar_avisos.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

$host    = "localhost";
$db_user = "admlova_secretario";
$db_pass = "secretario2026";
$db_name = "admlova_secretario";

$phone_number_id = "1297893416744537";
$access_token    = "EAAUJIfJBzp0BSdlljUGVo0bDbADhLC6I6ilZCVZBBtBqXmWMZBckitoaIfdlQKqXEZCIM7hRFESbifOTgFkF9CmiIwFB4rYxrTYhP2HTGpRZAuHbX8594DCgnE64PHjVxTEMYBGvsi8UvUtROzB1VioWurXTctZAeVg7xAxbDYLgdcoBSzFUCn5G4IZABNvtZAUZApQZDZD";

date_default_timezone_set('America/Argentina/Cordoba');
$ahora = date('Y-m-d H:i:s');

function guardarLog($mensaje) {
    $fecha = date('Y-m-d H:i:s');
    file_put_contents(__DIR__ . '/log.txt', "[{$fecha}] {$mensaje}\n", FILE_APPEND);
}

/**
 * Normaliza el número de teléfono para Meta (ej: 549351xxxxxxx)
 */
function normalizarTelefonoMeta($telefono) {
    $num = preg_replace('/[^0-9]/', '', $telefono);
    
    if (str_starts_with($num, '0')) {
        $num = substr($num, 1);
    }
    
    if (str_starts_with($num, '15')) {
        $num = substr($num, 2);
    }
    
    if (!str_starts_with($num, '549') && !str_starts_with($num, '54')) {
        $num = '549' . $num;
    } elseif (str_starts_with($num, '54') && !str_starts_with($num, '549')) {
        $num = '549' . substr($num, 2);
    }
    
    return $num;
}

/**
 * Función auxiliar para enviar mensajes a través de la API Graph de Meta
 */
function enviarMensajeWhatsApp($phone_number_id, $access_token, $payload) {
    $url = "https://graph.facebook.com/v20.0/{$phone_number_id}/messages";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http_code' => $http_code, 'response' => $response];
}

guardarLog("Cron de avisos iniciado. Evaluando fecha/hora <= {$ahora}");

$conn = new mysqli($host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    guardarLog("ERROR DB: " . $conn->connect_error);
    die("Error de conexión DB: " . $conn->connect_error);
}

// =========================================================================
// 1. PROCESAR RECORDATORIOS GENERALES (Tabla: recordatorios)
// =========================================================================

$sqlRecordatorios = "SELECT r.id, r.telefono, r.titulo_evento, r.fecha_evento, r.minutos_anticipacion,
                            GROUP_CONCAT(d.item SEPARATOR '\n• ') AS lista_items
                     FROM recordatorios r
                     LEFT JOIN recordatorio_detalles d ON r.id = d.recordatorio_id AND d.estado = 'pendiente'
                     WHERE r.estado = 'pendiente' AND r.fecha_hora_programada <= ?
                     GROUP BY r.id";

$stmtRec = $conn->prepare($sqlRecordatorios);
$stmtRec->bind_param("s", $ahora);
$stmtRec->execute();
$resultRec = $stmtRec->get_result();

while ($row = $resultRec->fetch_assoc()) {
    $id        = $row['id'];
    $telefono  = normalizarTelefonoMeta($row['telefono']);
    $titulo    = $row['titulo_evento'];
    $listaItems= $row['lista_items'];
    $horaStr   = !empty($row['fecha_evento']) ? date('d/m/Y H:i', strtotime($row['fecha_evento'])) : 'Hora programada';

    if (!empty($listaItems)) {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $telefono,
            'type' => 'template',
            'template' => [
                'name' => 'recordatorio_evento_detalle',
                'language' => ['code' => 'es_AR'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $titulo],
                            ['type' => 'text', 'text' => $horaStr],
                            ['type' => 'text', 'text' => $listaItems]
                        ]
                    ]
                ]
            ]
        ];
    } else {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $telefono,
            'type' => 'template',
            'template' => [
                'name' => 'recordatorio_evento_simple',
                'language' => ['code' => 'es_AR'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $titulo],
                            ['type' => 'text', 'text' => $horaStr]
                        ]
                    ]
                ]
            ]
        ];
    }

    $res = enviarMensajeWhatsApp($phone_number_id, $access_token, $payload);

    if ($res['http_code'] == 200) {
        $update_stmt = $conn->prepare("UPDATE recordatorios SET estado = 'enviado' WHERE id = ?");
        $update_stmt->bind_param("i", $id);
        $update_stmt->execute();
        $update_stmt->close();
        guardarLog("ÉXITO RECORDATORIO [ID {$id}]: Enviado a {$telefono}.");
    } else {
        guardarLog("ERROR RECORDATORIO [ID {$id} | HTTP {$res['http_code']}]: " . $res['response']);
    }
}
$stmtRec->close();

// =========================================================================
// 2. PROCESAR RECORDATORIOS DE TURNOS (Tabla: saas_turnos - 2 hs antes)
// =========================================================================

$sqlTurnos = "SELECT 
                t.id AS turno_id,
                c.telefono AS cliente_telefono,
                s.nombre AS servicio_nombre,
                n.nombre AS negocio_nombre,
                t.fecha_hora_inicio
              FROM saas_turnos t
              JOIN saas_clientes c ON t.cliente_id = c.id
              JOIN saas_servicios s ON t.servicio_id = s.id
              JOIN saas_negocios n ON t.negocio_id = n.id
              WHERE t.recordatorio_enviado = 0
                AND t.estado IN ('confirmado', 'reservado')
                AND t.fecha_hora_inicio <= DATE_ADD(?, INTERVAL 2 HOUR)
                AND t.fecha_hora_inicio > ?";

$stmtTurnos = $conn->prepare($sqlTurnos);
$stmtTurnos->bind_param("ss", $ahora, $ahora);
$stmtTurnos->execute();
$resultTurnos = $stmtTurnos->get_result();

while ($row = $resultTurnos->fetch_assoc()) {
    $turnoId        = $row['turno_id'];
    $telefonoCliente= normalizarTelefonoMeta($row['cliente_telefono']);
    $tituloEvento   = "Turno de " . $row['servicio_nombre'] . " en " . $row['negocio_nombre'];
    $horaStr        = date('d/m/Y H:i', strtotime($row['fecha_hora_inicio']));

    // Envio usando la plantilla simple existente
    // En la sección 2 de procesar_avisos.php

		$payloadTurno = [
		    'messaging_product' => 'whatsapp',
		    'to' => $telefonoCliente,
		    'type' => 'interactive',
		    'interactive' => [
		        'type' => 'button',
		        'body' => [
		            'text' => "⏰ *Recordatorio de Turno*\n\nTenés un turno de *{$row['servicio_nombre']}* en *{$row['negocio_nombre']}* para el *{$horaStr} hs*.\n\n¿Nos confirmás tu asistencia?"
		        ],
		        'action' => [
		            'buttons' => [
		                [
		                    'type' => 'reply',
		                    'reply' => [
		                        'id' => 'confirmar_turno_' . $turnoId,
		                        'title' => '✅ Confirmar'
		                    ]
		                ],
		                [
		                    'type' => 'reply',
		                    'reply' => [
		                        'id' => 'cancelar_turno_' . $turnoId,
		                        'title' => '❌ Cancelar'
		                    ]
		                ]
		            ]
		        ]
		    ]
		];

    $resTurno = enviarMensajeWhatsApp($phone_number_id, $access_token, $payloadTurno);

    if ($resTurno['http_code'] == 200) {
        $update_turno = $conn->prepare("UPDATE saas_turnos SET recordatorio_enviado = 1 WHERE id = ?");
        $update_turno->bind_param("i", $turnoId);
        $update_turno->execute();
        $update_turno->close();
        guardarLog("ÉXITO TURNO [ID {$turnoId}]: Recordatorio enviado a {$telefonoCliente}.");
    } else {
        guardarLog("ERROR TURNO [ID {$turnoId} | HTTP {$resTurno['http_code']}]: " . $resTurno['response']);
    }
}
$stmtTurnos->close();

$conn->close();
guardarLog("Cron de avisos finalizado.");
echo "Procesamiento completado.";