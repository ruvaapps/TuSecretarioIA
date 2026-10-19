<?php
// webhook_clientes.php
header('Content-Type: application/json');

// Configuración de Zona Horaria de Argentina
date_default_timezone_set('America/Argentina/Cordoba');

// -----------------------------------------------------------------------------
// 1. CONFIGURACIÓN Y CONEXIÓN A LA BASE DE DATOS
// -----------------------------------------------------------------------------
if (file_exists(__DIR__ . '/conexion.php')) {
    require_once __DIR__ . '/conexion.php';
} else {
    require_once __DIR__ . '/db.php';
}
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Cargar Autoload de Composer si se utilizan llaves VAPID con Minishlink\WebPush
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

if (file_exists(__DIR__ . '/../turnos_helper.php')) {
    require_once __DIR__ . '/../turnos_helper.php';
}



if (isset($pdo)) {
    $pdo->exec("SET time_zone = '-03:00';");
}

// Cargar credenciales desde variables de entorno o fallback
if (!defined('META_ACCESS_TOKEN')) {
    define('META_ACCESS_TOKEN', getenv('META_ACCESS_TOKEN') ?: 'TU_META_ACCESS_TOKEN');
}

if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: 'TU_OPENAI_API_KEY');
}

// Configuración de llaves VAPID para Web Push
if (!defined('VAPID_PUBLIC_KEY')) {
    define('VAPID_PUBLIC_KEY', 'BB_sUL_80YWtAyK5m995PeOr_K0m_M3aYG89QOMVHXUege_PKuh4FJ7VB2-NSesPJcAfTKUm7_4MXYSFpybqJ1I');
}
if (!defined('VAPID_PRIVATE_KEY')) {
    define('VAPID_PRIVATE_KEY', 'tB2EnpYIX_LalPzTQCbqOnyjbVWp3Pbi8SGZ79vY8KM');
}
if (!defined('VAPID_SUBJECT')) {
    define('VAPID_SUBJECT', 'Notificacion de turno');
}

function logClientes($msg) {
    file_put_contents(__DIR__ . '/log_clientes_1.txt', "[" . date('Y-m-d H:i:s') . "] [CLIENTE] " . $msg . "\n", FILE_APPEND);
}

function normalizarTelefono($numero) {
    return $numero;
}

function obtenerOCrearClienteId($pdo, $negocio_id, $telefono, $nombre) {
    $stmtSelect = $pdo->prepare("SELECT id FROM saas_clientes WHERE negocio_id = ? AND telefono = ? LIMIT 1");
    $stmtSelect->execute([$negocio_id, $telefono]);
    $clienteId = $stmtSelect->fetchColumn();

    if ($clienteId) {
        $stmtUpd = $pdo->prepare("UPDATE saas_clientes SET nombre = ? WHERE id = ?");
        $stmtUpd->execute([$nombre, $clienteId]);
        return $clienteId;
    } else {
        $stmtIns = $pdo->prepare("INSERT INTO saas_clientes (negocio_id, telefono, nombre) VALUES (?, ?, ?)");
        $stmtIns->execute([$negocio_id, $telefono, $nombre]);
        return $pdo->lastInsertId();
    }
}

/**
 * Función para enviar notificación push al negocio
 */
function notificarNuevoTurnoWebPush($pdo, $negocio_id, $nombreCliente, $nombreServicio, $fecha, $hora, $tituloPersonalizado = null, $cuerpoPersonalizado = null) {
    try {
        if (!class_exists('Minishlink\WebPush\WebPush')) {
            $autoloadPath = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
        }

        logClientes("Comienza a procesar push ");

        $stmtSub = $pdo->prepare("SELECT endpoint, p256dh, auth FROM saas_push_subscriptions WHERE negocio_id = ?");
        $stmtSub->execute([$negocio_id]);
        $suscripciones = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

        if (empty($suscripciones)) {
            logClientes("Push WebPush: No existen suscripciones activas para el negocio ID {$negocio_id}");
            return;
        }

        $fechaFmt = date('d/m/Y', strtotime($fecha));
        $horaFmt  = date('H:i', strtotime($hora));

        $titulo = $tituloPersonalizado ?: "🗓️ ¡Nuevo Turno Agendado!";
        $cuerpo  = $cuerpoPersonalizado ?: "{$nombreCliente} reservó {$nombreServicio} para el {$fechaFmt} a las {$horaFmt} hs.";

        $payload = json_encode([
            'title' => $titulo,
            'body'  => $cuerpo,
            'icon'  => "/icon.png",
            'url'   => "/panel.php"
        ]);

        if (class_exists('Minishlink\WebPush\WebPush') && defined('VAPID_PUBLIC_KEY') && defined('VAPID_PRIVATE_KEY')) {
            $auth = [
                'VAPID' => [
                    'subject'    => VAPID_SUBJECT,
                    'publicKey'  => VAPID_PUBLIC_KEY,
                    'privateKey' => VAPID_PRIVATE_KEY,
                ],
            ];

            $webPush = new \Minishlink\WebPush\WebPush($auth);

            foreach ($suscripciones as $sub) {
                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint'        => $sub['endpoint'],
                    'publicKey'       => $sub['p256dh_key'] ?? $sub['p256dh'] ?? '',
                    'authToken'       => $sub['auth_token'] ?? $sub['auth'] ?? '',
                    'contentEncoding' => 'aes128gcm',
                ]);
                $webPush->sendOneNotification($subscription, $payload);
            }

            foreach ($webPush->flush() as $report) {
                if (!$report->isSuccess()) {
                    logClientes("WebPush error en envío: " . $report->getReason());
                }
            }
        }
    } catch (Exception $e) {
        logClientes("Excepción en notificarNuevoTurnoWebPush: " . $e->getMessage());
    }
}

function enviarWhatsAppGeneral($to, $payload, $phoneNumberId) {
    $token = META_ACCESS_TOKEN;
    
    $ch = curl_init("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . trim($token),
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    
    if (curl_errno($ch)) {
        logClientes("Error cURL Meta WhatsApp: " . curl_error($ch));
    } else {
        logClientes("Respuesta Meta API: " . $res);
    }
    
    curl_close($ch);
    return $res;
}

// -----------------------------------------------------------------------------
// FUNCIÓN AUXILIAR: GENERAR PAYLOAD INTERACTIVO DE TURNOS DISPONIBLES
// -----------------------------------------------------------------------------
function obtenerTurnosDisponiblesPayload($pdo, $negocio_id_activo, $servicio_id_activo, $numero_cliente, $fechaDesdeStr = null, $franja = null) {
    logClientes("Generando turnos disponibles para servicio ID: {$servicio_id_activo} | Fecha: " . ($fechaDesdeStr ?: 'HOY') . " | Franja: " . ($franja ?: 'TODAS'));
    
    $stmtServ = $pdo->prepare("SELECT nombre, duracion_minutos FROM saas_servicios WHERE id = ? AND negocio_id = ?");
    $stmtServ->execute([$servicio_id_activo, $negocio_id_activo]);
    $infoServicio = $stmtServ->fetch(PDO::FETCH_ASSOC);

    if (!$infoServicio) {
        return null;
    }

    $duracionMin = (int)$infoServicio['duracion_minutos'] ?: 30;
    
    $stmtH = $pdo->prepare("SELECT dia_semana, hora_apertura, hora_cierre FROM saas_horarios_atencion WHERE negocio_id = ? AND esta_activo = 1");
    $stmtH->execute([$negocio_id_activo]);
    $horariosAtencion = $stmtH->fetchAll(PDO::FETCH_ASSOC);

    $mapaHorarios = [];
    foreach ($horariosAtencion as $h) {
        $mapaHorarios[$h['dia_semana']][] = $h;
    }

    $turnosDisponibles = [];
    
    $esFechaEspecifica = !empty($fechaDesdeStr);
    $fechaLoop = $esFechaEspecifica ? new DateTime($fechaDesdeStr . ' 00:00:00') : new DateTime();
    $diasBuscados = 0;
    $maxDiasAEscanear = $esFechaEspecifica ? 1 : 14; 

    while ($diasBuscados < $maxDiasAEscanear) {
        if (!$esFechaEspecifica && count($turnosDisponibles) >= 5) {
            break;
        }

        $diaSemanaNum = $fechaLoop->format('N');

        if (isset($mapaHorarios[$diaSemanaNum])) {
            foreach ($mapaHorarios[$diaSemanaNum] as $rango) {
                $horaInicioObj = new DateTime($fechaLoop->format('Y-m-d') . ' ' . $rango['hora_apertura']);
                $horaCierreObj = new DateTime($fechaLoop->format('Y-m-d') . ' ' . $rango['hora_cierre']);

                $ahora = new DateTime();
                if ($horaInicioObj < $ahora && $fechaLoop->format('Y-m-d') === $ahora->format('Y-m-d')) {
                    $minutosRestantes = 30 - ($ahora->format('i') % 30);
                    $horaInicioObj = clone $ahora;
                    $horaInicioObj->modify("+{$minutosRestantes} minutes");
                    $horaInicioObj->setTime((int)$horaInicioObj->format('H'), (int)$horaInicioObj->format('i'), 0);
                }

                while ($horaInicioObj < $horaCierreObj) {
                    if (!$esFechaEspecifica && count($turnosDisponibles) >= 5) {
                        break;
                    }

                    if (count($turnosDisponibles) >= 10) {
                        break;
                    }

                    $horaHH = (int)$horaInicioObj->format('H');

                    $cumpleFranja = true;
                    if ($franja === 'manana' && $horaHH >= 13) {
                        $cumpleFranja = false;
                    } elseif ($franja === 'tarde' && $horaHH < 13) {
                        $cumpleFranja = false;
                    }

                    if ($cumpleFranja) {
                        $fInicioStr = $horaInicioObj->format('Y-m-d H:i:s');
                        $fFinObj    = clone $horaInicioObj;
                        $fFinObj->modify("+{$duracionMin} minutes");
                        $fFinStr    = $fFinObj->format('Y-m-d H:i:s');

                        if ($fFinObj <= $horaCierreObj) {
                            $stmtT = $pdo->prepare("SELECT COUNT(*) FROM saas_turnos WHERE negocio_id = ? AND estado != 'cancelado' AND (? < fecha_hora_fin AND ? > fecha_hora_inicio)");
                            $stmtT->execute([$negocio_id_activo, $fInicioStr, $fFinStr]);

                            $stmtB = $pdo->prepare("SELECT COUNT(*) FROM saas_bloqueos WHERE negocio_id = ? AND (? < fecha_hora_fin AND ? > fecha_hora_inicio)");
                            $stmtB->execute([$negocio_id_activo, $fInicioStr, $fFinStr]);

                            if ($stmtT->fetchColumn() == 0 && $stmtB->fetchColumn() == 0) {
                                $turnosDisponibles[] = [
                                    'id_turno'  => 'turno_' . $horaInicioObj->format('Y-m-d_H:i:s'),
                                    'fecha_fmt' => $horaInicioObj->format('d/m/Y'),
                                    'hora_fmt'  => $horaInicioObj->format('H:i') . ' hs'
                                ];
                            }
                        }
                    }

                    $horaInicioObj->modify("+{$duracionMin} minutes");
                }
            }
        }

        $fechaLoop->modify('+1 day');
        $fechaLoop->setTime(0, 0, 0);
        $diasBuscados++;
    }

    if (!empty($turnosDisponibles)) {
        $rows = [];
        foreach ($turnosDisponibles as $t) {
            $rows[] = [
                'id'          => $t['id_turno'],
                'title'       => "{$t['fecha_fmt']} - {$t['hora_fmt']}",
                'description' => "Reservar turno para {$infoServicio['nombre']}"
            ];
        }

        $textoFranja = $franja === 'manana' ? ' (Mañana - Hasta las 13 hs)' : ($franja === 'tarde' ? ' (Tarde - Desde las 13 hs)' : '');

        if ($esFechaEspecifica) {
            $cuerpoMensaje = "Turnos disponibles para *{$infoServicio['nombre']}* el *" . date('d/m/Y', strtotime($fechaDesdeStr)) . "*{$textoFranja}:\n\n" .
                             "👉 Seleccioná el horario que prefieras de la lista:";
        } else {
            $cuerpoMensaje = "Próximos turnos disponibles para *{$infoServicio['nombre']}*:\n\n" .
                             "👉 Elegí uno de la lista o indicame una fecha (Mañana/Tarde).\n\n".
                             "(ej: _\"Quiero para el viernes a la tarde\"_ o _\"Turno para el 15/10 a la mañana\"_).";
        }

        return [
            'messaging_product' => 'whatsapp',
            'to'                => $numero_cliente,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'list',
                'header' => ['type' => 'text', 'text' => "Turnos Disponibles"],
                'body'   => ['text' => $cuerpoMensaje],
                'footer' => ['text' => 'Elegí un horario de la lista'],
                'action' => [
                    'button'   => 'Ver Horarios',
                    'sections' => [['title' => 'Horarios Libres', 'rows' => $rows]]
                ]
            ]
        ];
    }

    return null;
}

