<?php
declare(strict_types=1);

ob_start();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

set_exception_handler(static function (Throwable $e): void {
    error_log('[instalar_puentesms] Excepción no capturada: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title></head><body style="font-family:sans-serif;background:#0f172a;color:#f8fafc;padding:2rem;"><h2>No se pudo cargar la página</h2><p>Ocurrió un error interno al preparar la vista. Revisá el registro del servidor.</p></body></html>';
    exit;
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[instalar_puentesms] Error fatal: ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            http_response_code(500);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title></head><body style="font-family:sans-serif;background:#0f172a;color:#f8fafc;padding:2rem;"><h2>Error inesperado</h2><p>Se interrumpió la carga de la página. Revisá el error_log del servidor.</p></body></html>';
    }
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['negocio_id'])) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: login_negocio.php?redirect=instalar_puentesms.php');
    exit;
}

$conexionPath = __DIR__ . '/conexion.php';
if (!is_file($conexionPath)) {
    throw new RuntimeException('No se encontró conexion.php en ' . __DIR__);
}

require_once $conexionPath;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('La conexión $pdo no está disponible tras incluir conexion.php');
}

$negocio_nombre = isset($_SESSION['negocio_nombre']) && is_string($_SESSION['negocio_nombre']) && $_SESSION['negocio_nombre'] !== ''
    ? $_SESSION['negocio_nombre']
    : 'Mi Negocio';

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Instalar PuenteSMS - <?php echo htmlspecialchars($negocio_nombre, ENT_QUOTES, 'UTF-8'); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen pb-12">
  <header class="bg-slate-950/90 border-b border-slate-800 sticky top-0 z-30">
    <div class="max-w-3xl mx-auto px-4 py-3 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <a href="panel_negocio.php" class="bg-slate-800 hover:bg-slate-700 text-slate-200 px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition">
          <i class="fa-solid fa-arrow-left"></i> Volver al Panel
        </a>
        <div>
          <h1 class="text-sm font-bold text-white">Instalar PuenteSMS</h1>
          <p class="text-[11px] text-emerald-400"><?php echo htmlspecialchars($negocio_nombre, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
      </div>
    </div>
  </header>

  <main class="max-w-3xl mx-auto px-4 py-6 space-y-6">
    <div class="bg-slate-950 border border-slate-800 rounded-2xl p-5 space-y-2">
      <h2 class="text-base font-bold text-white flex items-center gap-2">
        <i class="fa-solid fa-mobile-screen-button text-emerald-400"></i> ¿Qué es PuenteSMS?
      </h2>
      <p class="text-xs text-slate-300 leading-relaxed">
        Es la aplicación Android que conecta tu línea celular con el sistema para enviar recordatorios, señas y promociones, y recibir respuestas de tus clientes (como <strong>CONFIRMO</strong> o <strong>CANCELAR</strong>).
      </p>
    </div>

    <!-- Paso 1 a 3 -->
    <div class="bg-slate-950 border border-slate-800 rounded-2xl p-5 space-y-4">
      <h2 class="text-base font-bold text-white">Paso a paso de descarga e instalación</h2>

      <div class="space-y-3 text-xs text-slate-300">
        <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-3.5">
          <p class="font-bold text-emerald-400 mb-1">1. Descargá el archivo APK</p>
          <p>Usá el botón de descarga al final de esta página para bajar <strong>PuenteSMS.apk</strong> en el teléfono Android que hará de pasarela SMS.</p>
        </div>

        <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-3.5">
          <p class="font-bold text-emerald-400 mb-1">2. Permití instalar desde tu navegador o Archivos</p>
          <p>Si Android muestra un aviso de seguridad por ser una instalación externa, tocá <strong>Configuración</strong> y activá <strong>"Permitir desde esta fuente"</strong> para el navegador o gestor de archivos.</p>
        </div>

        <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-3.5">
          <p class="font-bold text-emerald-400 mb-1">3. Instalá y abrí la app</p>
          <p>Tocá el APK descargado, elegí <strong>Instalar</strong> y abrí <strong>PuenteSMS</strong> al finalizar.</p>
        </div>
      </div>
    </div>

    <!-- Permisos necesarios -->
    <div class="bg-slate-950 border border-slate-800 rounded-2xl p-5 space-y-4">
      <h2 class="text-base font-bold text-white flex items-center gap-2">
        <i class="fa-solid fa-shield-halved text-amber-400"></i> Permisos que debe tener PuenteSMS
      </h2>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
        <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-3.5 space-y-1">
          <p class="font-bold text-white"><i class="fa-solid fa-comment-sms text-emerald-400 mr-1.5"></i> SMS (Enviar y recibir)</p>
          <p class="text-slate-400">Obligatorio para despachar los SMS de la cola y detectar los mensajes entrantes de los clientes.</p>
        </div>

        <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-3.5 space-y-1">
          <p class="font-bold text-white"><i class="fa-solid fa-bell text-amber-400 mr-1.5"></i> Notificaciones</p>
          <p class="text-slate-400">Permite mostrar el estado del servicio en ejecución y avisos importantes del sistema.</p>
        </div>

        <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-3.5 space-y-1">
          <p class="font-bold text-white"><i class="fa-solid fa-battery-full text-emerald-400 mr-1.5"></i> Batería sin restricciones</p>
          <p class="text-slate-400">En <em>Info de la app &rarr; Batería</em>, elegí <strong>Sin restricciones</strong> para evitar que Android pause los envíos o campañas.</p>
        </div>

        <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-3.5 space-y-1">
          <p class="font-bold text-white"><i class="fa-solid fa-wifi text-sky-400 mr-1.5"></i> Acceso a Internet</p>
          <p class="text-slate-400">Necesario para sincronizar la cola, confirmar entregas y reportar SMS entrantes al servidor.</p>
        </div>
      </div>
    </div>

    <!-- Botón de descarga al final -->
    <div class="bg-slate-950 border border-emerald-500/30 rounded-2xl p-5 text-center space-y-3">
      <p class="text-xs text-slate-300">¿Listo para instalar en tu equipo Android?</p>
      <a href="descargar_puentesms.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-extrabold text-sm px-6 py-3.5 rounded-xl shadow-lg shadow-emerald-500/20 transition">
        <i class="fa-solid fa-download"></i> Descargar PuenteSMS.apk
      </a>
    </div>
  </main>
</body>
</html>