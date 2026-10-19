<?php
// webhook.php - Router Central Exclusivo con Procesamiento de Audio
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');

ini_set('display_errors', 1);
error_reporting(E_ALL);

// -----------------------------------------------------------------------------
// 1. VERIFICACIÓN DEL WEBHOOK DE META (MÉTODO GET)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && $token === VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        exit;
    } else {
        http_response_code(403);
        echo "Error de verificación: token inválido.";
        exit;
    }
}

file_put_contents(__DIR__ . '/log.txt', "[" . date('Y-m-d H:i:s') . "] Comenzó tratamiento de mensaje en Router Central.\n", FILE_APPEND);

// -----------------------------------------------------------------------------
// 2. RECEPCIÓN Y NORMALIZACIÓN DEL MENSAJE (MÉTODO POST)
// -----------------------------------------------------------------------------
$input = file_get_contents('php://input');
$data = json_decode($input, true);

http_response_code(200);

if (!isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
    exit;
}

$messageData   = &$data['entry'][0]['changes'][0]['value']['messages'][0];
$phoneNumberId = $data['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'];

function normalizarTelefonoArgentina($numero) {
    //$numero = preg_replace('/[^0-9]/', '', $numero);
    //if (substr($numero, 0, 3) === '549') {
    //    $numero = '54' . substr($numero, 3);
    //}
    return $numero;
}

$from = normalizarTelefonoArgentina($messageData['from']);
$type = $messageData['type'];

// Registrar / Actualizar usuario en BD
$nombreWhatsApp = $data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? 'Usuario WhatsApp';
try {
    $sqlUser = "INSERT INTO usuarios (telefono, nombre) VALUES (:telefono, :nombre) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)";
    $stmtUser = $pdo->prepare($sqlUser);
    $stmtUser->execute([':telefono' => $from, ':nombre' => $nombreWhatsApp]);
} catch (PDOException $e) {
    error_log("Error al registrar usuario: " . $e->getMessage());
}

// -----------------------------------------------------------------------------
// 2.1. FILTRO DE TIPOS PERMITIDOS (SOLO TEXTO Y AUDIO)
// -----------------------------------------------------------------------------
// Si el mensaje es una reacción a un mensaje previo, finalizar la ejecución silenciosamente
if ($type === 'reaction') {
    file_put_contents(__DIR__ . '/log.txt', "[" . date('Y-m-d H:i:s') . "] Reacción recibida de {$from}. Ignorada correctamente.\n", FILE_APPEND);
    exit;
}

if ($type !== 'text' && $type !== 'audio' && $type !== 'interactive' && $type !== 'location' && $type !== 'image' && $type !== 'document') {
    file_put_contents(__DIR__ . '/log.txt', "[" . date('Y-m-d H:i:s') . "] Tipo de mensaje no soportado ('{$type}') recibido de {$from}.\n", FILE_APPEND);
    enviarRespuestaWhatsApp($from, $phoneNumberId, "⚠️ *Tipo de mensaje no permitido*: Por el momento solo procesamos mensajes de texto o notas de voz. Por favor, vuelve a intentarlo por alguno de esos medios.");
    exit;
}

// -----------------------------------------------------------------------------
// 3. PROCESAMIENTO DE AUDIO (CONVERSIÓN A TEXTO VÍA WHISPER)
// -----------------------------------------------------------------------------
if ($type === 'audio') {
    $mediaId = $messageData['audio']['id'] ?? null;
    if ($mediaId) {
        // 1. Obtener URL de descarga desde la API de Meta
        $ch = curl_init("https://graph.facebook.com/v20.0/{$mediaId}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . META_ACCESS_TOKEN]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $mediaResponse = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($mediaResponse['url'])) {
            $tempAudioPath = sys_get_temp_dir() . "/audio_{$mediaId}.ogg";
            $fp = fopen($tempAudioPath, 'w+');

            // 2. Descargar el archivo binario del audio
            $ch = curl_init($mediaResponse['url']);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . META_ACCESS_TOKEN,
                "User-Agent: PHP-Script"
            ]);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);

            // 3. Enviar a OpenAI Whisper indicando idioma español
            if (file_exists($tempAudioPath) && filesize($tempAudioPath) > 0) {
                $cFile = new CURLFile($tempAudioPath, 'audio/ogg', 'audio.ogg');
                $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . OPENAI_API_KEY]);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    'file' => $cFile,
                    'model' => 'whisper-1',
                    'language' => 'es'
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $whisperResult = json_decode(curl_exec($ch), true);
                curl_close($ch);

                // Borrar archivo temporal
                unlink($tempAudioPath);

                $transcripcion = trim($whisperResult['text'] ?? '');

                if (!empty($transcripcion)) {
                    file_put_contents(__DIR__ . '/log.txt', "[" . date('Y-m-d H:i:s') . "] Audio transcrito: \"{$transcripcion}\"\n", FILE_APPEND);

                    // Transformar la estructura de 'audio' a 'text' para los scripts secundarios
                    $messageData['type'] = 'text';
                    $messageData['text'] = ['body' => $transcripcion];
                    $type = 'text';

                    // Actualizar el payload codificado $input para el reenvío
                    $input = json_encode($data);
                } else {
                    file_put_contents(__DIR__ . '/log.txt', "[" . date('Y-m-d H:i:s') . "] Error o transcripción vacía de Whisper.\n", FILE_APPEND);
                }
            }
        }
    }
}

// -----------------------------------------------------------------------------
// 4. CONMUTADOR DE ROL (DUEÑO DE NEGOCIO EN MODO NEGOCIO VS CLIENTE/PERSONA)
// -----------------------------------------------------------------------------
$textoIngresado = "";
$interactiveId = "";

