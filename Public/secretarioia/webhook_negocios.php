<?php
// webhook_negocios.php
header('Content-Type: application/json');

// Configuración de Zona Horaria de Argentina
date_default_timezone_set('America/Argentina/Cordoba');

// -----------------------------------------------------------------------------
// 1. CONFIGURACIÓN Y CONEXIÓN
// -----------------------------------------------------------------------------
if (file_exists(__DIR__ . '/conexion.php')) {
    require_once __DIR__ . '/conexion.php';
} else {
    require_once __DIR__ . '/db.php';
}

require_once __DIR__ . '/../funciones_whatsapp.php';

if (isset($pdo)) {
    $pdo->exec("SET time_zone = '-03:00';");
}

if (!defined('META_ACCESS_TOKEN')) {
    define('META_ACCESS_TOKEN', 'EAAUJIfJBzp0BSdlljUGVo0bDbADhLC6I6ilZCVZBBtBqXmWMZBckitoaIfdlQKqXEZCIM7hRFESbifOTgFkF9CmiIwFB4rYxrTYhP2HTGpRZAuHbX8594DCgnE64PHjVxTEMYBGvsi8UvUtROzB1VioWurXTctZAeVg7xAxbDYLgdcoBSzFUCn5G4IZABNvtZAUZApQZDZD');
}

if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', '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');
}

function logNegocios($msg) {
    file_put_contents(__DIR__ . '/log.txt', "[" . date('Y-m-d H:i:s') . "] [NEGOCIOS] " . $msg . "\n", FILE_APPEND);
}

function enviarWhatsAppNegocios($pdo, $negocioId, $to, $texto) {
    $payloadWa = [
        'messaging_product' => 'whatsapp',
        'type' => 'text',
        'text' => ['body' => $texto]
    ];

    return ejecutarCurlWhatsAppInteligente($pdo, $negocioId, $payloadWa, $to, 'text');
}

// -----------------------------------------------------------------------------
// 2. RECEPCIÓN Y PARSEO DEL MENSAJE DE META
// -----------------------------------------------------------------------------
$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);

