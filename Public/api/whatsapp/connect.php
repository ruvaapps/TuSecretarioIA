<?php
header('Content-Type: application/json');
session_start();

// 1. Verificar sesión del negocio
if (!isset($_SESSION['negocio_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sesión no válida o no iniciada.']);
    exit;
}

$negocio_id = (int)$_SESSION['negocio_id'];

// 2. Leer payload JSON enviado desde el frontend
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$code = $input['code'] ?? null;
$waba_id_front = $input['waba_id'] ?? null;
$phone_number_id_front = $input['phone_number_id'] ?? null;

if (!$code) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Código de autorización (code) no provisto.']);
    exit;
}

// 3. Configuración de Meta App (Definí estas constantes en tu config.php)
define('META_APP_ID', '1417416286523037');
define('META_APP_SECRET', '3c23c1b8ca6001aa4cdf4f1677145372');
define('GRAPH_API_VERSION', 'v20.0');

try {
    // ------------------------------------------------------------------
    // PASO 1: Intercambiar 'code' por User Access Token
    // ------------------------------------------------------------------
    //$redirect_uri = 'https://tusecretarioia.lovar.com.ar/Desarrollo/panel_negocio.php';

    // ------------------------------------------------------------------
        // PASO 1: Intercambiar 'code' por User Access Token
        // ------------------------------------------------------------------

        // Capturamos la URL exacta desde donde vino la petición del navegador
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $redirect_uri = strtok($referer, '?'); // Limpiamos parámetros GET si tuviera

        // REGISTRO DE LOG (Guarda los datos en un archivo de texto en el servidor)
        $log_data = date('Y-m-d H:i:s') . " - Code recibido: " . $code . " | Redirect URI enviado: " . $redirect_uri . "\n";
        file_put_contents(__DIR__ . '/oauth_debug.log', $log_data, FILE_APPEND);

        $token_url = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/oauth/access_token?" . http_build_query([
            'client_id'     => META_APP_ID,
            'client_secret' => META_APP_SECRET,
            'code'          => $code,
            'redirect_uri'  => $redirect_uri
        ]);


    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $token_data = json_decode($response, true);

    if (isset($token_data['error'])) {
        throw new Exception("Error obteniendo token de Meta: " . $token_data['error']['message']);
    }

    $access_token = $token_data['access_token'];

    // ------------------------------------------------------------------
    // PASO 2: Obtener WABA ID y Phone Number ID si no llegaron del frontend
    // ------------------------------------------------------------------
    $waba_id = $waba_id_front;
    $phone_number_id = $phone_number_id_front;

    if (!$waba_id || !$phone_number_id) {
        $me_url = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/me/whatsapp_business_accounts?access_token=" . $access_token;

        $ch = curl_init($me_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $me_res = curl_exec($ch);
        curl_close($ch);

        $me_data = json_decode($me_res, true);

        if (!empty($me_data['data'][0]['id'])) {
            $waba_id = $me_data['data'][0]['id'];

            // Consultar números asociados a este WABA
            $phones_url = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$waba_id}/phone_numbers?access_token=" . $access_token;
            $ch = curl_init($phones_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $phones_res = curl_exec($ch);
            curl_close($ch);

            $phones_data = json_decode($phones_res, true);
            if (!empty($phones_data['data'][0]['id'])) {
                $phone_number_id = $phones_data['data'][0]['id'];
            }
        }
    }

    if (!$waba_id || !$phone_number_id) {
        throw new Exception("No se pudieron determinar el WABA ID ni el Phone Number ID del usuario.");
    }

    // ------------------------------------------------------------------
    // PASO 3: Suscribir la WABA del cliente a tu App (Webhook)
    // ------------------------------------------------------------------
    $subscribe_url = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$waba_id}/subscribed_apps";
    $ch = curl_init($subscribe_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'access_token' => $access_token
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $sub_res = curl_exec($ch);
    curl_close($ch);

    // ------------------------------------------------------------------
    // PASO 4: Guardar credenciales en la Base de Datos (`saas_negocios`)
    // ------------------------------------------------------------------
    // Reemplazar por tu conexión PDO existente ($pdo)
    require_once '../../config/db.php';

    $stmt = $pdo->prepare("
        UPDATE saas_negocios
        SET whatsapp_mode = 'own',
            wa_phone_number_id = :phone_number_id,
            wa_waba_id = :waba_id,
            wa_access_token = :access_token
        WHERE id = :negocio_id
    ");

    $stmt->execute([
        ':phone_number_id' => $phone_number_id,
        ':waba_id' => $waba_id,
        ':access_token' => $access_token,
        ':negocio_id' => $negocio_id
    ]);

    // ------------------------------------------------------------------
    // PASO 5: Disparar la creación automática de plantillas predeterminadas
    // ------------------------------------------------------------------
    require_once 'setup_templates.php';
    crearPlantillasBaseMeta($waba_id, $access_token);

    echo json_encode([
        'success' => true,
        'message' => 'Cuenta de WhatsApp Business conectada correctamente.',
        'data' => [
            'waba_id' => $waba_id,
            'phone_number_id' => $phone_number_id,
            'mode' => 'own'
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}