if ($type === 'text') {
    $textoIngresado = trim($messageData['text']['body'] ?? '');
} elseif ($type === 'interactive') {
    // Si respondió desde un menú desplegable (list_reply) o botón (button_reply)
    if (isset($messageData['interactive']['list_reply'])) {
        $interactiveId = $messageData['interactive']['list_reply']['id'] ?? '';
        $textoIngresado = $messageData['interactive']['list_reply']['title'] ?? '';
    } elseif (isset($messageData['interactive']['button_reply'])) {
        $interactiveId = $messageData['interactive']['button_reply']['id'] ?? '';
        $textoIngresado = $messageData['interactive']['button_reply']['title'] ?? '';
    }
}

$textBodyLower = strtolower($textoIngresado);

try {
    $stmtNegocio = $pdo->prepare("SELECT id, nombre FROM saas_negocios WHERE telefono_dueno = ? LIMIT 1");
    $stmtNegocio->execute([$from]);
    $negocio = $stmtNegocio->fetch(PDO::FETCH_ASSOC);

    if ($negocio) {
        $nombreNegocio = $negocio['nombre'] ?? 'Tu Negocio';

        // Evaluar si presionó una opción interactiva o escribió el comando
        if ($interactiveId === 'btn_modo_negocio' || $textBodyLower === 'modo negocio') {
            setModoActivo($pdo, $from, 'negocio');
            enviarRespuestaWhatsApp($from, $phoneNumberId, "🏢 *Modo Negocio Activado*\nA partir de ahora tus mensajes gestionarán tu agenda y clientes.");
            redirigirAWebhook('webhook_negocios.php', $input);
            exit;
        } elseif ($interactiveId === 'btn_modo_persona' || $textBodyLower === 'modo persona') {
            setModoActivo($pdo, $from, 'persona');
            enviarRespuestaWhatsApp($from, $phoneNumberId, "👤 *Modo Persona Activado*\nA partir de ahora interactúas como usuario personal.");
            exit;
        } elseif ($textBodyLower === 'cambiar modo' || $textBodyLower === 'menu modo' || $textBodyLower === 'modo') {
            mostrarMenuSeleccion($from, $phoneNumberId, $nombreNegocio);
            exit;
        }

        $stmtEstado = $pdo->prepare("SELECT modo_activo FROM saas_usuario_estados WHERE telefono = ?");
        $stmtEstado->execute([$from]);
        $modoActivo = $stmtEstado->fetchColumn();

        if (!$modoActivo) {
            mostrarMenuSeleccion($from, $phoneNumberId, $nombreNegocio);
            exit;
        }

        if ($modoActivo === 'negocio') {
            file_put_contents(__DIR__ . '/log.txt', "[" . date('Y-m-d H:i:s') . "] [ROUTER] Redirigiendo a webhook_negocios.php para {$from}\n", FILE_APPEND);
            redirigirAWebhook('webhook_negocios.php', $input);
            exit;
        }
    }
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/log.txt', "[" . date('Y-m-d H:i:s') . "] [ROUTER ERROR] " . $e->getMessage() . "\n", FILE_APPEND);
}

// -----------------------------------------------------------------------------
// 5. DELEGACIÓN GENERAL A WEBHOOK_CLIENTES.PHP
// -----------------------------------------------------------------------------
file_put_contents(__DIR__ . '/log.txt', "[" . date('Y-m-d H:i:s') . "] [ROUTER] Redirigiendo a webhook_clientes.php para {$from}\n", FILE_APPEND);
redirigirAWebhook('webhook_clientes.php', $input);
exit;

// -----------------------------------------------------------------------------
// FUNCIONES AUXILIARES DE ENRUTAMIENTO Y MENSAJERÍA
// -----------------------------------------------------------------------------

function mostrarMenuSeleccion($to, $phoneNumberId, $nombreNegocio) {
    $payloadWa = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'interactive',
        'interactive'       => [
            'type'   => 'list',
            'header' => [
                'type' => 'text',
                'text' => 'Selección de Perfil'
            ],
            'body'   => [
                'text' => "👋 ¡Hola! Detectamos que tu número pertenece al negocio: *{$nombreNegocio}*.\n\n¿Cómo deseas interactuar hoy?"
            ],
            'footer' => [
                'text' => 'Puedes escribir "cambiar modo" luego para alternar.'
            ],
            'action' => [
                'button' => 'Seleccionar Modo',
                'sections' => [
                    [
                        'title' => 'Opciones de Perfil',
                        'rows'  => [
                            [
                                'id'          => 'btn_modo_negocio',
                                'title'       => '🏢 Modo Negocio',
                                'description' => 'Gestionar turnos, agenda y clientes.'
                            ],
                            [
                                'id'          => 'btn_modo_persona',
                                'title'       => '👤 Modo Persona',
                                'description' => 'Agendar turnos o usar recordatorios.'
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . META_ACCESS_TOKEN, "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadWa));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function setModoActivo($pdo, $telefono, $modo) {
    $stmt = $pdo->prepare("
        INSERT INTO saas_usuario_estados (telefono, modo_activo) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE modo_activo = VALUES(modo_activo)
    ");
    $stmt->execute([$telefono, $modo]);
}

function redirigirAWebhook($archivoDestino, $input) {
    $ch = curl_init("https://tusecretarioia.lovar.com.ar/secretarioia/{$archivoDestino}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
    curl_close($ch);
}

function enviarRespuestaWhatsApp($to, $phoneNumberId, $text) {
    $payloadWa = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $text]
    ];

    $ch = curl_init("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . META_ACCESS_TOKEN, "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadWa));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}