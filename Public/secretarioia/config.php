<?php
// config.php - Carga de variables de entorno simples

function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
    return true;
}

// Cargar el archivo .env desde la raíz
loadEnv(__DIR__ . '/.env');

// Definir constantes globales si no estaban definidas
define('META_ACCESS_TOKEN', getenv('META_ACCESS_TOKEN'));
define('META_PHONE_NUMBER_ID', getenv('META_PHONE_NUMBER_ID'));
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY'));
define('VERIFY_TOKEN', getenv('VERIFY_TOKEN'));