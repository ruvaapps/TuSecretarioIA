<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['negocio_id'])) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Ajustá la ruta si guardás el APK en otra carpeta, ej: __DIR__ . '/downloads/PuenteSMS.apk'
$apkPath = __DIR__ . '/downloads/PuenteSMS.apk';
$downloadName = 'PuenteSMS.apk';

if (!is_file($apkPath) || !is_readable($apkPath)) {
    http_response_code(404);
    exit('El archivo APK aún no está disponible en el servidor.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . (string)filesize($apkPath));

readfile($apkPath);
exit;