// -----------------------------------------------------------------------------
// FUNCIÓN AUXILIAR: ENVIAR SOLICITUD DE UBICACIÓN
// -----------------------------------------------------------------------------
function enviarSolicitudUbicacion($numero_cliente, $phoneNumberId, $mensajeTexto = "Para mostrarte los negocios más cercanos, por favor compartí tu ubicación actual.") {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $numero_cliente,
        'type' => 'interactive',
        'interactive' => [
            'type' => 'location_request_message',
            'body' => [
                'text' => $mensajeTexto
            ],
            'action' => [
                'name' => 'send_location'
            ]
        ]
    ];
    return enviarWhatsAppGeneral($numero_cliente, $payload, $phoneNumberId);
}

// -----------------------------------------------------------------------------
// 2. RECEPCIÓN DEL MENSAJE DE WHATSAPP (TEXTO, INTERACTIVO, UBICACIÓN O IMAGEN)
// -----------------------------------------------------------------------------
$inputRaw = file_get_contents('php://input');
logClientes("--- NUEVA PETICION RECIBIDA ---");
logClientes("RAW Input: " . $inputRaw);

$input = json_decode($inputRaw, true);

$messageData   = $input['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
$phoneNumberId = $input['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;

if (!$messageData) {
    logClientes("Petición ignorada: No contiene datos de mensaje.");
    echo json_encode(['status' => 'ignored']);
    exit;
}

$numero_cliente    = normalizarTelefono($messageData['from'] ?? '');
$nombre_cliente_wa = $input['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? 'Cliente';

$texto_mensaje       = '';
$id_negocio_elegido  = null;
$id_servicio_elegido = null;
$turno_elegido_str   = null;
$accion = null;

// Cargar sesión previa antes de procesar
$stmtSesion = $pdo->prepare("SELECT negocio_id, paso, datos_json FROM saas_clientes_sesiones WHERE telefono = ? LIMIT 1");
$stmtSesion->execute([$numero_cliente]);
$sesionActual = $stmtSesion->fetch(PDO::FETCH_ASSOC);

$datosSesion = ($sesionActual && !empty($sesionActual['datos_json'])) ? json_decode($sesionActual['datos_json'], true) : [];

// -----------------------------------------------------------------------------
// 2.1 PROCESAMIENTO DE COMPROBANTES DE PAGO (TIPO: IMAGE / DOCUMENT)
// -----------------------------------------------------------------------------
if (($messageData['type'] ?? '') === 'image' || ($messageData['type'] ?? '') === 'document') {
    $tipoMedia = $messageData['type'];
    logClientes("Archivo/Comprobante ({$tipoMedia}) recibido de {$numero_cliente}");

    // Buscar si el cliente tiene un turno pendiente de pago/revisión por transferencia
    $stmtTurnoPendiente = $pdo->prepare("
        SELECT 
            t.id AS turno_id,
            t.negocio_id,
            t.monto_sena,
            t.fecha_hora_inicio,
            s.nombre AS servicio_nombre,
            n.nombre AS negocio_nombre
        FROM saas_turnos t
        INNER JOIN saas_clientes c ON t.cliente_id = c.id
        INNER JOIN saas_servicios s ON t.servicio_id = s.id
        INNER JOIN saas_negocios n ON t.negocio_id = n.id
        WHERE c.telefono = ? 
          AND t.metodo_pago = 'transferencia' 
          AND t.estado IN ('pendiente_pago', 'pendiente_revision')
        ORDER BY t.creado_el DESC
        LIMIT 1
    ");
    $stmtTurnoPendiente->execute([$numero_cliente]);
    $turnoPend = $stmtTurnoPendiente->fetch(PDO::FETCH_ASSOC);

    if (!$turnoPend) {
        $payloadWa = [
            'messaging_product' => 'whatsapp',
            'to' => $numero_cliente,
            'type' => 'text',
            'text' => ['body' => "Recibimos tu archivo, pero no registramos ningún turno pendiente de pago por transferencia a tu nombre."]
        ];
        enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
        echo json_encode(['status' => 'success', 'reason' => 'sin_turno_pendiente']);
        exit;
    }

    $mediaId = $messageData['image']['id'] ?? ($messageData['document']['id'] ?? null);

	logClientes("Archivo/Comprobante ({$mediaId}) ");
    if ($mediaId) {
        // 1. Obtener URL del archivo desde Meta API
        $ch = curl_init("https://graph.facebook.com/v20.0/{$mediaId}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . META_ACCESS_TOKEN]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $mediaRes = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($mediaRes['url'])) {
            // 2. Descargar archivo en binario
            $ch = curl_init($mediaRes['url']);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . META_ACCESS_TOKEN,
                "User-Agent: PHP-Script"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $fileBin = curl_exec($ch);
            curl_close($ch);

            if ($fileBin) {
                $esComprobante = false;
                $montoDetectado = 0;
                $numOperacion = null;

                // 3. Evaluar según si es IMAGEN o DOCUMENTO (PDF)
                if ($tipoMedia === 'image') {
                    $base64Img = base64_encode($fileBin);
                    $payloadVision = [
								    'model' => 'gpt-4o',
								    'messages' => [
								        [
								            'role' => 'user',
								            'content' => [
								                [
								                    'type' => 'text', 
								                    'text' => "Analizá la siguiente imagen. ¿Corresponde a un comprobante de transferencia bancaria, pago o transferencia de billetera virtual? Responde en JSON estricto: {\"es_comprobante\": true|false, \"monto\": number, \"numero_operacion\": \"string\", \"nombre_origen\": \"string|null\", \"banco_origen\": \"string|null\"}"
								                ],
								                [
								                    'type' => 'image_url', 
								                    'image_url' => ['url' => "data:image/jpeg;base64,{$base64Img}"]
								                ]
								            ]
								        ]
								    ],
								    'response_format' => ['type' => 'json_object']
								];

                    $ch = curl_init('https://api.openai.com/v1/chat/completions');
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . OPENAI_API_KEY, "Content-Type: application/json"]);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadVision));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $resVisionRaw = curl_exec($ch);
                    curl_close($ch);

                    $resVision = json_decode($resVisionRaw, true);
                    $dataVision = json_decode($resVision['choices'][0]['message']['content'] ?? '{}', true);

                    $esComprobante  = $dataVision['es_comprobante'] ?? false;
                    $montoDetectado = $dataVision['monto'] ?? 0;
                    $numOperacion   = $dataVision['numero_operacion'] ?? null;

     } elseif ($tipoMedia === 'document') {
    logClientes("Procesando documento PDF de {$numero_cliente} vía OpenAI Files API");

    $esComprobante  = false;
    $montoDetectado = 0;
    $numOperacion   = null;

    // 1. Guardar el binario del PDF temporalmente en el servidor
    $tempPdfPath = sys_get_temp_dir() . '/comprobante_' . time() . '_' . uniqid() . '.pdf';
    file_put_contents($tempPdfPath, $fileBin);

    try {
        // 2. Subir el archivo PDF a la API de OpenAI (/v1/files)
        $cFile = new CURLFile($tempPdfPath, 'application/pdf', 'comprobante.pdf');
        $ch = curl_init('https://api.openai.com/v1/files');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . OPENAI_API_KEY
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'purpose' => 'user_data',
            'file' => $cFile
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resFileRaw = curl_exec($ch);
        curl_close($ch);

        $resFileData = json_decode($resFileRaw, true);
        $fileId = $resFileData['id'] ?? null;

        logClientes("Respuesta Subida File OpenAI ID: " . ($fileId ?: 'ERROR'));

        if ($fileId) {
            // 3. Consultar a gpt-4o pasándole el file_id del PDF
            $payloadGpt = [
						    'model' => 'gpt-4o',
						    'messages' => [
						        [
						            'role' => 'user',
						            'content' => [
						                [
						                    'type' => 'text',
						                    'text' => "Analizá el archivo PDF adjunto. ¿Corresponde a un comprobante de transferencia bancaria, pago o transferencia de billetera virtual? Responde en JSON estricto: {\"es_comprobante\": true|false, \"monto\": number, \"numero_operacion\": \"string\", \"nombre_origen\": \"string|null\", \"banco_origen\": \"string|null\"}"
						                ],
						                [
						                    'type' => 'file',
						                    'file' => ['file_id' => $fileId]
						                ]
						            ]
						        ]
						    ],
						    'response_format' => ['type' => 'json_object']
						];

            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . OPENAI_API_KEY, 
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadGpt));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $resGptRaw = curl_exec($ch);
            curl_close($ch);

            $resGpt = json_decode($resGptRaw, true);
            $dataVision = json_decode($resGpt['choices'][0]['message']['content'] ?? '{}', true);

            logClientes("Resultado OpenAI PDF via File ID: " . json_encode($dataVision));

            $esComprobante  = $dataVision['es_comprobante'] ?? false;
            $montoDetectado = $dataVision['monto'] ?? 0;
            $numOperacion   = $dataVision['numero_operacion'] ?? null;

            // 4. Eliminar el archivo subido en OpenAI para mantener limpia la cuenta
            $ch = curl_init("https://api.openai.com/v1/files/{$fileId}");
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . OPENAI_API_KEY]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (\Exception $e) {
        logClientes("Excepción en procesamiento de PDF: " . $e->getMessage());
    } finally {
        // Eliminar archivo temporal local
        if (file_exists($tempPdfPath)) {
            @unlink($tempPdfPath);
        }
    }
}
				
                if ($esComprobante) {
                    // A) Actualizar estado del turno a 'pendiente_revision'
                    $stmtUpdTurno = $pdo->prepare("UPDATE saas_turnos SET estado = 'pendiente_revision' WHERE id = ?");
                    $stmtUpdTurno->execute([$turnoPend['turno_id']]);

                    // B) REGISTRAR EN LA TABLA saas_comprobantes_pago
                    $nombreOrigen = $dataVision['nombre_origen'] ?? null;
					$bancoOrigen  = $dataVision['banco_origen'] ?? null;
					
					// REGISTRAR EN LA TABLA saas_comprobantes_pago
					$stmtInsComp = $pdo->prepare("
					    INSERT INTO saas_comprobantes_pago 
					    (turno_id, negocio_id, cliente_telefono, url_comprobante, whatsapp_media_id, monto_detectado, numero_operacion, nombre_origen, banco_origen, estado, validado_por)
					    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', 'ia_openai')
					");
					$stmtInsComp->execute([
					    $turnoPend['turno_id'],
					    $turnoPend['negocio_id'],
					    $numero_cliente,
					    $mediaRes['url'] ?? '',
					    $mediaId,
					    $montoDetectado,
					    $numOperacion,
					    $nombreOrigen,
					    $bancoOrigen
					]);

                    // C) Responder al cliente
                    $msjCliente = "🧾 *Comprobante Recibido con Éxito*\n\n"
                        . "Hemos registrado tu comprobante para el turno de *{$turnoPend['servicio_nombre']}* en *{$turnoPend['negocio_nombre']}*.\n"
                        . "El comercio verificará la transferencia y confirmará tu reserva en breve. ¡Muchas gracias!";

                    $payloadWa = [
                        'messaging_product' => 'whatsapp',
                        'to' => $numero_cliente,
                        'type' => 'text',
                        'text' => ['body' => $msjCliente]
                    ];
                    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);

                    // D) Notificar Push al dueño del negocio
                    $fechaStr = date('Y-m-d', strtotime($turnoPend['fecha_hora_inicio']));
                    $horaStr  = date('H:i:s', strtotime($turnoPend['fecha_hora_inicio']));

                    notificarNuevoTurnoWebPush(
                        $pdo, 
                        $turnoPend['negocio_id'], 
                        $nombre_cliente_wa, 
                        $turnoPend['servicio_nombre'], 
                        $fechaStr, 
                        $horaStr,
                        "🧾 Comprobante Recibido",
                        "{$nombre_cliente_wa} envió el comprobante de seña para el turno de {$turnoPend['servicio_nombre']}."
                    );

                    echo json_encode(['status' => 'success', 'reason' => 'comprobante_procesado']);
                    exit;
                } else {
                    $payloadWa = [
                        'messaging_product' => 'whatsapp',
                        'to' => $numero_cliente,
                        'type' => 'text',
                        'text' => ['body' => "⚠️ El archivo enviado no parece ser un comprobante de transferencia válido. Por favor, volvé a enviar una imagen o PDF claro del comprobante de pago."]
                    ];
                    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
                    echo json_encode(['status' => 'success', 'reason' => 'comprobante_no_valido']);
                    exit;
                }
            }
        }
    }
}
// RECEPCIÓN DE UBICACIÓN
if (($messageData['type'] ?? '') === 'location') {
    $latitud  = $messageData['location']['latitude'] ?? null;
    $longitud = $messageData['location']['longitude'] ?? null;

    logClientes("Ubicación recibida de {$numero_cliente}: Lat {$latitud}, Lon {$longitud}");

    if ($latitud && $longitud) {
        $datosSesion['latitud']  = $latitud;
        $datosSesion['longitud'] = $longitud;
        $texto_mensaje           = "Envié mi ubicación GPS.";
    }
} 
// Extraer el texto si es un mensaje de texto plano de WhatsApp
elseif (($messageData['type'] ?? '') === 'text') {
    $texto_mensaje = $messageData['text']['body'] ?? '';
}
// Evaluar detección automática por nombre solo en mensajes de texto directos y si no hay negocio activo en la sesión
elseif (($messageData['type'] ?? '') === 'text' && empty($negocio_id_activo) && mb_strlen(trim($texto_mensaje)) > 3) {
    $stmtBuscNegocio = $pdo->prepare("
        SELECT id 
        FROM saas_negocios 
        WHERE paso_onboarding = 'COMPLETADO' 
          AND (estado IS NULL OR estado = 'activo')
          AND LOWER(?) LIKE CONCAT('%', LOWER(nombre), '%')
        LIMIT 1
    ");
    $stmtBuscNegocio->execute([$texto_mensaje]);
    $negocioDetectadoId = $stmtBuscNegocio->fetchColumn();

    if ($negocioDetectadoId) {
        $id_negocio_elegido = (int)$negocioDetectadoId;
        logClientes("Negocio detectado en texto libre: ID {$id_negocio_elegido}");
    }
}
elseif (($messageData['type'] ?? '') === 'interactive') {
    if (($messageData['interactive']['type'] ?? '') === 'list_reply') {
        $id_seleccionado = $messageData['interactive']['list_reply']['id'] ?? '';
        logClientes("Respuesta interactiva seleccionada: {$id_seleccionado}");
        
        if (strpos($id_seleccionado, 'rubro_') === 0) {
            $rubro_elegido = urldecode(str_replace('rubro_', '', $id_seleccionado));
            $texto_mensaje = "Quiero ver los negocios del rubro {$rubro_elegido}.";
            $datosSesion['rubro_filtro'] = $rubro_elegido;
        }
        elseif (strpos($id_seleccionado, 'negocio_') === 0) {
            $id_negocio_elegido = (int)str_replace('negocio_', '', $id_seleccionado);
            $texto_mensaje = "Seleccioné el negocio ID {$id_negocio_elegido}.";
        }
        elseif (strpos($id_seleccionado, 'servicio_') === 0) {
            $id_servicio_elegido = (int)str_replace('servicio_', '', $id_seleccionado);
            $texto_mensaje = "Seleccioné el servicio ID {$id_servicio_elegido}. Por favor mostrame los turnos disponibles.";
        }
        elseif (strpos($id_seleccionado, 'turno_') === 0) {
            $turno_elegido_str = str_replace('turno_', '', $id_seleccionado);
            $texto_mensaje = "Quiero confirmar el turno del {$turno_elegido_str}.";
        }
    }
    elseif (($messageData['interactive']['type'] ?? '') === 'button_reply') {
        $btnId = $messageData['interactive']['button_reply']['id'] ?? '';
        logClientes("Botón seleccionado: {$btnId}");

        if (strpos($btnId, 'confirmar_turno_') === 0) {
            $turnoId = (int)str_replace('confirmar_turno_', '', $btnId);

            $stmtConf = $pdo->prepare("UPDATE saas_turnos SET estado = 'confirmado' WHERE id = ?");
            $stmtConf->execute([$turnoId]);

            $payloadWa = [
                'messaging_product' => 'whatsapp',
                'to' => $numero_cliente,
                'type' => 'text',
                'text' => ['body' => "👍 ¡Excelente! Tu turno ha sido confirmado. Te esperamos."]
            ];
            enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
            echo json_encode(['status' => 'success']);
            exit;
        }
//        
	elseif (strpos($btnId, 'cancelar_turno_') === 0) {
            $turnoId = (int)str_replace('cancelar_turno_', '', $btnId);

            // Obtener el negocio_id asociado al turno antes de cancelar
            $stmtNeg = $pdo->prepare("SELECT negocio_id FROM saas_turnos WHERE id = ?");
            $stmtNeg->execute([$turnoId]);
            $negId = $stmtNeg->fetchColumn() ?: $negocio_id_activo;

            if ($negId) {
                // cancelarTurnoCompleto ya actualiza BD, desactiva recordatorios y dispara el WebPush al negocio
                cancelarTurnoCompleto($pdo, $turnoId, $negId);
            } else {
                // Fallback por si no se encuentra el negocio
                $stmtCancel = $pdo->prepare("UPDATE saas_turnos SET estado = 'cancelado' WHERE id = ?");
                $stmtCancel->execute([$turnoId]);
            }

            $payloadWa = [
                'messaging_product' => 'whatsapp',
                'to' => $numero_cliente,
                'type' => 'text',
                'text' => ['body' => "❌ Tu turno ha sido cancelado con éxito. El horario ha quedado liberado."]
            ];
            enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
            echo json_encode(['status' => 'success']);
            exit;
        } 
//			
        elseif ($btnId === 'pedir_ubicacion') {
            enviarSolicitudUbicacion($numero_cliente, $phoneNumberId, "Compartí tu ubicación para encontrar las opciones más cercanas:");
            echo json_encode(['status' => 'success']);
            exit;
        }
        elseif ($btnId === 'omitir_ubicacion') {
		    $texto_mensaje = "Ver todos los comercios sin filtrar por cercanía.";
		    unset($datosSesion['latitud'], $datosSesion['longitud'], $datosSesion['negocio_id'], $datosSesion['servicio_id']);
		    $negocio_id_activo = null;
		    $servicio_id_activo = null;
		    $accion = 'LISTAR_RUBROS';
		}
    }
}

// -----------------------------------------------------------------------------
// 2.B.0. INTERCEPCIÓN PREVIA DE RESETEO Y NAVEGACIÓN
// -----------------------------------------------------------------------------
$texto_minuscula = mb_strtolower(trim($texto_mensaje));

$esInicio = (preg_match('/^(inicio|menu|menú|volver|volver al inicio)$/i', $texto_minuscula));

if ($esInicio){
	   $payloadWa = [
        'messaging_product' => 'whatsapp',
        'to' => $numero_cliente,
        'type' => 'interactive',
        'interactive' => [
            'type' => 'button',
            'body' => [
                'text' => "📍 *Búsqueda de Comercios*\n\nPodés compartirnos tu ubicación para encontrar los locales más cercanos, o ver todos los comercios disponibles por categoría."
            ],
            'action' => [
                'buttons' => [
                    [
                        'type' => 'reply',
                        'reply' => ['id' => 'pedir_ubicacion', 'title' => '📍 Enviar Ubicación']
                    ],
                    [
                        'type' => 'reply',
                        'reply' => ['id' => 'omitir_ubicacion', 'title' => '📂 Ver Categorías']
                    ]
                ]
            ]
        ]
    ];

    logClientes("Enviando invitación de ubicación/categorías a WhatsApp");
    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success']);
    exit;
}	

$esPedidoDeCategorias = (
    preg_match('/(ver|mostrar|todas|todos|cambiar|volver).*(categor[ií]as|rubros)/i', $texto_mensaje) || 
    preg_match('/^(categor[ií]as|rubros)$/i', $texto_minuscula)
);

if ($esPedidoDeCategorias) {
    logClientes("Intercepción manual: Cliente solicitó reiniciar navegación a rubros.");
    
    // 1. Limpieza total de sesión local y variables activas
    unset($datosSesion['rubro_filtro'], $datosSesion['negocio_id'], $datosSesion['servicio_id']);
    $id_negocio_elegido  = null;
    $id_servicio_elegido = null;
    $negocio_id_activo   = null;
    $servicio_id_activo  = null;

    // 2. Guardar sesión limpia en la Base de Datos
    $stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET negocio_id = NULL, datos_json = ? WHERE telefono = ?");
    $stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);

    // 3. Consultar y enviar rubros directamente sin pasar por GPT ni guardar historial viejo
    $stmtRubros = $pdo->prepare("
        SELECT DISTINCT rubro 
        FROM saas_negocios 
        WHERE paso_onboarding = 'COMPLETADO' 
          AND (estado IS NULL OR estado = 'activo')
          AND rubro IS NOT NULL AND rubro != ''
        LIMIT 10
    ");
    $stmtRubros->execute();
    $listaRubros = $stmtRubros->fetchAll(PDO::FETCH_COLUMN);

    if (empty($listaRubros)) {
        $payloadWa = [
            'messaging_product' => 'whatsapp',
            'to' => $numero_cliente,
            'type' => 'text',
            'text' => ['body' => "🔍 En este momento no hay categorías disponibles."]
        ];
    } else {
        $rows = [];
        foreach ($listaRubros as $r) {
            $rows[] = [
                'id' => 'rubro_' . urlencode($r), 
                'title' => mb_substr($r, 0, 24), 
                'description' => 'Ver locales de esta categoría'
            ];
        }

        $payloadWa = [
            'messaging_product' => 'whatsapp',
            'to' => $numero_cliente,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => ['type' => 'text', 'text' => 'Categorías Disponibles'],
                'body' => ['text' => 'Elegí el tipo de servicio que estás buscando:'],
                'footer' => ['text' => 'Secretario IA'],
                'action' => [
                    'button' => 'Ver Categorías', 
                    'sections' => [['title' => 'Rubros', 'rows' => $rows]]
                ]
            ]
        ];
    }

    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success', 'reason' => 'reset_rubros_intercepted']);
    exit; // Corta la ejecución aquí para evitar que vuelva a evaluarse más abajo
}

