<?php
// 1. Cargar el autoloader de Composer
require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Verificación de Web-Push PHP</h2>";

// 2. Comprobar si la clase WebPush existe
if (class_exists('Minishlink\WebPush\WebPush')) {
    echo "✅ <strong>Librería instalada correctamente:</strong> La clase Minishlink\WebPush\WebPush está disponible.<br><br>";
} else {
    echo "❌ <strong>Error:</strong> No se encuentra la clase WebPush. Revisa si ejecutaste 'composer require minishlink/web-push' y si la ruta a vendor/autoload.php es correcta.<br><br>";
}

// 3. Comprobar extensiones PHP requeridas por Web-Push
$extensiones = ['guzzlehttp' => null, 'openssl' => 'openssl', 'mbstring' => 'mbstring', 'curl' => 'curl', 'gmp' => 'gmp'];

echo "<h3>Requisitos de Extensiones PHP:</h3><ul>";

foreach (['openssl', 'mbstring', 'curl', 'gmp'] as $ext) {
    if (extension_loaded($ext)) {
        echo "<li>✅ Extensión PHP <strong>{$ext}</strong>: Activa</li>";
    } else {
        echo "<li>⚠️ Extensión PHP <strong>{$ext}</strong>: NO detectada (web-push requiere GMP o BCMath para cifrado de claves VAPID).</li>";
    }
}

// Comprobar bcmath como alternativa a gmp
if (!extension_loaded('gmp')) {
    if (extension_loaded('bcmath')) {
        echo "<li>✅ Extensión PHP <strong>bcmath</strong>: Activa (Sustituye a GMP para cálculos criptográficos)</li>";
    } else {
        echo "<li>❌ <strong>Atención:</strong> Se requiere la extensión <strong>GMP</strong> o <strong>BCMath</strong> en PHP para que Web-Push pueda generar y firmar las claves VAPID.</li>";
    }
}

echo "</ul>";