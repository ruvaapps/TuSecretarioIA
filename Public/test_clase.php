<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

echo "<pre>";
if (class_exists('Minishlink\WebPush\WebPush')) {
    echo "✅ La clase Minishlink\\WebPush\\WebPush EXISTE y está lista para usarse.";
} else {
    echo "❌ La clase SIGUE SIN EXISTIR en vendor/autoload.php.";
}
echo "</pre>";