// -----------------------------------------------------------------------------
// 2.B.1 INTERCEPCIÓN PREVIA DE CONSULTAS DE AYUDA / INSTRUCTIVO
// -----------------------------------------------------------------------------
$texto_minuscula = mb_strtolower(trim($texto_mensaje));

// Intercepción previa de reseteo/volver a categorías
$esPedidoDeCategorias = (
    preg_match('/(ver|mostrar|todas|todos|cambiar|volver).*(categor[ií]as|rubros)/i', $texto_mensaje) || 
    preg_match('/^(categor[ií]as|rubros|inicio|menu|menú)$/i', mb_strtolower(trim($texto_mensaje)))
);

if ($esPedidoDeCategorias) {
    logClientes("Intercepción manual: Cliente solicitó reiniciar navegación a rubros.");
    
    // Limpieza total del contexto en la sesión local
    unset($datosSesion['rubro_filtro'], $datosSesion['negocio_id'], $datosSesion['servicio_id']);
    $id_negocio_elegido  = null;
    $id_servicio_elegido = null;
    $negocio_id_activo   = null;
    $servicio_id_activo  = null;

    // Guardar sesión limpia en la Base de Datos
    $stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET negocio_id = NULL, datos_json = ? WHERE telefono = ?");
    $stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);

    // Forzar ejecución inmediata del bloque LISTAR_RUBROS
    $accion = 'LISTAR_RUBROS';
}

