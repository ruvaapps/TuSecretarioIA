<?php
// instalador_composer.php
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(600);
error_reporting(E_ALL);

echo "<pre>";
echo "=== INSTALADOR AUTOMÁTICO DE DEPENDENCIAS WEB-PUSH VIA COMPOSER PHAR ===\n\n";

// 1. Crear composer.json si no existe
$composerJsonPath = __DIR__ . '/composer.json';
if (!file_exists($composerJsonPath)) {
    echo "1. Creando composer.json...\n";
    $composerConfig = [
        "require" => [
            "minishlink/web-push" => "^8.0",
            "guzzlehttp/guzzle"   => "^7.8"
        ],
        "config" => [
            "allow-plugins" => true,
            "platform" => [
                "php" => "8.1"
            ]
        ]
    ];
    file_put_contents($composerJsonPath, json_encode($composerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "✅ composer.json creado con éxito.\n\n";
} else {
    echo "1. composer.json ya existe.\n\n";
}

// 2. Descargar composer.phar si no existe
$pharPath = __DIR__ . '/composer.phar';
if (!file_exists($pharPath)) {
    echo "2. Descargando binario composer.phar...\n";
    $pharContent = @file_get_contents('https://getcomposer.org/download/latest-stable/composer.phar');
    if ($pharContent === false) {
        die("❌ Error al descargar composer.phar desde getcomposer.org. Verifica si allow_url_fopen está activo.");
    }
    file_put_contents($pharPath, $pharContent);
    echo "✅ composer.phar descargado exitosamente.\n\n";
} else {
    echo "2. composer.phar ya se encuentra descargado.\n\n";
}

// 3. Incluir e invocar Composer programáticamente
echo "3. Ejecutando 'composer install' para descargar dependencias reales y autoloader...\n";

// Establecer variables de entorno para que Composer trabaje localmente
putenv('COMPOSER_HOME=' . __DIR__ . '/.composer');
chdir(__DIR__);

// Intentar ejecución vía proc_open / exec / shell_exec
$output = [];
$returnVar = -1;

$cmd = "php composer.phar install --no-dev --optimize-autoloader 2>&1";

if (function_exists('exec')) {
    exec($cmd, $output, $returnVar);
    echo implode("\n", $output) . "\n";
} elseif (function_exists('shell_exec')) {
    $out = shell_exec($cmd);
    echo $out . "\n";
    $returnVar = 0;
} else {
    echo "❌ Las funciones exec/shell_exec están deshabilitadas en tu hosting.\n";
    echo "Invocando el entorno de Composer mediante sintaxis PHP...\n";
    
    // Método alternativo si exec está bloqueado
    require_once 'phar://' . __DIR__ . '/composer.phar/src/bootstrap.php';
    
    $_SERVER['argv'] = ['composer', 'install', '--no-dev', '--optimize-autoloader'];
    
    try {
        $app = new \Composer\Console\Application();
        $app->setAutoExit(false);
        $returnVar = $app->run();
    } catch (\Exception $e) {
        echo "❌ Excepción al ejecutar Composer interno: " . $e->getMessage() . "\n";
    }
}

if (file_exists(__DIR__ . '/vendor/autoload.php') && class_exists('Minishlink\WebPush\WebPush')) {
    echo "\n🎉 ¡ÉXITO ROTUNDO! La clase Minishlink\\WebPush\\WebPush fue instalada y cargada correctamente.\n";
} else {
    // Si aún así no la detecta, forzar la inclusión manual del nuevo vendor/autoload.php
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists('Minishlink\WebPush\WebPush')) {
        echo "\n🎉 ¡ÉXITO ROTUNDO! La clase Minishlink\\WebPush\\WebPush fue instalada y verificada correctamente.\n";
    } else {
        echo "\n⚠️ Advertencia: Revisa la salida de Composer para identificar posibles paquetes faltantes o restricciones de versión de PHP.\n";
    }
}

echo "</pre>";