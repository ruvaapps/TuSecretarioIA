<?php
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(600);
error_reporting(E_ALL);

echo "<pre>";
echo "--- Descargador de Web-Push sin Composer CLI ---\n\n";

// Definir paquetes requeridos en version zip directa desde GitHub (v7.1.1 compatible PHP 8+)
$paquetes = [
    'minishlink/web-push' => 'https://github.com/web-push-libs/web-push-php/archive/refs/tags/v7.1.1.zip',
    'guzzlehttp/guzzle'   => 'https://github.com/guzzle/guzzle/archive/refs/tags/7.8.1.zip',
    'guzzlehttp/psr7'     => 'https://github.com/guzzle/psr7/archive/refs/tags/2.6.2.zip',
    'guzzlehttp/promises' => 'https://github.com/guzzle/promises/archive/refs/tags/2.0.2.zip',
    'psr/http-message'    => 'https://github.com/php-fig/http-message/archive/refs/tags/2.0.zip',
    'psr/http-client'     => 'https://github.com/php-fig/http-client/archive/refs/tags/1.0.3.zip',
];

if (!file_exists(__DIR__ . '/vendor')) {
    mkdir(__DIR__ . '/vendor', 0755, true);
}

foreach ($paquetes as $nombre => $url) {
    echo "Descargando {$nombre}...\n";
    $zipFile = __DIR__ . '/temp.zip';
    
    // Descargar zip
    $content = @file_get_contents($url, false, stream_context_create([
        'http' => ['header' => "User-Agent: PHP-Script\r\n"]
    ]));
    
    if ($content === false) {
        echo "❌ Error al descargar {$nombre}\n";
        continue;
    }
    
    file_put_contents($zipFile, $content);
    
    $zip = new ZipArchive;
    if ($zip->open($zipFile) === TRUE) {
        $targetDir = __DIR__ . '/vendor/' . $nombre;
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        // Extraer omitiendo la carpeta raíz del zip
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $parts = explode('/', $filename);
            array_shift($parts); // Quitar carpeta contenedora
            $relativePath = implode('/', $parts);
            
            if (empty($relativePath)) continue;
            
            $fullPath = $targetDir . '/' . $relativePath;
            if (substr($filename, -1) === '/') {
                if (!file_exists($fullPath)) mkdir($fullPath, 0755, true);
            } else {
                $dir = dirname($fullPath);
                if (!file_exists($dir)) mkdir($dir, 0755, true);
                copy("zip://" . $zipFile . "#" . $filename, $fullPath);
            }
        }
        $zip->close();
        echo "✅ {$nombre} extraído correctamente.\n";
    } else {
        echo "❌ Error al descomprimir {$nombre}\n";
    }
    
    if (file_exists($zipFile)) unlink($zipFile);
}

// Crear un autoloader.php casero equivalente a vendor/autoload.php
$autoloaderContent = <<<'PHP'
<?php
spl_autoload_register(function ($class) {
    $prefixes = [
        'Minishlink\\WebPush\\' => __DIR__ . '/minishlink/web-push/src/',
        'GuzzleHttp\\Psr7\\'   => __DIR__ . '/guzzlehttp/psr7/src/',
        'GuzzleHttp\\Promise\\' => __DIR__ . '/guzzlehttp/promises/src/',
        'GuzzleHttp\\'         => __DIR__ . '/guzzlehttp/guzzle/src/',
        'Psr\\Http\\Message\\'  => __DIR__ . '/psr/http-message/src/',
        'Psr\\Http\\Client\\'   => __DIR__ . '/psr/http-client/src/',
    ];

    foreach ($prefixes as $prefix => $base_dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});
PHP;

file_put_contents(__DIR__ . '/vendor/autoload.php', $autoloaderContent);
echo "\n✅ Archivo vendor/autoload.php generado exitosamente.\n";
echo "\n¡Proceso completado! Ya podés probar test_push.php";
echo "</pre>";