if (preg_match('/^(ayuda|como funciona|cómo funciona|como hago|cómo hago|comandos|instructivo|instrucciones|me ayudas|me ayudás)$/i', $texto_minuscula)) {
    logClientes("Interceptada consulta de ayuda para cliente.");

    $respuestaAyuda = "🤖 *¡Hola! Soy tu Asistente de Turnos y Recordatorios*\n\n"
        . "Puedo ayudarte con lo siguiente:\n\n"
        . "📅 *Agendar o ver locales:* \"Quiero agendar un turno\" o \"Ver negocios\"\n"
        . "📍 *Buscar por cercanía:* Podés compartirme tu ubicación en cualquier momento.\n"
        . "🔎 *Consultar tu agenda:* \"¿Qué tengo agendado?\" o \"Mis reservas\"\n"
        . "❌ *Cancelar:* \"Quiero cancelar mi turno de peluquería\" o \"Quitar el recordatorio del super\"\n\n"
        . "Escribime lo que necesites.";

    $payloadWa = [
        'messaging_product' => 'whatsapp',
        'to' => $numero_cliente,
        'type' => 'text',
        'text' => ['body' => $respuestaAyuda]
    ];

    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success', 'reason' => 'help_handled']);
    exit;
}

// -----------------------------------------------------------------------------
// 3. GESTIÓN DE SESIÓN Y HISTORIAL DE CONVERSACIÓN
// -----------------------------------------------------------------------------
if ($id_negocio_elegido) {
    $datosSesion['negocio_id'] = $id_negocio_elegido;
    unset($datosSesion['servicio_id']);
}
if ($id_servicio_elegido) {
    $datosSesion['servicio_id'] = $id_servicio_elegido;
}

$negocio_id_activo  = $datosSesion['negocio_id'] ?? null;
$servicio_id_activo = $datosSesion['servicio_id'] ?? null;

$historialMensajes = $datosSesion['historial'] ?? [];
if (!empty($texto_mensaje)) {
    $historialMensajes[] = ['role' => 'user', 'content' => $texto_mensaje];
}
if (count($historialMensajes) > 10) {
    $historialMensajes = array_slice($historialMensajes, -10);
}
$datosSesion['historial'] = $historialMensajes;