$messageData   = $input['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
$phoneNumberId = $input['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;

if (!$messageData) {
    logNegocios("Payload recibido sin estructura de mensaje válida.");
    echo json_encode(['status' => 'ignored']);
    exit;
}

function normalizarTelefono($numero) {
 //   $numero = preg_replace('/[^0-9]/', '', $numero);
 //   if (substr($numero, 0, 3) === '549') {
 //       $numero = '54' . substr($numero, 3);
 //   }
    return $numero;
}

$numero_emisor = normalizarTelefono($messageData['from'] ?? '');
$tipo_mensaje  = $messageData['type'] ?? '';
$texto_mensaje = '';

if ($tipo_mensaje === 'text') {
    $texto_mensaje = $messageData['text']['body'] ?? '';
}

logNegocios("Mensaje de: {$numero_emisor} | Texto: {$texto_mensaje}");

// Buscar negocio asociado
$stmt = $pdo->prepare("SELECT id, nombre, paso_onboarding FROM saas_negocios WHERE telefono_dueno = ?");
$stmt->execute([$numero_emisor]);
$negocio = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$negocio) {
    logNegocios("No se encontró negocio asociado al teléfono: {$numero_emisor}");
    echo json_encode(['status' => 'negocio_not_found']);
    exit;
}

$pasoActual = $negocio['paso_onboarding'] ?? 'INICIO';

// -----------------------------------------------------------------------------
// 2.B. INTERCEPCIÓN PREVIA DE CONSULTAS DE AYUDA / INSTRUCTIVO (NEGOCIOS)
// -----------------------------------------------------------------------------
$texto_minuscula = mb_strtolower(trim($texto_mensaje));

if (preg_match('/(ayuda|como funciona|cómo funciona|como hago|cómo hago|comandos|instructivo|instrucciones|me ayudas|me ayudás)/i', $texto_minuscula)) {
    logNegocios("Interceptada consulta de ayuda para negocio.");

    if ($pasoActual !== 'COMPLETADO') {
        $respuestaAyuda = "⚙️ *Configuración Pendiente*\n\n"
            . "Hola *{$negocio['nombre']}*. Tu cuenta está en estado: *{$pasoActual}*.\n\n"
            . "👉 Para comenzar a recibir turnos, primero debés completar tu perfil indicándome:\n"
            . "1️⃣ *Tus servicios y precios* (Ej: *Corte $8000, Barba $5000*)\n"
            . "2️⃣ *Tus días y horarios de atención* (Ej: *Lunes a Viernes de 9 a 18 hs con turnos de 30 min*)\n\n"
            . "Escribime tus servicios o datos para avanzar.";
    } else {
        $respuestaAyuda = "💼 *Panel de Control - Secretario IA*\n\n"
            . "Como dueño de *{$negocio['nombre']}*, podés realizar las siguientes acciones escribiéndome de forma natural:\n\n"
            . "📅 *Consultar Turnos:* \"¿Qué turnos tengo hoy?\" o \"Turnos del viernes\"\n"
            . "📌 *Agendar Recordatorios:* \"Recordame ir al súper mañana a las 18hs comprar leche y pan\"\n"
            . "🚫 *Bloquear Fechas:* \"Bloquear mañana de 13 a 15 por almuerzo\" o \"Bloquear el 25/12 completo\"\n"
            . "🔓 *Desbloquear Agenda:* \"Desbloquear agenda para el 25/12\"\n"
            . "💈 *Agregar Servicios:* \"Agregar servicio Masaje $12000 45 min\"\n"
            . "⏰ *Modificar Horarios:* \"Atendemos de Lunes a Sábado de 8 a 16 hs\"\n\n"
            . "¿Qué te gustaría hacer ahora?";
    }

    if ($phoneNumberId) {
        enviarWhatsAppNegocios($pdo, $$negocio['id'],$numero_emisor, $respuestaAyuda);
    }
    echo json_encode(['status' => 'success', 'reason' => 'help_handled']);
    exit;
}

// -----------------------------------------------------------------------------
// 3. PROCESAMIENTO CON GPT-4o-mini BASADO EN MÁQUINA DE ESTADOS
// -----------------------------------------------------------------------------
$fechaHoy   = date('Y-m-d');
$horaActual = date('H:i:s');
$diaSemana  = date('l');

$promptSistema = "Sos el asistente de agenda para el negocio '" . $negocio['nombre'] . "'.
La fecha de HOY es {$fechaHoy} a las {$horaActual} ({$diaSemana}).

ESTADO ACTUAL DEL ONBOARDING DEL NEGOCIO: '{$pasoActual}'

REGLAS GENERALES:
- NUNCA repitas en tus respuestas muletillas como 'Tu configuración inicial está completa' o 'Tu configuración está completada' de forma automática.
- Sé claro, directo y profesional.

REGLA DE SEGURIDAD PRIORITARIA:
- Si paso_onboarding NO ES 'COMPLETADO' y el comerciante intenta consultar turnos ('CONSULTAR_TURNOS'), bloquear agenda ('BLOQUEAR_FECHA') o desbloquear agenda ('DESBLOQUEAR_FECHA'), NO ejecutes la acción. Devolvé la acción 'RESPONDER_MENSAJE' e informale amablemente que primero debe completar la configuración inicial enviando la información pendiente ({$pasoActual}).

REGLAS DE MÁQUINA DE ESTADOS Y ACCIONES:
1. Si paso_onboarding es 'INICIO' o el comerciante saluda por primera vez: Guialo amablemente para que registre sus servicios. Devolvé accion: 'INICIAR_ONBOARDING'.
2. Si paso_onboarding es 'ESPERANDO_SERVICIOS': Extraé los servicios ingresados. Devolvé accion: 'GUARDAR_SERVICIOS'.
3. Si paso_onboarding es 'ESPERANDO_HORARIOS' o 'ESPERANDO_DURACION': Extraé los días y horarios o la duración del turno. Devolvé accion: 'CONFIGURAR_HORARIO'.
4. Si el comerciante pide agendar un recordatorio personal, tarea o evento propio (ej: 'agendar ida al súper', 'recordame comprar leche', 'agendar médico mañana 10am', etc.): Devolvé accion: 'GUARDAR_RECORDATORIO'.
   - Extraé el 'titulo_evento', 'fecha_evento' (YYYY-MM-DD HH:MM:SS), 'minutos_anticipacion' (por defecto 60), 'fecha_hora_programada' (fecha_evento - minutos_anticipacion), e 'items' (array con la lista de productos/ítems o detalles a guardar en el recordatorio si aplica).
5. Si paso_onboarding es 'COMPLETADO': El comerciante puede consultar turnos ('CONSULTAR_TURNOS'), modificar horarios ('CONFIGURAR_HORARIO'), agregar servicios ('GUARDAR_SERVICIOS'), bloquear fechas ('BLOQUEAR_FECHA') o desbloquear fechas ('DESBLOQUEAR_FECHA').

FORMATO DE RESPUESTA JSON ESTRICTO:

CASO A: Iniciar Onboarding
{
  \"accion\": \"INICIAR_ONBOARDING\",
  \"confirmacion\": \"👋 ¡Hola! Bienvenido a tu Asistente de Agenda.\n\nPara activar tu panel, solo necesitamos 2 pasos simples:\n\n👉 *Paso 1:* Envíame la lista de servicios que ofrecés con sus precios (Ejemplo: *Corte $8000, Barba $5000*).\"
}

CASO B: Cargar Servicios
{
  \"accion\": \"GUARDAR_SERVICIOS\",
  \"servicios\": [
    {\"nombre\": \"Corte de pelo\", \"precio\": 8000, \"duracion_min\": 30}
  ],
  \"confirmacion\": \"¡Excelente! Guardé tus servicios.\n\n👉 *Paso 2:* Ahora indicame qué días y horarios vas a atender y cuánto dura cada turno (Ejemplo: *Lunes a Viernes de 9 a 18 con turnos de 30 min*).\"
}

CASO C: Cargar / Modificar Días y Horarios Habituales
{
  \"accion\": \"CONFIGURAR_HORARIO\",
  \"dias_laborales\": \"1,2,3,4,5\",
  \"hora_apertura\": \"09:00:00\",
  \"hora_cierre\": \"18:00:00\",
  \"duracion_turno_min\": 30,
  \"confirmacion\": \"¡Perfecto! Configuración completada. Tu agenda ya está lista para recibir reservas.\"
}

CASO D: Agendar Recordatorio / Evento Personal
{
  \"accion\": \"GUARDAR_RECORDATORIO\",
  \"titulo_evento\": \"Ir al súper\",
  \"fecha_evento\": \"YYYY-MM-DD HH:MM:SS\",
  \"minutos_anticipacion\": 60,
  \"fecha_hora_programada\": \"YYYY-MM-DD HH:MM:SS\",
  \"items\": [\"Leche\", \"Pan\", \"Huevos\"],
  \"confirmacion\": \"📌 ¡Recordatorio agendado con éxito para [Fecha Hora]!\"
}

CASO E: Bloquear Día u Horario
{
  \"accion\": \"BLOQUEAR_FECHA\",
  \"fecha\": \"YYYY-MM-DD\",
  \"hora_inicio\": \"00:00:00\",
  \"hora_fin\": \"23:59:59\",
  \"motivo\": \"Día bloqueado\",
  \"confirmacion\": \"¡Listo! Quedó bloqueado el día [Fecha] completo.\"
}

CASO F: Desbloquear Día
{
  \"accion\": \"DESBLOQUEAR_FECHA\",
  \"fecha\": \"YYYY-MM-DD\",
  \"confirmacion\": \"¡Entendido! Procedo a desbloquear la agenda para el día [Fecha].\"
}

CASO G: Consultar Turnos
{
  \"accion\": \"CONSULTAR_TURNOS\",
  \"fecha_filtro\": \"YYYY-MM-DD\"
}

CASO H: Responder Mensajes Generales
{
  \"accion\": \"RESPONDER_MENSAJE\",
  \"confirmacion\": \"[Escribí una respuesta acorde al estado '{$pasoActual}']\"
}

Devuelve ÚNICAMENTE el objeto JSON sin texto extra.";

$payloadGpt = [
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role' => 'system', 'content' => $promptSistema],
        ['role' => 'user', 'content' => $texto_mensaje]
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
$gptResponseRaw = curl_exec($ch);
curl_close($ch);

$gptResponse = json_decode($gptResponseRaw, true);
$datosIA     = json_decode($gptResponse['choices'][0]['message']['content'] ?? '{}', true);

$accion       = $datosIA['accion'] ?? 'RESPONDER_MENSAJE';
$confirmacion = $datosIA['confirmacion'] ?? "Entendido, procesé tu solicitud.";

logNegocios("IA parseó Acción: {$accion} | Estado previo: {$pasoActual}");

// -----------------------------------------------------------------------------
// 4. EJECUCIÓN SEGÚN LA ACCIÓN DETECTADA
// -----------------------------------------------------------------------------
try {
    if ($accion === 'INICIAR_ONBOARDING') {
        
        $stmtEstado = $pdo->prepare("UPDATE saas_negocios SET paso_onboarding = 'ESPERANDO_SERVICIOS' WHERE id = ?");
        $stmtEstado->execute([$negocio['id']]);

    } elseif ($accion === 'GUARDAR_SERVICIOS') {

        $servicios = $datosIA['servicios'] ?? [];
        $stmtServicio = $pdo->prepare("
            INSERT INTO saas_servicios (negocio_id, nombre, precio, duracion_minutos)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($servicios as $s) {
            $stmtServicio->execute([
                $negocio['id'],
                $s['nombre'],
                $s['precio'] ?? 0,
                $s['duracion_min'] ?? 30
            ]);
        }
        
        $stmtEstado = $pdo->prepare("UPDATE saas_negocios SET paso_onboarding = 'ESPERANDO_HORARIOS' WHERE id = ?");
        $stmtEstado->execute([$negocio['id']]);

    } elseif ($accion === 'CONFIGURAR_HORARIO') {
        
        $dias_string   = $datosIA['dias_laborales'] ?? '1,2,3,4,5';
        $hora_apertura = $datosIA['hora_apertura'] ?? '09:00:00';
        $hora_cierre   = $datosIA['hora_cierre'] ?? '18:00:00';
        $duracion_min  = (int)($datosIA['duracion_turno_min'] ?? 30);

        $stmtConfig = $pdo->prepare("
            INSERT INTO saas_configuracion_agenda (negocio_id, duracion_turno_min)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE duracion_turno_min = VALUES(duracion_turno_min)
        ");
        $stmtConfig->execute([$negocio['id'], $duracion_min]);

        $dias_array = array_map('intval', explode(',', $dias_string));

        $stmtHorario = $pdo->prepare("
            INSERT INTO saas_horarios_atencion (negocio_id, dia_semana, hora_apertura, hora_cierre, esta_activo)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                hora_apertura = VALUES(hora_apertura),
                hora_cierre = VALUES(hora_cierre),
                esta_activo = 1
        ");

        foreach ($dias_array as $dia) {
            $stmtHorario->execute([
                $negocio['id'],
                $dia,
                $hora_apertura,
                $hora_cierre
            ]);
        }

        $stmtEstado = $pdo->prepare("UPDATE saas_negocios SET paso_onboarding = 'COMPLETADO' WHERE id = ?");
        $stmtEstado->execute([$negocio['id']]);

    } elseif ($accion === 'GUARDAR_RECORDATORIO') {

        $tituloEvento        = $datosIA['titulo_evento'] ?? 'Recordatorio';
        $fechaEvento         = $datosIA['fecha_evento'] ?? date('Y-m-d H:i:s', strtotime('+1 hour'));
        $minutosAnticipacion = (int)($datosIA['minutos_anticipacion'] ?? 60);
        $fechaHoraProgramada = $datosIA['fecha_hora_programada'] ?? date('Y-m-d H:i:s', strtotime("{$fechaEvento} - {$minutosAnticipacion} minutes"));
        $confirmacionUsuario = 'si';
        $items               = $datosIA['items'] ?? [];

        // Guardar cabecera en recordatorios
        $stmtRec = $pdo->prepare("
            INSERT INTO recordatorios (telefono, titulo_evento, fecha_evento, minutos_anticipacion, fecha_hora_programada, estado, confirmacion_usuario) 
            VALUES (?, ?, ?, ?, ?, 'pendiente', ?)
        ");
        $stmtRec->execute([
            $numero_emisor, 
            $tituloEvento, 
            $fechaEvento, 
            $minutosAnticipacion, 
            $fechaHoraProgramada, 
            $confirmacionUsuario
        ]);
        $recordatorioId = $pdo->lastInsertId();

        // Guardar detalle de ítems si existen
        if ($recordatorioId && !empty($items) && is_array($items)) {
            $stmtDet = $pdo->prepare("
                INSERT INTO recordatorios_detalles (recordatorio_id, item, completado) 
                VALUES (?, ?, 0)
            ");
            foreach ($items as $item) {
                if (!empty(trim($item))) {
                    $stmtDet->execute([$recordatorioId, trim($item)]);
                }
            }
        }

        $fechaFmt = date('d/m/Y H:i', strtotime($fechaEvento));
        $confirmacion = "📌 *¡Recordatorio Agendado con Éxito!*\n\n" .
                        "📝 *Evento:* {$tituloEvento}\n" .
                        "📆 *Fecha y Hora:* {$fechaFmt} hs\n";

        if (!empty($items)) {
            $confirmacion .= "\n📋 *Detalles/Ítems:*\n";
            foreach ($items as $it) {
                $confirmacion .= "• {$it}\n";
            }
        }

    } elseif ($accion === 'BLOQUEAR_FECHA') {

        if ($pasoActual !== 'COMPLETADO') {
            $confirmacion = "⚠️ No podés bloquear fechas aún. Primero debés completar la configuración de tu negocio.";
        } else { 
            $fecha       = $datosIA['fecha'] ?? date('Y-m-d');
            $hora_inicio = $datosIA['hora_inicio'] ?? '00:00:00';
            $hora_fin    = $datosIA['hora_fin'] ?? '23:59:59';
            $motivo      = $datosIA['motivo'] ?? 'Bloqueado vía WhatsApp';

            $fecha_hora_inicio = "{$fecha} {$hora_inicio}";
            $fecha_hora_fin    = "{$fecha} {$hora_fin}";

            $stmtBloqueo = $pdo->prepare("
                INSERT INTO saas_bloqueos (negocio_id, fecha_hora_inicio, fecha_hora_fin, motivo)
                VALUES (?, ?, ?, ?)
            ");
            $stmtBloqueo->execute([
                $negocio['id'],
                $fecha_hora_inicio,
                $fecha_hora_fin,
                $motivo
            ]);
        }

    } elseif ($accion === 'DESBLOQUEAR_FECHA') {

        if ($pasoActual !== 'COMPLETADO') {
            $confirmacion = "⚠️ No podés modificar bloqueos aún. Primero debés completar la configuración de tu negocio.";
        } else {
            $fecha = $datosIA['fecha'] ?? date('Y-m-d');

            $stmtDesbloqueo = $pdo->prepare("
                DELETE FROM saas_bloqueos 
                WHERE negocio_id = ? 
                  AND DATE(fecha_hora_inicio) = ?
            ");
            $stmtDesbloqueo->execute([$negocio['id'], $fecha]);

            if ($stmtDesbloqueo->rowCount() > 0) {
                $confirmacion = "🔓 ¡Listo! Se desbloqueó la agenda para el día " . date('d/m/Y', strtotime($fecha)) . ".";
            } else {
                $confirmacion = "ℹ️ No se encontraron bloqueos registrados para la fecha " . date('d/m/Y', strtotime($fecha)) . ".";
            }
        }

    } elseif ($accion === 'CONSULTAR_TURNOS') {

        if ($pasoActual !== 'COMPLETADO') {
            $confirmacion = "⚠️ La agenda aún no está activa. Completá los pasos pendientes de configuración para comenzar a recibir y consultar turnos.";
        } else {
            $fechaFiltro = $datosIA['fecha_filtro'] ?? date('Y-m-d');
            $fechaFiltroInicio = "{$fechaFiltro} 00:00:00";

            $stmtTurnos = $pdo->prepare("
                SELECT 
                    t.fecha_hora_inicio, 
                    t.fecha_hora_fin, 
                    t.estado,
                    COALESCE(c.nombre, 'Cliente') AS cliente_nombre,
                    COALESCE(s.nombre, 'Servicio') AS servicio_nombre
                FROM saas_turnos t
                LEFT JOIN saas_clientes c ON t.cliente_id = c.id
                LEFT JOIN saas_servicios s ON t.servicio_id = s.id
                WHERE t.negocio_id = ? 
                  AND t.fecha_hora_inicio >= ? 
                  AND t.estado != 'cancelado'
                ORDER BY t.fecha_hora_inicio ASC
                LIMIT 10
            ");
            $stmtTurnos->execute([$negocio['id'], $fechaFiltroInicio]);
            $turnos = $stmtTurnos->fetchAll(PDO::FETCH_ASSOC);

            if (empty($turnos)) {
                $confirmacion = "📅 No tenés turnos agendados a partir del " . date('d/m/Y', strtotime($fechaFiltro)) . ".";
            } else {
                $confirmacion = "📅 *Tus próximos turnos agendados:*\n\n";
                foreach ($turnos as $t) {
                    $fechaHora = strtotime($t['fecha_hora_inicio']);
                    $fechaFormatted = date('d/m/Y', $fechaHora);
                    $horaFormatted  = date('H:i', $fechaHora);
                    
                    $confirmacion .= "• *{$fechaFormatted} {$horaFormatted} hs* - {$t['cliente_nombre']} ({$t['servicio_nombre']})\n";
                }
            }
        }
    }
} catch (Exception $e) {
    logNegocios("Error procesando acción en BD: " . $e->getMessage());
}

// -----------------------------------------------------------------------------
// 5. RESPONDER AL COMERCIANTE VÍA WHATSAPP
// -----------------------------------------------------------------------------
if ($phoneNumberId) {
    enviarWhatsAppNegocios($pdo, $$negocio['id'],$numero_emisor, $confirmacion);
}

echo json_encode(['status' => 'success']);