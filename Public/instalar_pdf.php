<?php
// instalar_pdf.php
set_time_limit(300);
ini_set('memory_limit', '512M');

echo "<h2>Iniciando instalación de smalot/pdfparser...</h2>";

// 1. Descargar composer.phar si no existe
if (!file_exists(__DIR__ . '/composer.phar')) {
    echo "Descargando composer.phar...<br>";
    $pharContent = @file_get_contents('https://getcomposer.org/composer-phar');
    if ($pharContent) {
        file_put_contents(__DIR__ . '/composer.phar', $pharContent);
    } else {
        die("<h3 style='color:red;'>Error al descargar composer.phar. Revisa las conexiones cURL/file_get_contents.</h3>");
    }
}

// 2. Configurar entorno y ejecutar
putenv('COMPOSER_HOME=' . __DIR__ . '/.composer');

$output = [];
$returnVar = 0;

// Ejecutamos composer require
exec('php composer.phar require smalot/pdfparser 2>&1', $output, $returnVar);

echo "<pre>" . implode("\n", $output) . "</pre>";

if ($returnVar === 0) {
    echo "<h3 style='color:green;'>¡Instalación completada con éxito!</h3>";
} else {
    echo "<h3 style='color:red;'>Ocurrió un error en la ejecución. Revisa el log superior.</h3>";
}