if ($id_negocio_elegido || $id_servicio_elegido || $sesionActual || !empty($texto_mensaje)) {
    $stmtUpdSesion = $pdo->prepare("
        INSERT INTO saas_clientes_sesiones (telefono, negocio_id, paso, datos_json) 
        VALUES (?, ?, 'EN_INTERACCION', ?) 
        ON DUPLICATE KEY UPDATE negocio_id = VALUES(negocio_id), paso = VALUES(paso), datos_json = VALUES(datos_json)
    ");
    $stmtUpdSesion->execute([$numero_cliente, $negocio_id_activo, json_encode($datosSesion)]);
}

// -----------------------------------------------------------------------------
// 3.B. SI SELECCIONÓ UN NEGOCIO -> MOSTRAR SUS SERVICIOS Y DIRECCIÓN
// -----------------------------------------------------------------------------
if ($id_negocio_elegido) {
    logClientes("Procesando lista de servicios para negocio ID: {$id_negocio_elegido}");

    $stmtNeg = $pdo->prepare("SELECT nombre, direccion, localidad, link_google_maps FROM saas_negocios WHERE id = ?");
    $stmtNeg->execute([$id_negocio_elegido]);
    $infoNegocio = $stmtNeg->fetch(PDO::FETCH_ASSOC);

    $nombreNeg = $infoNegocio['nombre'] ?? "el comercio";
    
    $ubicacionArr = array_filter([$infoNegocio['direccion'] ?? null, $infoNegocio['localidad'] ?? null]);
    $textoUbicacion = !empty($ubicacionArr) ? implode(", ", $ubicacionArr) : null;

    $stmtServicios = $pdo->prepare("
        SELECT id, nombre, precio, duracion_minutos 
        FROM saas_servicios 
        WHERE negocio_id = ? AND (estado IS NULL OR estado = 'activo')
        ORDER BY nombre ASC
        LIMIT 10
    ");
    $stmtServicios->execute([$id_negocio_elegido]);
    $listaServicios = $stmtServicios->fetchAll(PDO::FETCH_ASSOC);

    if (empty($listaServicios)) {
        $payloadWa = [
            'messaging_product' => 'whatsapp',
            'to' => $numero_cliente,
            'type' => 'text',
            'text' => ['body' => "El negocio *{$nombreNeg}* no posee servicios activos en este momento."]
        ];
    } else {
        $rows = [];
        foreach ($listaServicios as $s) {
            $precioFmt = $s['precio'] > 0 ? " | $" . number_format($s['precio'], 0, ',', '.') : "";
            $desc = "{$s['duracion_minutos']} min{$precioFmt}";

            $rows[] = [
                'id' => 'servicio_' . $s['id'],
                'title' => substr($s['nombre'], 0, 24),
                'description' => substr($desc, 0, 72)
            ];
        }

        $bodyTexto = "Seleccioná el servicio que deseas agendar:";
        if ($textoUbicacion) {
            $bodyTexto = "📍 *Ubicación:* {$textoUbicacion}\n\n" . $bodyTexto;
        }

        $payloadWa = [
            'messaging_product' => 'whatsapp',
            'to' => $numero_cliente,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => ['type' => 'text', 'text' => "Servicios de {$nombreNeg}"],
                'body' => ['text' => $bodyTexto],
                'footer' => ['text' => 'Secretario IA'],
                'action' => [
                    'button' => 'Ver Servicios',
                    'sections' => [['title' => 'Servicios Disponibles', 'rows' => $rows]]
                ]
            ]
        ];
    }

    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success']);
    exit;
}

// -----------------------------------------------------------------------------
// 4. SI ELIGIÓ UN SERVICIO -> GENERAR TURNOS MÁS CERCANOS
// -----------------------------------------------------------------------------
if ($id_servicio_elegido && $negocio_id_activo) {
    $payloadWa = obtenerTurnosDisponiblesPayload($pdo, $negocio_id_activo, $servicio_id_activo, $numero_cliente);

    if ($payloadWa) {
        enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// -----------------------------------------------------------------------------
// 5. SI SELECCIONÓ UN TURNO DE LA LISTA -> CONFIRMAR Y GUARDAR
// -----------------------------------------------------------------------------
if ($turno_elegido_str && $servicio_id_activo && $negocio_id_activo) {
    logClientes("Confirmando turno interactivo: {$turno_elegido_str}");
    
    $partes = explode('_', $turno_elegido_str);
    $fecha = $partes[0] ?? date('Y-m-d');
    $hora  = $partes[1] ?? '00:00';

    // Ejecutar creación del turno desde el helper centralizado
    $resTurno = agendarTurnoCompleto(
        $pdo, 
        $negocio_id_activo, 
        $nombre_cliente_wa, 
        $numero_cliente, 
        $servicio_id_activo, 
        $fecha, 
        $hora, 
        'whatsapp'
    );

    if ($resTurno['status'] === 'success') {
        if (empty($resTurno['requiere_sena'])) {
            $confirmacion = "🎉 *¡Turno Agendado con Éxito!*\n\n" .
                            "🏢 *Lugar:* {$resTurno['nombre_negocio']}\n" .
                            "💈 *Servicio:* {$resTurno['nombre_servicio']}\n" .
                            "📅 *Fecha:* " . date('d/m/Y', strtotime($fecha)) . "\n" .
                            "⏰ *Hora:* " . date('H:i', strtotime($hora)) . " hs\n\n" .
                            "¡Te esperamos!";

            $payloadWa = [
                'messaging_product' => 'whatsapp',
                'to' => $numero_cliente,
                'type' => 'text',
                'text' => ['body' => $confirmacion]
            ];
            enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
        }
    } else {
        $payloadWa = [
            'messaging_product' => 'whatsapp',
            'to' => $numero_cliente,
            'type' => 'text',
            'text' => ['body' => "⚠️ " . $resTurno['message']]
        ];
        enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    }

    echo json_encode(['status' => 'success']);
    exit;
}
logClientes("Evaluando mensaje con GPT-4o-mini...");

// -----------------------------------------------------------------------------
// 6. EVALUACIÓN Y RESPUESTAS LIBRES VÍA GPT-4o-mini
// -----------------------------------------------------------------------------
$fechaHoy   = date('Y-m-d');
$horaActual = date('H:i:s');
$diaSemana  = date('N');

$diasSemanaNom = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
$fechasProximosDias = "";

for ($i = 0; $i < 7; $i++) {
    $fechaAux = new DateTime();
    $fechaAux->modify("+{$i} days");
    $diaNum = $fechaAux->format('N');
    $fechasProximosDias .= "- {$diasSemanaNom[$diaNum]} " . ($i === 0 ? "(HOY)" : "") . ": " . $fechaAux->format('Y-m-d') . "\n";
}

$stmtRubrosLista = $pdo->query("SELECT DISTINCT rubro FROM saas_negocios WHERE paso_onboarding = 'COMPLETADO' AND (estado IS NULL OR estado = 'activo') AND rubro IS NOT NULL AND rubro != ''");
$rubrosDisponibles = $stmtRubrosLista->fetchAll(PDO::FETCH_COLUMN);
$jsonRubros = json_encode($rubrosDisponibles, JSON_UNESCAPED_UNICODE);

$jsonServicios = "[]";
$jsonHorarios  = "[]";
$nombreNegocioActivo = "Ninguno seleccionado";

if ($negocio_id_activo) {
    $stmtNeg = $pdo->prepare("SELECT nombre FROM saas_negocios WHERE id = ?");
    $stmtNeg->execute([$negocio_id_activo]);
    $nombreNegocioActivo = $stmtNeg->fetchColumn() ?: "Ninguno seleccionado";

    $stmtServicios = $pdo->prepare("SELECT id, nombre, precio, duracion_minutos FROM saas_servicios WHERE negocio_id = ? AND (estado IS NULL OR estado = 'activo')");
    $stmtServicios->execute([$negocio_id_activo]);
    $jsonServicios = json_encode($stmtServicios->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);

    $stmtHorarios = $pdo->prepare("SELECT dia_semana, hora_apertura, hora_cierre FROM saas_horarios_atencion WHERE negocio_id = ? AND esta_activo = 1");
    $stmtHorarios->execute([$negocio_id_activo]);
    $jsonHorarios = json_encode($stmtHorarios->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
}

$tieneUbicacion = (!empty($datosSesion['latitud']) && !empty($datosSesion['longitud'])) ? "SI" : "NO";

$promptSistema = "Sos la secretaria virtual del sistema central de reservas y recordatorios personales.
HOY ES: {$fechaHoy} {$horaActual} (Día N°: {$diaSemana}).
¿EL CLIENTE ENVÍO UBICACIÓN GPS?: {$tieneUbicacion}.

REGLA DE RESPUESTA:
- NUNCA uses textos comodín como 'Mensaje', 'Mensaje confirmando', 'Confirmacion' o 'Respuesta'. Si usas 'confirmacion', debe ser una respuesta final en lenguaje natural dirigida al usuario.

CALENDARIO DE REFERENCIA:
{$fechasProximosDias}

RUBROS / CATEGORÍAS DISPONIBLES:
{$jsonRubros}

NEGOCIO SELECCIONADO ACTUALMENTE: '{$nombreNegocioActivo}' (ID: " . ($negocio_id_activo ?? 'Ninguno') . ")

CATÁLOGO DE SERVICIOS DEL NEGOCIO SELECCIONADO:
{$jsonServicios}

HORARIOS DEL NEGOCIO SELECCIONADO:
{$jsonHorarios}


REGLAS DE ATENCIÓN OBLIGATORIAS:
1. REGLA PRIORITARIA DE NAVEGACIÓN: Si el usuario dice 'ver categorías', 'ver rubros', 'todas las categorías', 'todos los rubros', 'volver', 'inicio', 'menú' o desea cambiar de categoría -> DEBES DEVOLVER OBLIGATORIAMENTE 'LISTAR_RUBROS' ignorando cualquier rubro o ubicación previa del historial.
2. Si el cliente pregunta de forma general 'qué negocios hay', 'qué opciones hay', 'quiero un turno', 'buscar cercanos' o explorar sin mencionar un rubro específico:
   - Si NO ha enviado su ubicación aún, devuelve 'PREGUNTAR_UBICACION_O_RUBRO'.
   - Si ha enviado su ubicación aún, devuelve 'LISTAR_RUBROS'.
   - Si el cliente pide explícitamente 'ver categorías', 'ver rubros', 'omitir ubicación' o 'ver todos los negocios', DEBES DEVOLVER 'LISTAR_RUBROS', sin importar si envió o no su ubicación.

3. Si el cliente menciona un rubro/categoría (ej: 'peluquerías', 'estética') o responde con una categoría -> DEBES DEVOLVER LA ACCIÓN 'LISTAR_NEGOCIOS' especificando el 'rubro' filtrado.
4. Si el cliente menciona directamente un nombre de negocio o ya eligió uno, pero no especificó servicio -> devolvé 'LISTAR_NEGOCIOS' o deja que seleccione el negocio.
5. Si el cliente desea consultar los turnos de una FECHA ESPECÍFICA (ej: 'quiero para el viernes', 'tenés el 15/10?'):
   - Si además indica la franja horaria ('mañana' o 'tarde', ej: 'el viernes a la mañana'), devuelve 'MOSTRAR_TURNOS_FECHA' especificando 'fecha' (YYYY-MM-DD) y 'franja' ('manana' o 'tarde').
   - Si NO indica la franja horaria (mañana o tarde), puedes devolver 'RESPONDER_CLIENTE' preguntándole cortésmente si prefiere ver los turnos para el turno 'mañana' (hasta las 13 hs) o 'tarde' (desde las 13 hs) de esa fecha, o devolver 'MOSTRAR_TURNOS_FECHA' con 'franja': null para listar indistintamente.
6. Si el cliente indica DÍA Y HORA ESPECÍFICA (ej: 'quiero para el viernes a las 15 hs') -> devuelve 'RESERVAR_TURNO' con la fecha YYYY-MM-DD y hora HH:MM:SS. Para 'RESERVAR_TURNO', deduce el servicio_id (usando $jsonServicios o $servicio_id_activo), la fecha (YYYY-MM-DD) y la hora (HH:MM:SS).
7. Si el cliente pide guardar o agendar un recordatorio personal (ej: 'recordarme ir al super hoy a las 19 hs comprar leche, pan y queso'):
   - DEBES DEVOLVER LA ACCIÓN 'CREAR_RECORDATORIO'.
   - Extrae el 'titulo_evento' (ej: 'Ir al súper').
   - Calcula 'fecha_evento' (YYYY-MM-DD HH:MM:SS).
   - Si no especifica 'minutos_anticipacion', asigna 30 por defecto.
   - Si menciona ítems o tareas específicas dentro del recordatorio, devuélvelos en un array 'items'. Si no hay lista, incluye el título como un único ítem.
8. Si el cliente consulta sus turnos, reservas, recordatorios o agenda (ej: '¿qué tengo agendado?', 'mis reservas', 'lista de mi agenda', etc.), devolvé 'CONSULTAR_AGENDA' indicando fecha_desde y fecha_hasta.
9. Si el cliente pide CANCELAR, BORRAR, QUITAR o ANULAR sin especificar un elemento exacto (ej: 'quiero cancelar un turno', 'cancelar turno', 'necesito cancelar') -> DEBES DEVOLVER LA ACCIÓN 'SELECCIONAR_TURNO_A_CANCELAR'.
10. Si el cliente especifica cuál elemento quiere cancelar por nombre o fecha (ej: 'cancelar peluquería', 'anular el del viernes') -> DEBES DEVOLVER 'CANCELAR_TURNO' con la propiedad 'busqueda'.
11. Si el cliente responde con un número de opción (ej: '1', 'el 2'), devolvé 'CANCELAR_TURNO' poniendo solo el número en 'busqueda'.
12. Si el usuario dice 'me creas un recordatorio para esto:' o reenvía/cita la información de un turno/evento externo para que lo agendemos -> DEBES DEVOLVER 'CREAR_RECORDATORIO'. Extrae el título, fecha y hora del texto citado.

FORMATO JSON ESTRICTO:
CASO O: Preguntar ubicación/cercanía -> {\"accion\": \"PREGUNTAR_UBICACION_O_RUBRO\"}
CASO 1A: Listar rubros/categorías -> {\"accion\": \"LISTAR_RUBROS\"}
CASO 1B: Listar negocios de un rubro -> {\"accion\": \"LISTAR_NEGOCIOS\", \"rubro\": \"NombreRubro\"}
CASO 1C: Mostrar turnos por fecha y franja -> {\"accion\": \"MOSTRAR_TURNOS_FECHA\", \"fecha\": \"YYYY-MM-DD\", \"franja\": \"manana\"} (franja puede ser \"manana\", \"tarde\" o null)
CASO 2: Reservar turno comercial -> {\"accion\": \"RESERVAR_TURNO\", \"servicio_id\": 1, \"fecha\": \"YYYY-MM-DD\", \"hora\": \"HH:MM:SS\", \"minutos_anticipacion\": 60, \"confirmacion\": \"Procesando reserva...\"}
CASO 3: Crear recordatorio personal -> {\"accion\": \"CREAR_RECORDATORIO\", \"titulo_evento\": \"Ir al súper\", \"fecha_evento\": \"YYYY-MM-DD HH:MM:SS\", \"minutos_anticipacion\": 30, \"items\": [\"Comprar leche\", \"Comprar pan\"]}
CASO 4: Consultar agenda/turnos -> {\"accion\": \"CONSULTAR_AGENDA\", \"fecha_desde\": \"YYYY-MM-DD HH:MM:SS\", \"fecha_hasta\": \"YYYY-MM-DD HH:MM:SS\"}
CASO 5: Cancelar turno especifico -> {\"accion\": \"CANCELAR_TURNO\", \"busqueda\": \"palabra clave, numero o evento\"}
CASO 6: Seleccionar de lista para cancelar -> {\"accion\": \"SELECCIONAR_TURNO_A_CANCELAR\"}
CASO 7: Responder -> {\"accion\": \"RESPONDER_CLIENTE\", \"confirmacion\": \"[Respuesta completa para el cliente]\"}";

$messagesForGpt = [
    ['role' => 'system', 'content' => $promptSistema]
];

foreach ($historialMensajes as $msg) {
    $messagesForGpt[] = [
        'role' => $msg['role'],
        'content' => $msg['content']
    ];
}

$payloadGpt = [
    'model' => 'gpt-4o-mini',
    'messages' => $messagesForGpt,
    'response_format' => ['type' => 'json_object']
];

logClientes("Enviando curl a OpenAI con historial de " . count($historialMensajes) . " mensajes");
// Chequeo previo antes de llamar a OpenAI
if (empty($accion)) {
		$ch = curl_init('https://api.openai.com/v1/chat/completions');
		curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . OPENAI_API_KEY, "Content-Type: application/json"]);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadGpt));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$gptResponseRaw = curl_exec($ch);
		
		if (curl_errno($ch)) {
		    logClientes("Error cURL OpenAI: " . curl_error($ch));
		}
		curl_close($ch);
		
		logClientes("Respuesta RAW de OpenAI: " . $gptResponseRaw);
		
		$gptResponse = json_decode($gptResponseRaw, true);
		$datosIA     = json_decode($gptResponse['choices'][0]['message']['content'] ?? '{}', true);
		$accion       = $datosIA['accion'] ?? 'RESPONDER_CLIENTE';
}
logClientes("Acción determinada por IA: " . $accion . " | JSON IA: " . json_encode($datosIA));

// -----------------------------------------------------------------------------
// 7. PROCESAMIENTO DE ACCIONES DE GPT-4o-mini
// -----------------------------------------------------------------------------

// CASO O: PREGUNTAR SI DESEA USAR UBICACIÓN O VER RUBROS
if ($accion === 'PREGUNTAR_UBICACION_O_RUBRO') {
    $payloadWa = [
        'messaging_product' => 'whatsapp',
        'to' => $numero_cliente,
        'type' => 'interactive',
        'interactive' => [
            'type' => 'button',
            'body' => [
                'text' => "📍 *Búsqueda de Comercios*\n\nPodés compartirnos tu ubicación para encontrar los locales más cercanos, o ver todos los comercios disponibles por categoría."
            ],
            'action' => [
                'buttons' => [
                    [
                        'type' => 'reply',
                        'reply' => ['id' => 'pedir_ubicacion', 'title' => '📍 Enviar Ubicación']
                    ],
                    [
                        'type' => 'reply',
                        'reply' => ['id' => 'omitir_ubicacion', 'title' => '📂 Ver Categorías']
                    ]
                ]
            ]
        ]
    ];

    logClientes("Enviando invitación de ubicación/categorías a WhatsApp");
    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success']);
    exit;
}

// CASO A1: LISTAR RUBROS PRIMERO
if ($accion === 'LISTAR_RUBROS') {

	// Limpiar filtros y selecciones previas para permitir seleccionar de cero
    unset($datosSesion['rubro_filtro'], $datosSesion['negocio_id'], $datosSesion['servicio_id']);
    $negocio_id_activo = null;
    $servicio_id_activo = null;

    // Actualizar la sesión en BD inmediatamente para persistir la limpieza
    $stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET negocio_id = NULL, datos_json = ? WHERE telefono = ?");
    $stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);
	
    $stmtRubros = $pdo->prepare("
        SELECT DISTINCT rubro 
        FROM saas_negocios 
        WHERE paso_onboarding = 'COMPLETADO' 
          AND (estado IS NULL OR estado = 'activo')
          AND rubro IS NOT NULL AND rubro != ''
        LIMIT 10
    ");
    $stmtRubros->execute();
    $listaRubros = $stmtRubros->fetchAll(PDO::FETCH_COLUMN);

    if (empty($listaRubros)) {
        $payloadWa = [
            'messaging_product' => 'whatsapp',
            'to' => $numero_cliente,
            'type' => 'text',
            'text' => ['body' => "🔍 En este momento no hay categorías o comercios disponibles."]
        ];
    } else {
        $rows = [];
        foreach ($listaRubros as $r) {
            $rows[] = [
                'id' => 'rubro_' . urlencode($r), 
                'title' => substr($r, 0, 24), 
                'description' => 'Ver locales de esta categoría'
            ];
        }

        $payloadWa = [
            'messaging_product' => 'whatsapp',
            'to' => $numero_cliente,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => ['type' => 'text', 'text' => 'Categorías Disponibles'],
                'body' => ['text' => 'Elegí el tipo de servicio que estás buscando:'],
                'footer' => ['text' => 'Secretario IA'],
                'action' => [
                    'button' => 'Ver Categorías', 
                    'sections' => [['title' => 'Rubros', 'rows' => $rows]]
                ]
            ]
        ];
    }

    logClientes("Enviando lista de rubros a WhatsApp");
    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success']);
    exit;
}

// CASO A2: LISTAR NEGOCIOS CON DIRECCIÓN / LOCALIDAD
if ($accion === 'LISTAR_NEGOCIOS') {
    unset($datosSesion['servicio_id']);
    
    // Si la IA especificó un rubro explícito en su JSON, usamos ese.
    // Si no vino en la IA, usamos el de la sesión.
    $rubroFiltro = !empty($datosIA['rubro']) ? $datosIA['rubro'] : ($datosSesion['rubro_filtro'] ?? null);

    $lat = $datosSesion['latitud'] ?? null;
    $lon = $datosSesion['longitud'] ?? null;

    $params = [];

    if ($lat && $lon) {
        $sql = "SELECT id, nombre, direccion, localidad, link_google_maps,
                       (6371 * acos(cos(radians(?)) * cos(radians(latitud)) * cos(radians(longitud) - radians(?)) + sin(radians(?)) * sin(radians(latitud)))) AS distancia
                FROM saas_negocios 
                WHERE paso_onboarding = 'COMPLETADO' 
                  AND (estado IS NULL OR estado = 'activo')";
        $params[] = $lat;
        $params[] = $lon;
        $params[] = $lat;

        if ($rubroFiltro) {
            $sql .= " AND rubro LIKE ?";
            $params[] = "%{$rubroFiltro}%";
        }
        $sql .= " ORDER BY distancia ASC LIMIT 10";
    } else {
        $sql = "SELECT id, nombre, direccion, localidad, link_google_maps 
                FROM saas_negocios 
                WHERE paso_onboarding = 'COMPLETADO' 
                  AND (estado IS NULL OR estado = 'activo')";

        if ($rubroFiltro) {
            $sql .= " AND rubro LIKE ?";
            $params[] = "%{$rubroFiltro}%";
        }
        $sql .= " LIMIT 10";
    }

    $stmtNegocios = $pdo->prepare($sql);
    $stmtNegocios->execute($params);
    $listaNegocios = $stmtNegocios->fetchAll(PDO::FETCH_ASSOC);

    if (empty($listaNegocios)) {
        $payloadWa = [
            'messaging_product' => 'whatsapp',
            'to' => $numero_cliente,
            'type' => 'text',
            'text' => ['body' => "🔍 No se encontraron comercios activos para esa categoría."]
        ];
    } else {
        $rows = [];
        foreach ($listaNegocios as $n) {
            $partesUbicacion = array_filter([$n['direccion'] ?? null, $n['localidad'] ?? null]);
            $ubicacionTexto  = !empty($partesUbicacion) ? implode(', ', $partesUbicacion) : 'Ver servicios y turnos';

            if (isset($n['distancia']) && $n['distancia'] !== null) {
                $distanciaFmt   = number_format((float)$n['distancia'], 1, ',', '.');
                $ubicacionTexto = "a {$distanciaFmt} km | " . $ubicacionTexto;
            }

            $rows[] = [
                'id'          => 'negocio_' . $n['id'], 
                'title'       => mb_substr($n['nombre'], 0, 24), 
                'description' => mb_substr($ubicacionTexto, 0, 72)
            ];
        }

        $tituloHeader = $rubroFiltro ? "Locales de {$rubroFiltro}" : "Comercios Disponibles";

        $payloadWa = [
            'messaging_product' => 'whatsapp',
            'to' => $numero_cliente,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => ['type' => 'text', 'text' => $tituloHeader],
                'body' => ['text' => ($lat && $lon) ? 'Ordenados por cercanía a tu posición:' : 'Elegí el negocio donde te gustaría reservar:'],
                'footer' => ['text' => 'Secretario IA'],
                'action' => [
                    'button' => 'Ver Locales', 
                    'sections' => [['title' => 'Locales', 'rows' => $rows]]
                ]
            ]
        ];
    }

    logClientes("Enviando lista de negocios a WhatsApp");
    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success']);
    exit;
}

// CASO A3: MOSTRAR TURNOS POR FECHA
if ($accion === 'MOSTRAR_TURNOS_FECHA') {
    $fechaSolicitada = $datosIA['fecha'] ?? null;
    $franjaSolicitada = $datosIA['franja'] ?? null;

    logClientes("IA solicitó mostrar turnos para la fecha: " . ($fechaSolicitada ?: 'No especificada') . " - Franja: " . ($franjaSolicitada ?: 'Todas'));

    if ($negocio_id_activo && $servicio_id_activo) {
        $payloadWa = obtenerTurnosDisponiblesPayload($pdo, $negocio_id_activo, $servicio_id_activo, $numero_cliente, $fechaSolicitada, $franjaSolicitada);

        if ($payloadWa) {
            enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
            echo json_encode(['status' => 'success']);
            exit;
        } else {
            $textoFranjaErr = $franjaSolicitada === 'manana' ? ' por la mañana (hasta las 13 hs)' : ($franjaSolicitada === 'tarde' ? ' por la tarde (desde las 13 hs)' : '');
            $respuestaTexto = "No encontré turnos disponibles para el día " . date('d/m/Y', strtotime($fechaSolicitada)) . "{$textoFranjaErr}. Podés consultar para otra franja horaria o fecha.";
        }
    } else {
        $respuestaTexto = "Para ver los turnos disponibles de una fecha, primero elegí un negocio y un servicio.";
    }

    $datosSesion['historial'][] = ['role' => 'assistant', 'content' => $respuestaTexto];
    $stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET datos_json = ? WHERE telefono = ?");
    $stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);

    $payloadWa = [
        'messaging_product' => 'whatsapp',
        'to' => $numero_cliente,
        'type' => 'text',
        'text' => ['body' => $respuestaTexto]
    ];

    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success']);
    exit;
}

// CASO B: RESERVAR TURNO VIA GPT
if ($accion === 'RESERVAR_TURNO') {
    $servicio_id = $datosIA['servicio_id'] ?? $servicio_id_activo;
    
    if (!$servicio_id && $negocio_id_activo) {
        $stmtServFirst = $pdo->prepare("SELECT id FROM saas_servicios WHERE negocio_id = ? AND (estado IS NULL OR estado = 'activo') LIMIT 1");
        $stmtServFirst->execute([$negocio_id_activo]);
        $servicio_id = $stmtServFirst->fetchColumn() ?: null;
    }

    $fecha = $datosIA['fecha'] ?? null;
    $hora  = $datosIA['hora'] ?? null;

    if ($negocio_id_activo && $servicio_id && $fecha && $hora) {
        $resTurno = agendarTurnoCompleto(
            $pdo, 
            $negocio_id_activo, 
            $nombre_cliente_wa, 
            $numero_cliente, 
            $servicio_id, 
            $fecha, 
            $hora, 
            'whatsapp'
        );

        if ($resTurno['status'] === 'success') {
            if (empty($resTurno['requiere_sena'])) {
                $mensajeFinal = "🎉 *¡Turno Agendado con Éxito!*\n\n" .
                                "🏢 *Lugar:* {$resTurno['nombre_negocio']}\n" .
                                "💈 *Servicio:* {$resTurno['nombre_servicio']}\n" .
                                "📅 *Fecha:* " . date('d/m/Y', strtotime($fecha)) . "\n" .
                                "⏰ *Hora:* " . date('H:i', strtotime($hora)) . " hs\n\n" .
                                "¡Te esperamos!";
            } else {
                $mensajeFinal = "Reservamos tu turno pero requiere pago de seña. Te enviamos los detalles de pago a continuación.";
            }
        } else {
            $mensajeFinal = "⚠️ " . $resTurno['message'];
        }
    } else {
        $mensajeFinal = "Para agendar el turno necesito confirmar el servicio, la fecha y la hora exactas.";
    }

    $datosSesion['historial'][] = ['role' => 'assistant', 'content' => $mensajeFinal];
    $stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET datos_json = ? WHERE telefono = ?");
    $stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);

    $payloadWa = [
        'messaging_product' => 'whatsapp',
        'to' => $numero_cliente,
        'type' => 'text',
        'text' => ['body' => $mensajeFinal]
    ];

    logClientes("Respuesta de reserva enviada a WhatsApp");
    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success']);
    exit;
}
// CASO B2: CREAR RECORDATORIO PERSONAL
if ($accion === 'CREAR_RECORDATORIO') {
    logClientes("Procesando creación de recordatorio personal.");

    $tituloEvento        = trim($datosIA['titulo_evento'] ?? 'Recordatorio Personal');
    $fechaEvento         = $datosIA['fecha_evento'] ?? date('Y-m-d H:i:s', strtotime('+1 hour'));
    $minutosAnticipacion = (int)($datosIA['minutos_anticipacion'] ?? 30);
    $items               = $datosIA['items'] ?? [];

    if (is_string($items) && !empty($items)) {
        $items = array_map('trim', explode(',', $items));
    }
    if (empty($items) || !is_array($items)) {
        $items = [$tituloEvento];
    }

    $fechaHoraProgramada = date('Y-m-d H:i:s', strtotime("{$fechaEvento} - {$minutosAnticipacion} minutes"));
    $fechaActual         = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        $stmtRec = $pdo->prepare("
            INSERT INTO recordatorios 
            (created_at, telefono, titulo_evento, fecha_evento, minutos_anticipacion, fecha_hora_programada, estado, confirmacion_usuario) 
            VALUES (?, ?, ?, ?, ?, ?, 'pendiente', ?)
        ");
        $stmtRec->execute([
            $fechaActual,
            $numero_cliente,
            $tituloEvento,
            $fechaEvento,
            $minutosAnticipacion,
            $fechaHoraProgramada,
            'si'
        ]);

        $recordatorioId = $pdo->lastInsertId();

        if ($recordatorioId) {
            $stmtDet = $pdo->prepare("
                INSERT INTO recordatorio_detalles 
                (recordatorio_id, item, estado, created_at) 
                VALUES (?, ?, 'pendiente', ?)
            ");

            foreach ($items as $itemTexto) {
                $itemLimpio = trim($itemTexto);
                if (!empty($itemLimpio)) {
                    $stmtDet->execute([
                        $recordatorioId,
                        $itemLimpio,
                        $fechaActual
                    ]);
                }
            }
        }

        $pdo->commit();

        $fechaFmt = date('d/m/Y', strtotime($fechaEvento));
        $horaFmt  = date('H:i', strtotime($fechaEvento));

        $itemsTexto = "";
        if (count($items) > 1 || (count($items) === 1 && $items[0] !== $tituloEvento)) {
            $itemsTexto = "\n📋 *Detalle:*\n" . implode("\n", array_map(fn($it) => "• " . $it, $items));
        }

        $mensajeFinal = "📌 *¡Recordatorio Agendado!*\n\n" .
                        "📝 *Evento:* {$tituloEvento}\n" .
                        "📅 *Fecha:* {$fechaFmt}\n" .
                        "⏰ *Hora:* {$horaFmt} hs\n" .
                        "🔔 *Notificación:* {$minutosAnticipacion} min antes" .
                        $itemsTexto . "\n\n" .
                        "Te avisaré en la fecha y hora indicadas. 👍";

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logClientes("Error SQL al guardar recordatorio: " . $e->getMessage());
        $mensajeFinal = "⚠️ Hubo un error al guardar tu recordatorio. Por favor intentá de nuevo.";
    }

    $datosSesion['historial'][] = ['role' => 'assistant', 'content' => $mensajeFinal];
    $stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET datos_json = ? WHERE telefono = ?");
    $stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);

    $payloadWa = [
        'messaging_product' => 'whatsapp',
        'to' => $numero_cliente,
        'type' => 'text',
        'text' => ['body' => $mensajeFinal]
    ];

    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success']);
    exit;
}

// CASO C: CONSULTAR AGENDA Y TURNOS DEL CLIENTE
if ($accion === 'CONSULTAR_AGENDA') {
    $fechaDesde = $datosIA['fecha_desde'] ?? date('Y-m-d 00:00:00');
    $fechaHasta = $datosIA['fecha_hasta'] ?? date('Y-m-d 23:59:59', strtotime('+30 days'));

    try {
        $stmtAgenda = $pdo->prepare("
            SELECT 
                t.fecha_hora_inicio AS fecha_evento,
                CONCAT('Turno de ', s.nombre, ' en ', n.nombre) AS titulo_evento,
                'turno' AS tipo
            FROM saas_turnos t
            JOIN saas_clientes c ON t.cliente_id = c.id
            JOIN saas_servicios s ON t.servicio_id = s.id
            JOIN saas_negocios n ON t.negocio_id = n.id
            WHERE c.telefono = ? 
              AND t.estado != 'cancelado' 
              AND t.fecha_hora_inicio BETWEEN ? AND ?

            UNION ALL

            SELECT 
                r.fecha_evento,
                r.titulo_evento,
                'recordatorio' AS tipo
            FROM recordatorios r
            WHERE r.telefono = ? 
              AND r.estado = 'pendiente' 
              AND r.fecha_evento BETWEEN ? AND ?
              AND NOT EXISTS (
                  SELECT 1 FROM saas_turnos st
                  JOIN saas_clientes sc ON st.cliente_id = sc.id
                  WHERE sc.telefono = r.telefono 
                    AND st.fecha_hora_inicio = r.fecha_evento
                    AND st.estado != 'cancelado'
              )

            ORDER BY fecha_evento ASC
        ");

        $stmtAgenda->execute([
            $numero_cliente, $fechaDesde, $fechaHasta,
            $numero_cliente, $fechaDesde, $fechaHasta
        ]);
        $resTurnos = $stmtAgenda->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($resTurnos)) {
            $mensajeFinal = "📅 *TUS PRÓXIMOS TURNOS Y EVENTOS AGENDADOS:*\n";
            foreach ($resTurnos as $index => $row) {
                $num = $index + 1;
                $fechaFmt = date('d/m/Y H:i', strtotime($row['fecha_evento']));
                $icono = ($row['tipo'] === 'turno') ? '🔹' : '📌';
                
                $mensajeFinal .= "\n{$num}. {$icono} *{$row['titulo_evento']}*\n   📆 {$fechaFmt} hs\n";
            }
        } else {
            $mensajeFinal = "📅 No tenés turnos ni eventos agendados para ese período.";
        }

    } catch (PDOException $e) {
        logClientes("Error SQL al consultar agenda: " . $e->getMessage());
        $mensajeFinal = "⚠️ Ocurrió un error al obtener tus turnos agendados.";
    }

    $datosSesion['historial'][] = ['role' => 'assistant', 'content' => $mensajeFinal];
    $stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET datos_json = ? WHERE telefono = ?");
    $stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);

    $payloadWa = [
        'messaging_product' => 'whatsapp',
        'to' => $numero_cliente,
        'type' => 'text',
        'text' => ['body' => $mensajeFinal]
    ];

    logClientes("Respuesta de consulta de agenda enviada a WhatsApp");
    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success']);
    exit;
}

// -----------------------------------------------------------------------------
// CASO E: SOLICITUD GENÉRICA DE CANCELACIÓN (SELECCIONAR DE LISTA)
// -----------------------------------------------------------------------------
if ($accion === 'SELECCIONAR_TURNO_A_CANCELAR') {
    logClientes("Ingresando a SELECCIONAR_TURNO_A_CANCELAR : ".$numero_cliente);
    try {
        $stmtTurnos = $pdo->prepare("
            SELECT 
                t.id AS turno_id,
                t.fecha_hora_inicio AS fecha_evento,
                s.nombre AS servicio_nombre,
                n.nombre AS negocio_nombre,
                CONCAT('Turno de ', s.nombre, ' en ', n.nombre) AS titulo_evento,
                r.id AS recordatorio_id
            FROM saas_turnos t
            JOIN saas_clientes c ON t.cliente_id = c.id
            JOIN saas_servicios s ON t.servicio_id = s.id
            JOIN saas_negocios n ON t.negocio_id = n.id
            LEFT JOIN recordatorios r ON (r.telefono = c.telefono AND r.fecha_evento = t.fecha_hora_inicio AND r.estado = 'pendiente')
            WHERE c.telefono = ? 
              AND t.estado != 'cancelado' 
              AND t.fecha_hora_inicio >= NOW()

            UNION ALL

            SELECT 
                NULL AS turno_id,
                fecha_evento,
                'Recordatorio' AS servicio_nombre,
                'Personal' AS negocio_nombre,
                titulo_evento,
                id AS recordatorio_id
            FROM recordatorios
            WHERE telefono = ? 
              AND estado = 'pendiente' 
              AND fecha_evento >= NOW()
              AND NOT EXISTS (
                  SELECT 1 FROM saas_turnos st
                  JOIN saas_clientes sc ON st.cliente_id = sc.id
                  WHERE sc.telefono = recordatorios.telefono 
                    AND st.fecha_hora_inicio = recordatorios.fecha_evento
                    AND st.estado != 'cancelado'
              )

            ORDER BY fecha_evento ASC
        ");
        $stmtTurnos->execute([$numero_cliente, $numero_cliente]);
        $turnosPendientes = $stmtTurnos->fetchAll(PDO::FETCH_ASSOC);

        if (empty($turnosPendientes)) {
            $mensajeFinal = "⚠️ No tenés turnos o recordatorios activos para cancelar en este momento.";
        } elseif (count($turnosPendientes) === 1) {
            $turno = $turnosPendientes[0];

            if (!empty($turno['turno_id'])) {
                $stmtCancelTurno = $pdo->prepare("UPDATE saas_turnos SET estado = 'cancelado' WHERE id = ?");
                $stmtCancelTurno->execute([$turno['turno_id']]);
            }

            if (!empty($turno['recordatorio_id'])) {
                $stmtCancelRec = $pdo->prepare("UPDATE recordatorios SET estado = 'cancelado' WHERE id = ?");
                $stmtCancelRec->execute([$turno['recordatorio_id']]);
            }

            $fechaFmt = date('d/m/Y H:i', strtotime($turno['fecha_evento']));
            $mensajeFinal = "❌ *Recordatorio/Turno Cancelado con Éxito*\n\n" .
                            "📌 *{$turno['titulo_evento']}*\n" .
                            "📆 Fecha: {$fechaFmt} hs\n\n" .
                            "El elemento ha sido liberado de tu agenda.";
        } else {
            $mensajeFinal = "📅 *Tenés varios eventos agendados. ¿Cuál te gustaría cancelar?*\n\n";
            $opcionesCancelacion = [];
            
            foreach ($turnosPendientes as $index => $t) {
                $numeroOpcion = $index + 1;
                $fechaFmt = date('d/m/Y H:i', strtotime($t['fecha_evento']));
                
                $mensajeFinal .= "*{$numeroOpcion}* - 🔹 *{$t['titulo_evento']}*\n   📆 {$fechaFmt} hs\n\n";
            
                $opcionesCancelacion[$numeroOpcion] = [
                    'turno_id'        => $t['turno_id'],
                    'recordatorio_id' => $t['recordatorio_id'],
                    'fecha_evento'    => $t['fecha_evento'],
                    'titulo_evento'   => $t['titulo_evento']
                ];
            }
            
            $mensajeFinal .= "Por favor, indicame el número del elemento que querés anular.";
            
            $datosSesion['opciones_cancelacion'] = $opcionesCancelacion;
        }

    } catch (PDOException $e) {
        logClientes("Error SQL al procesar lista de cancelación: " . $e->getMessage());
        $mensajeFinal = "⚠️ Ocurrió un inconveniente al consultar o cancelar tus turnos. Por favor, intentalo de nuevo en unos minutos.";
    }

    $datosSesion['historial'][] = ['role' => 'assistant', 'content' => $mensajeFinal];
    $stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET datos_json = ? WHERE telefono = ?");
    $stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);

    $payloadWa = [
        'messaging_product' => 'whatsapp',
        'to' => $numero_cliente,
        'type' => 'text',
        'text' => ['body' => $mensajeFinal]
    ];

    enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
    echo json_encode(['status' => 'success']);
    exit;
}

// -----------------------------------------------------------------------------
// CASO D: CANCELAR TURNO/RECORDATORIO ESPECÍFICO
// -----------------------------------------------------------------------------
if ($accion === 'CANCELAR_TURNO' || $accion === 'CANCELAR_RECORDATORIO') {
    logClientes("Ingresando al bloque de CANCELACIÓN directa.");

    try {   
        $busqueda = trim($datosIA['busqueda'] ?? '');
        $opcionesGuardadas = $datosSesion['opciones_cancelacion'] ?? [];
        $turnoACancelar = null;

        logClientes("Texto original del cliente: " . $texto_mensaje);
        logClientes("Busqueda sugerida por IA: " . $busqueda);

        $numeroSeleccionado = null;

        if (!empty($opcionesGuardadas)) {
            preg_match('/\b\d+\b/', $texto_mensaje, $coincidenciasTexto);
            if (isset($coincidenciasTexto[0]) && isset($opcionesGuardadas[(int)$coincidenciasTexto[0]])) {
                $numeroSeleccionado = (int)$coincidenciasTexto[0];
            }
        }

        if ($numeroSeleccionado === null && !empty($opcionesGuardadas)) {
            preg_match('/\b\d+\b/', $busqueda, $coincidenciasIA);
            if (isset($coincidenciasIA[0]) && isset($opcionesGuardadas[(int)$coincidenciasIA[0]])) {
                $numeroSeleccionado = (int)$coincidenciasIA[0];
            }
        }

        logClientes("Numero de opción seleccionada para cancelar: " . ($numeroSeleccionado ?? 'Ninguno'));
        
        if ($numeroSeleccionado !== null && isset($opcionesGuardadas[$numeroSeleccionado])) {
            $turnoACancelar = $opcionesGuardadas[$numeroSeleccionado];
        } 
        else {
            if (empty($busqueda) || in_array(strtolower($busqueda), ['mensaje', 'confirmacion', 'mensaje confirmando', 'recordatorio', 'turno', 'un turno'])) {
                $busquedaLimpia = preg_replace('/(necesito|quiero|quitar|cancelar|borrar|anular|el|la|los|las|de|del|recordatorio|un|turno)/i', '', $texto_mensaje);
                $busqueda = trim($busquedaLimpia);
                logClientes("Búsqueda ajustada por fallback de texto: " . $busqueda);
            }

            if (empty($busqueda)) {
                logClientes("Búsqueda vacía tras limpieza. Redirigiendo a selección de lista.");
                $datosSesion['historial'][] = ['role' => 'user', 'content' => $texto_mensaje];
                $stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET datos_json = ? WHERE telefono = ?");
                $stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);

                $accion = 'SELECCIONAR_TURNO_A_CANCELAR';
            } else {
                $busquedaEscapada = addcslashes($busqueda, '%_');
                $param = "%{$busquedaEscapada}%";

                $stmtSearch = $pdo->prepare("
                    SELECT 
                        t.id AS turno_id,
                        t.fecha_hora_inicio AS fecha_evento,
                        CONCAT('Turno de ', s.nombre, ' en ', n.nombre) AS titulo_evento,
                        r.id AS recordatorio_id
                    FROM saas_turnos t
                    JOIN saas_clientes c ON t.cliente_id = c.id
                    JOIN saas_servicios s ON t.servicio_id = s.id
                    JOIN saas_negocios n ON t.negocio_id = n.id
                    LEFT JOIN recordatorios r ON (r.telefono = c.telefono AND r.fecha_evento = t.fecha_hora_inicio AND r.estado = 'pendiente')
                    WHERE c.telefono = ? 
                      AND t.estado != 'cancelado' 
                      AND (s.nombre LIKE ? OR n.nombre LIKE ? OR t.fecha_hora_inicio LIKE ?)
                    ORDER BY t.fecha_hora_inicio ASC 
                    LIMIT 1
                ");
                $stmtSearch->execute([$numero_cliente, $param, $param, $param]);
                $turnoACancelar = $stmtSearch->fetch(PDO::FETCH_ASSOC);

                if (!$turnoACancelar) {
                    logClientes("Buscando directamente en la tabla recordatorios para el teléfono: {$numero_cliente} con param: {$param}");
                    $stmtSearchRec = $pdo->prepare("
                        SELECT 
                            NULL AS turno_id,
                            fecha_evento,
                            titulo_evento,
                            id AS recordatorio_id
                        FROM recordatorios
                        WHERE telefono = ? 
                          AND estado = 'pendiente'
                          AND titulo_evento LIKE ?
                        ORDER BY fecha_evento ASC
                        LIMIT 1
                    ");
                    $stmtSearchRec->execute([$numero_cliente, $param]);
                    $turnoACancelar = $stmtSearchRec->fetch(PDO::FETCH_ASSOC);
                }
            }
        }

        if ($accion === 'CANCELAR_TURNO' || $accion === 'CANCELAR_RECORDATORIO') {
            unset($datosSesion['opciones_cancelacion']);

            if ($turnoACancelar) {
                logClientes("Evento/Turno encontrado para cancelar: ID Recordatorio: " . ($turnoACancelar['recordatorio_id'] ?? 'N/A'));

                if (!empty($turnoACancelar['turno_id'])) {
				    // Cancelar usando la función unificada del helper
				    cancelarTurnoCompleto($pdo, $turnoACancelar['turno_id'], $negocio_id_activo);
				}

                if (!empty($turnoACancelar['recordatorio_id'])) {
                    $stmtUpdRec = $pdo->prepare("UPDATE recordatorios SET estado = 'cancelado' WHERE id = ?");
                    $stmtUpdRec->execute([$turnoACancelar['recordatorio_id']]);
                } else {
                    $stmtUpdRec = $pdo->prepare("UPDATE recordatorios SET estado = 'cancelado' WHERE telefono = ? AND fecha_evento = ?");
                    $stmtUpdRec->execute([$numero_cliente, $turnoACancelar['fecha_evento']]);
                }

                $fechaFmt = date('d/m/Y H:i', strtotime($turnoACancelar['fecha_evento']));
                $mensajeFinal = "❌ *Recordatorio/Turno Cancelado con Éxito*\n\n" .
                                "📌 *{$turnoACancelar['titulo_evento']}*\n" .
                                "📆 Fecha: {$fechaFmt} hs\n\n" .
                                "El elemento ha sido removido de tu agenda.";
            } else {
                logClientes("No se encontraron coincidencias para cancelar.");
                $mensajeFinal = "⚠️ No encontré ningún turno o recordatorio activo que coincida con \"{$busqueda}\".";
            }

            $datosSesion['historial'][] = ['role' => 'assistant', 'content' => $mensajeFinal];
            $stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET datos_json = ? WHERE telefono = ?");
            $stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);

            $payloadWa = [
                'messaging_product' => 'whatsapp',
                'to' => $numero_cliente,
                'type' => 'text',
                'text' => ['body' => $mensajeFinal]
            ];

            enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
            echo json_encode(['status' => 'success']);
            exit;
        }

    } catch (PDOException $e) {
        logClientes("Error SQL al procesar cancelación: " . $e->getMessage());
        $mensajeFinal = "⚠️ Ocurrió un inconveniente al consultar o cancelar tus turnos. Por favor, intentalo de nuevo en unos minutos.";
    }
}

// CASO F: RESPUESTA GENÉRICA / LIBRE
logClientes("Ingresando a CASO F (Respuesta genérica)");
$respuestaTexto = $datosIA['confirmacion'] ?? $datosIA['respuesta'] ?? $datosIA['message'] ?? null;

$placeholders = ['mensaje', 'mensaje confirmando', 'confirmacion', 'respuesta'];
if (!$respuestaTexto || in_array(strtolower(trim($respuestaTexto)), $placeholders)) {
    $respuestaTexto = "No pude interpretar bien la consulta o no encontré ese evento en tu agenda. Si querés cancelar algo, decime el nombre exacto o pedime 'mis reservas' para ver la lista.";
}

$datosSesion['historial'][] = ['role' => 'assistant', 'content' => $respuestaTexto];
$stmtUpdSesion = $pdo->prepare("UPDATE saas_clientes_sesiones SET datos_json = ? WHERE telefono = ?");
$stmtUpdSesion->execute([json_encode($datosSesion), $numero_cliente]);

$payloadWa = [
    'messaging_product' => 'whatsapp',
    'to' => $numero_cliente,
    'type' => 'text',
    'text' => ['body' => $respuestaTexto]
];

logClientes("Respuesta genérica final enviada a WhatsApp: " . $respuestaTexto);
enviarWhatsAppGeneral($numero_cliente, $payloadWa, $phoneNumberId);
echo json_encode(['status' => 'success']);