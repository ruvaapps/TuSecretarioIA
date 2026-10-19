<?php
// mp_callback.php
session_start();

// Incluir conexión subiendo un nivel de directorio
require_once __DIR__ . '/../conexion.php';

function logMPCallback($msg) {
    file_put_contents(__DIR__ . '/log_mp_callback.txt', "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

$code       = $_GET['code'] ?? null;
$negocio_id = $_GET['state'] ?? ($_SESSION['negocio_id'] ?? null);
$error      = $_GET['error'] ?? null;

if ($error) {
    logMPCallback("Error de autorización retornado por MP: " . $error);
    header("Location: ../panel_negocio.php?mp_status=error");
    exit;
}

if (!$code || !$negocio_id) {
    logMPCallback("Faltan parámetros en callback. Code: {$code} | Negocio ID: {$negocio_id}");
    header("Location: ../panel_negocio.php?mp_status=missing_data");
    exit;
}

// Configuración de la App en Mercado Pago
$clientId     = '3805741532914585';
$clientSecret = 'E5TjxS9MQgafyj6pIyV1SPEBinZXDYJT';

// URL exacta configurada en el flujo de MP
$redirectUri  = 'https://tusecretarioia.lovar.com.ar/mercadoPago/mp_callback.php';

// Petición POST a Mercado Pago para solicitar los Access Tokens del Negocio
$url = 'https://api.mercadopago.com/oauth/token';

$payload = [
    'client_secret' => $clientSecret,
    'client_id'     => $clientId,
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => $redirectUri
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    logMPCallback("Error cURL al solicitar OAuth token: " . $curlErr);
    header("Location: ../panel_negocio.php?mp_status=curl_error");
    exit;
}

$resData = json_decode($response, true);

if (isset($resData['access_token'])) {
    $accessToken  = $resData['access_token'];
    $refreshToken = $resData['refresh_token'];
    $mpUserId     = $resData['user_id'];
    $expiresIn    = $resData['expires_in']; // Segundos de vigencia (ej. 15552000 = 180 días)
    
    // Calcular fecha exacta de expiración
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

    try {
        // Guardar tokens en la base de datos
        $stmt = $pdo->prepare("
            UPDATE saas_negocios 
            SET mp_access_token = ?, 
                mp_refresh_token = ?, 
                mp_user_id = ?, 
                mp_token_expires_at = ? 
            WHERE id = ?
        ");
        $stmt->execute([$accessToken, $refreshToken, $mpUserId, $expiresAt, $negocio_id]);

        logMPCallback("Conexión OAuth exitosa para Negocio ID: {$negocio_id} | MP User ID: {$mpUserId}");
        header("Location: ../panel_negocio.php?mp_status=success");
        exit;
    } catch (PDOException $e) {
        logMPCallback("Error de BD al actualizar tokens: " . $e->getMessage());
        header("Location: ../panel_negocio.php?mp_status=db_error");
        exit;
    }
} else {
    logMPCallback("Error en respuesta OAuth de MP: " . json_encode($resData));
    header("Location: ../panel_negocio.php?mp_status=oauth_failed");
    exit;
}