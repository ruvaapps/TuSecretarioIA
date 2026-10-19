<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

function urlBase64Encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function generarVapidKeysNativo() {
    $config = [
        "digest_alg" => "sha256",
        "private_key_type" => OPENSSL_KEYTYPE_EC,
        "curve_name" => "prime256v1"
    ];

    $res = openssl_pkey_new($config);
    if (!$res) {
        throw new Exception("Error al generar clave OpenSSL: " . openssl_error_string());
    }

    // Exportar Clave Privada
    openssl_pkey_export($res, $privKeyPem);
    $keyDetails = openssl_pkey_get_details($res);
    $ec = $keyDetails['ec'];

    // Convertir coordenadas X e Y a punto binario no comprimido (0x04 + X + Y)
    $pubBin = "\x04" . $ec['x'] . $ec['y'];
    $privBin = $ec['d'];

    return [
        'publicKey'  => urlBase64Encode($pubBin),
        'privateKey' => urlBase64Encode($privBin)
    ];
}

try {
    $keys = generarVapidKeysNativo();
    echo "<h2>Claves VAPID Generadas Exitosamente</h2>";
    echo "<p><strong>Clave Pública (Public Key):</strong><br>";
    echo "<textarea rows='3' cols='80' readonly>" . $keys['publicKey'] . "</textarea></p>";
    echo "<p><strong>Clave Privada (Private Key):</strong><br>";
    echo "<textarea rows='3' cols='80' readonly>" . $keys['privateKey'] . "</textarea></p>";
    echo "<p>✅ <em>Guardá ambas claves. La pública irá en el JS de tu panel y la privada en el backend PHP.</em></p>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}