<?php
session_start();

if (!isset($_SESSION['negocio_id'])) {
    header('Location: login_negocio.php?redirect=panel_negocio.php');
    exit;
}

require_once 'conexion.php';

$negocio_id     = $_SESSION['negocio_id'];
$negocio_nombre = $_SESSION['negocio_nombre'] ?? 'Mi Negocio';

// Configuración Mercado Pago
$client_id_mp = '3805741532914585'; // Reemplazar por tu Client ID
$redirect_uri_mp = urlencode('https://tusecretarioia.lovar.com.ar/mercadoPago/mp_callback.php');
$auth_url_mp = "https://auth.mercadopago.com.ar/authorization?client_id={$client_id_mp}&response_type=code&platform_id=mp&state={$negocio_id}&redirect_uri={$redirect_uri_mp}";

// Clave pública VAPID para Web Push
define('VAPID_PUBLIC_KEY', 'BB_sUL_80YWtAyK5m995PeOr_K0m_M3aYG89QOMVHXUege_PKuh4FJ7VB2-NSesPJcAfTKUm7_4MXYSFpybqJ1I');

// 1. Cargar la configuración general del negocio
$stmt = $pdo->prepare("SELECT * FROM saas_configuracion_agenda WHERE negocio_id = ?");
$stmt->execute([$negocio_id]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);

$duracion          = $config['duracion_turno_min'] ?? 30;
$telefono_whatsapp = $config['telefono_whatsapp'] ?? '5493517537781';

// 2. Cargar datos del negocio (domicilio e instagram)
$stmt_negocio = $pdo->prepare("SELECT slug, direccion, localidad, latitud, longitud, instagram FROM saas_negocios WHERE id = ?");
$stmt_negocio->execute([$negocio_id]);
$datos_negocio = $stmt_negocio->fetch(PDO::FETCH_ASSOC);

$direccion_negocio = $datos_negocio['direccion'] ?? '';
$localidad_negocio = $datos_negocio['localidad'] ?? '';
$latitud_negocio   = $datos_negocio['latitud'] ?? '';
$longitud_negocio  = $datos_negocio['longitud'] ?? '';
$instagram_negocio = $datos_negocio['instagram'] ?? '';
$slug = $datos_negocio['slug'] ?? '';

// 3. Cargar los rangos de horario dinámicos
$stmt_horarios = $pdo->prepare("
    SELECT 
        MIN(hora_apertura) AS hora_min, 
        MAX(hora_cierre) AS hora_max 
    FROM saas_horarios_atencion 
    WHERE negocio_id = ? AND esta_activo = 1
");
$stmt_horarios->execute([$negocio_id]);
$horarios = $stmt_horarios->fetch(PDO::FETCH_ASSOC);

$hora_apertura = !empty($horarios['hora_min']) ? date('H:i', strtotime($horarios['hora_min'])) : (!empty($config['hora_apertura']) ? date('H:i', strtotime($config['hora_apertura'])) : '08:00');
$hora_cierre   = !empty($horarios['hora_max']) ? date('H:i', strtotime($horarios['hora_max'])) : (!empty($config['hora_cierre']) ? date('H:i', strtotime($config['hora_cierre'])) : '20:00');

$slot_min = $hora_apertura . ':00';
$slot_max = $hora_cierre . ':00';

// Mensaje y Enlace de WhatsApp
$texto_mensaje   = "¡Hola! Quiero agendar un turno en " . $negocio_nombre;
$mensaje_inicial = rawurlencode($texto_mensaje);
$link_whatsapp   = "https://wa.me/" . preg_replace('/[^0-9]/', '', $telefono_whatsapp) . "?text=" . $mensaje_inicial;

// Enlace Público Web para Instagram / Redes
$protocolo      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$dominio        = $_SERVER['HTTP_HOST'];
$directorio     = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$link_instagram = $protocolo . $dominio . $directorio . "/reserva.php?slug=" . $slug;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Panel de Turnos - <?php echo htmlspecialchars($negocio_nombre); ?></title>
  
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#0f172a">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
  
  <!-- CDN para Generación de Códigos QR -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

  <!-- Leaflet.js para Mapa Interactivo -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

  <style>
    /* Estilos para el diseño oscuro de FullCalendar */
    .fc {
      --fc-border-color: #334155 !important;
      --fc-page-bg-color: transparent !important;
      --fc-neutral-bg-color: #0f172a !important;
      --fc-list-event-hover-bg-color: #1e293b !important;
      --fc-today-bg-color: rgba(16, 185, 129, 0.08) !important;
      font-family: inherit;
    }

    .fc .fc-col-header-cell {
      background-color: #0f172a !important;
      border-color: #334155 !important;
      padding: 8px 0 !important;
    }

    .fc .fc-col-header-cell-cushion {
      color: #f8fafc !important;
      font-weight: 600 !important;
      text-transform: capitalize !important;
      padding: 4px 8px !important;
      text-decoration: none !important;
    }

    .fc .fc-day-today .fc-col-header-cell-cushion {
      color: #34d399 !important;
      font-weight: 700 !important;
    }

    .fc .fc-timegrid-slot-label-cushion,
    .fc .fc-timegrid-axis-cushion {
      color: #94a3b8 !important;
      font-size: 0.75rem !important;
      font-weight: 500 !important;
    }

    .fc-theme-standard td, .fc-theme-standard th, .fc-theme-standard .fc-scrollgrid {
      border-color: #334155 !important;
    }

    .fc .fc-button-primary {
      background-color: #1e293b !important;
      border-color: #334155 !important;
      color: #f1f5f9 !important;
      font-size: 0.75rem !important;
      border-radius: 0.5rem !important;
      text-transform: capitalize !important;
    }

    .fc .fc-button-primary:hover {
      background-color: #334155 !important;
      border-color: #475569 !important;
    }

    .fc .fc-button-primary:disabled {
      background-color: #0f172a !important;
      border-color: #1e293b !important;
      opacity: 0.5;
    }

    .fc .fc-button-active {
      background-color: #10b981 !important;
      border-color: #10b981 !important;
      color: #022c22 !important;
      font-weight: 700 !important;
    }

    .fc .fc-toolbar-title {
      color: #f8fafc !important;
      font-size: 1.1rem !important;
      font-weight: 700 !important;
    }
  </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen select-none pb-20">

  <!-- Header Fijo con Menú Desplegable -->
  <header class="bg-slate-950/90 backdrop-blur border-b border-slate-800 sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
      <div class="flex items-center space-x-3">
        <div class="w-9 h-9 bg-emerald-500 rounded-xl flex items-center justify-center text-slate-950 font-bold text-base shadow-lg shadow-emerald-500/20">
          <i class="fa-solid fa-store"></i>
        </div>
        <div>
          <h1 class="text-sm font-bold text-white leading-tight"><?php echo htmlspecialchars($negocio_nombre); ?></h1>
          <p class="text-[10px] text-emerald-400 font-medium">IA Activa (<?php echo $hora_apertura; ?> - <?php echo $hora_cierre; ?> hs)</p>
        </div>
      </div>

      <div class="flex items-center gap-2 sm:gap-3">
        <!-- ACCESO RÁPIDO: Clientes & Promociones -->
        <a href="clientes/index.php" class="bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-2" title="Clientes y Promociones">
          <i class="fa-solid fa-users-gear text-emerald-400 font-bold"></i>
          <span class="hidden sm:inline">Clientes & Promos</span>
        </a>

        <!-- Botón para Notificaciones Push -->
        <button id="btnNotificacionesPush" onclick="toggleSuscripcionPush()" class="bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-2" title="Gestionar Notificaciones Push">
          <i class="fa-solid fa-bell font-bold"></i>
          <span class="hidden sm:inline" id="txtBtnPush">Activar Notificaciones</span>
        </button>

        <!-- Botón de Instalación PWA -->
        <button id="btnInstalarApp" onclick="instalarPWA()" class="hidden items-center gap-2 bg-teal-500/10 hover:bg-teal-500/20 text-teal-400 border border-teal-500/30 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all">
          <i class="fa-solid fa-download"></i>
          <span class="hidden sm:inline">Instalar App</span>
        </button>

        <!-- Menú Desplegable de Configuración -->
        <div class="relative">
          <button onclick="toggleMenu()" id="btnMenu" class="bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 w-10 h-10 rounded-xl flex items-center justify-center text-base transition">
            <i class="fa-solid fa-bars"></i>
          </button>

          <div id="dropdownMenu" class="hidden absolute right-0 mt-2 w-56 bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl z-50 overflow-hidden py-1">
            <div class="px-4 py-2 border-b border-slate-800 text-[11px] text-slate-400 font-semibold uppercase tracking-wider">
              Opciones del Panel
            </div>

            <a href="clientes/index.php" class="w-full text-left px-4 py-2.5 text-xs text-emerald-400 font-semibold hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-users-gear text-emerald-400 w-4"></i> Clientes & Promociones
            </a>

            <button onclick="abrirModalMercadoPago(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-slate-200 hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-credit-card text-sky-400 w-4"></i> Conectar Mercado Pago
            </button>
			
            <button onclick="abrirModalDatosCobro(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-slate-200 hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-building-columns text-emerald-400 w-4"></i> Configurar Cobros / Transferencia
            </button>
			
            <button onclick="abrirModalRevisionPagos(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-amber-300 hover:bg-slate-800 flex items-center gap-2.5 transition font-semibold">
              <i class="fa-solid fa-receipt text-amber-400 w-4"></i> Revisar Comprobantes
            </button>

            <button onclick="toggleSuscripcionPush(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-slate-200 hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-bell text-amber-400 w-4"></i> Notificaciones Push
            </button>

            <button onclick="abrirModalHorarios(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-slate-200 hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-clock text-emerald-400 w-4"></i> Configurar Horarios
            </button>
            
            <button onclick="abrirModalServicios(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-slate-200 hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-list-check text-emerald-400 w-4"></i> Configurar Servicios
            </button>

            <button onclick="abrirModalRecursos(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-emerald-400 font-semibold hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-cubes text-emerald-400 w-4"></i> Recursos / Espacios
            </button>

            <button onclick="abrirModalDomicilio(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-slate-200 hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-location-dot text-emerald-400 w-4"></i> Configurar Domicilio
            </button>

            <button onclick="abrirModalInstagram(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-slate-200 hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-brands fa-instagram text-emerald-400 w-4"></i> Configurar Instagram
            </button>

            <button onclick="abrirModalAlerta(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-slate-200 hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-bell text-emerald-400 w-4"></i> Alerta Resumen Diario
            </button>
            
            <button onclick="abrirModalQR(); toggleMenu();" class="w-full text-left px-4 py-2.5 text-xs text-slate-200 hover:bg-slate-800 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-qrcode text-emerald-400 w-4"></i> Mis Enlaces y QR
            </button>

            <div class="border-t border-slate-800 my-1"></div>

            <a href="logout.php" class="w-full text-left px-4 py-2.5 text-xs text-red-400 hover:bg-red-500/10 flex items-center gap-2.5 transition">
              <i class="fa-solid fa-right-from-bracket w-4"></i> Cerrar Sesión
            </a>
          </div>
        </div>
      </div>
    </div>
  </header>

  <main class="max-w-7xl mx-auto px-2 sm:px-4 py-4 space-y-4">
	
	<!-- Referencias de Colores -->
	<div class="flex items-center justify-around text-[11px] bg-slate-950 p-2.5 rounded-xl border border-slate-800">
	  <div class="flex items-center gap-1.5">
	    <span class="w-2.5 h-2.5 bg-blue-500 rounded-full"></span>
	    <span>Confirmado</span>
	  </div>
	  <div class="flex items-center gap-1.5">
	    <span class="w-2.5 h-2.5 bg-amber-500 rounded-full"></span>
	    <span>Por Revisar</span>
	  </div>
	  <div class="flex items-center gap-1.5">
	    <span class="w-2.5 h-2.5 bg-orange-500 rounded-full"></span>
	    <span>Pend. Pago</span>
	  </div>
	  <div class="flex items-center gap-1.5">
	    <span class="w-2.5 h-2.5 bg-red-500 rounded-full"></span>
	    <span>Bloqueado</span>
	  </div>
	</div>

    <!-- FILTRO DE RECURSOS PARA EL CALENDARIO -->
    <div id="contenedorFiltroCalendario" class="hidden flex items-center justify-between bg-slate-950 p-2.5 rounded-xl border border-slate-800 text-xs">
      <span class="font-bold text-slate-300 flex items-center gap-2">
        <i class="fa-solid fa-filter text-emerald-400"></i> Filtrar Agenda:
      </span>
      <select id="filtroRecursoCalendario" onchange="filtrarCalendarioPorRecurso()" class="bg-slate-900 border border-slate-800 rounded-lg px-3 py-1.5 text-white focus:outline-none focus:border-emerald-500 font-semibold">
        <option value="">-- Todos los Recursos --</option>
      </select>
    </div>

    <!-- Calendario -->
    <div class="bg-slate-950 border border-slate-800 p-2 sm:p-4 rounded-xl shadow-2xl">
      <div id="calendar" class="text-slate-200 text-xs"></div>
    </div>

  </main>

  <!-- BOTÓN FLOTANTE NUEVO TURNO -->
  <button onclick="abrirModalNuevoTurno()" class="fixed bottom-6 right-6 w-14 h-14 bg-emerald-500 hover:bg-emerald-400 text-slate-950 rounded-full flex items-center justify-center text-xl shadow-2xl shadow-emerald-500/40 z-30 transition transform active:scale-95">
    <i class="fa-solid fa-plus"></i>
  </button>

  <!-- MODAL: REVISIÓN DE COMPROBANTES / TRANSFERENCIAS -->
  <div id="modalRevisionPagos" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-2xl rounded-2xl p-5 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-receipt text-amber-400"></i> Comprobantes Pendientes de Revisión
        </h3>
        <button onclick="cerrarModal('modalRevisionPagos')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>

      <div id="listaPendientesRevision" class="space-y-3"></div>
    </div>
  </div>
	
  <!-- MODAL: CONFIGURAR DATOS DE COBRO / TRANSFERENCIA -->
  <div id="modalDatosCobro" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-lg rounded-2xl p-5 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-building-columns text-emerald-400"></i> Métodos de Pago y Datos Bancarios
        </h3>
        <button onclick="cerrarModal('modalDatosCobro')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>
      
      <form id="formDatosCobro" onsubmit="guardarDatosCobro(event)" class="space-y-4 text-xs">
        <div class="bg-slate-950 p-3 rounded-xl border border-slate-800 space-y-2">
          <h4 class="font-bold text-slate-200 text-xs mb-1">Método de Cobro Activo</h4>
          
          <div class="flex items-center justify-between">
            <label for="permiteMP" class="text-slate-300 font-medium cursor-pointer">Mercado Pago (Link automático)</label>
            <input type="radio" id="permiteMP" name="metodo_pago_activo" value="mercadopago" class="w-4 h-4 accent-emerald-500 cursor-pointer">
          </div>

          <div class="flex items-center justify-between border-t border-slate-800/60 pt-2">
            <label for="permiteTransf" class="text-slate-300 font-medium cursor-pointer">Transferencia Bancaria / CVU</label>
            <input type="radio" id="permiteTransf" name="metodo_pago_activo" value="transferencia" class="w-4 h-4 accent-emerald-500 cursor-pointer">
          </div>
        </div>

        <div class="space-y-3">
          <h4 class="font-bold text-emerald-400 text-xs flex items-center gap-1.5">
            <i class="fa-solid fa-file-invoice-dollar"></i> Datos para Transferencia Directa
          </h4>

          <div>
            <label class="block text-slate-400 mb-1">Nombre del Banco / Billetera Virtual</label>
            <input type="text" id="bancoNombre" name="banco_nombre" placeholder="Ej: Mercado Pago / BBVA / Ualá" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>

          <div>
            <label class="block text-slate-400 mb-1">Titular de la Cuenta</label>
            <input type="text" id="bancoTitular" name="banco_titular" placeholder="Ej: Juan Pérez" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-slate-400 mb-1">CUIT / CUIL</label>
              <input type="text" id="bancoCuit" name="banco_cuit_cuil" placeholder="Ej: 20-35123456-9" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
            </div>
            <div>
              <label class="block text-slate-400 mb-1">Alias de la Cuenta</label>
              <input type="text" id="bancoAlias" name="banco_alias" placeholder="Ej: mi.negocio.mp" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
            </div>
          </div>

          <div>
            <label class="block text-slate-400 mb-1">CBU / CVU (22 dígitos)</label>
            <input type="text" id="bancoCbu" name="banco_cbu_cvu" maxlength="22" placeholder="0000003100012345678901" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>

          <div>
            <label class="block text-slate-400 mb-1">Instrucciones Adicionales (Opcional)</label>
            <textarea id="bancoInstrucciones" name="banco_instrucciones" rows="2" placeholder="Ej: Enviar comprobante dentro de los 30 minutos para congelar la reserva." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 resize-none"></textarea>
          </div>
        </div>

        <div class="flex gap-2 pt-2 border-t border-slate-800">
          <button type="button" onclick="cerrarModal('modalDatosCobro')" class="flex-1 bg-slate-800 text-slate-300 font-semibold py-2.5 rounded-xl text-xs hover:bg-slate-700 transition">
            Cancelar
          </button>
          <button type="submit" class="flex-1 bg-emerald-500 text-slate-950 font-bold py-2.5 rounded-xl text-xs hover:bg-emerald-400 transition">
            Guardar Datos
          </button>
        </div>
      </form>
    </div>
  </div>	

  <!-- MODAL: MERCADO PAGO -->
  <div id="modalMercadoPago" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-5 space-y-4 shadow-2xl">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-credit-card text-sky-400"></i> Cobrar con Mercado Pago
        </h3>
        <button onclick="cerrarModal('modalMercadoPago')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>
      <div class="space-y-3 text-xs text-slate-300">
        <p>Conecta tu cuenta para recibir los pagos directos de las señas o servicios solicitados por tus clientes.</p>
        <div class="pt-2">
          <a href="<?php echo $auth_url_mp; ?>" class="w-full bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold py-3 px-4 rounded-xl text-center block transition shadow-lg shadow-sky-500/20">
            Conectar con Mercado Pago
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: MIS ENLACES Y QR -->
  <div id="modalQR" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-5 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-qrcode text-emerald-400"></i> Tu QR y Enlaces
        </h3>
        <button onclick="cerrarModal('modalQR')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>

      <div class="flex flex-col items-center gap-4 text-center">
        <div class="bg-white p-3 rounded-2xl shadow-inner">
          <div id="qrcode"></div>
        </div>

        <p class="text-xs text-slate-400">
          Usa tu QR o los siguientes enlaces según dónde desees ofrecer la reserva de turnos.
        </p>

        <div class="w-full space-y-1.5 text-left">
          <label class="block text-[11px] font-semibold text-emerald-400 flex items-center gap-1.5">
            <i class="fa-brands fa-instagram text-pink-500"></i> Enlace Web (Bio de Instagram)
          </label>
          <div class="flex items-center gap-2 bg-slate-950 p-2 rounded-xl border border-slate-800 w-full">
            <input type="text" id="linkInstagram" readonly value="<?php echo htmlspecialchars($link_instagram); ?>" class="bg-transparent text-xs text-slate-300 px-2 w-full focus:outline-none select-all"/>
            <button onclick="copiarLink('linkInstagram', 'btnCopiarInsta')" id="btnCopiarInsta" class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-xs px-3 py-1.5 rounded-lg transition flex items-center gap-1 shrink-0">
              <i class="fa-regular fa-copy"></i> Copiar
            </button>
          </div>
        </div>

        <div class="w-full space-y-1.5 text-left">
          <label class="block text-[11px] font-semibold text-emerald-400 flex items-center gap-1.5">
            <i class="fa-brands fa-whatsapp text-emerald-500"></i> Enlace Directo por WhatsApp
          </label>
          <div class="flex items-center gap-2 bg-slate-950 p-2 rounded-xl border border-slate-800 w-full">
            <input type="text" id="linkNegocio" readonly value="<?php echo htmlspecialchars($link_whatsapp); ?>" class="bg-transparent text-xs text-slate-300 px-2 w-full focus:outline-none select-all"/>
            <button onclick="copiarLink('linkNegocio', 'btnCopiarWA')" id="btnCopiarWA" class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-xs px-3 py-1.5 rounded-lg transition flex items-center gap-1 shrink-0">
              <i class="fa-regular fa-copy"></i> Copiar
            </button>
          </div>
        </div>

        <button onclick="descargarQR()" class="w-full bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold py-2 rounded-xl border border-slate-700 transition flex items-center justify-center gap-2 mt-2">
          <i class="fa-solid fa-download text-emerald-400"></i> Descargar QR para Imprimir
        </button>
      </div>
    </div>
  </div>

  <!-- MODAL: CONFIGURAR ALERTA RESUMEN DIARIO -->
  <div id="modalAlerta" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-5 space-y-4 shadow-2xl">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-bell text-emerald-400"></i> Resumen Diario por WhatsApp
        </h3>
        <button onclick="cerrarModal('modalAlerta')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>
      
      <form id="formAlerta" onsubmit="guardarConfigAlerta(event)" class="space-y-4 text-xs">
        <p class="text-slate-400">
          Configura si deseas recibir un mensaje automático cada mañana por WhatsApp con la cantidad de turnos programados para el día.
        </p>

        <div class="flex items-center justify-between bg-slate-950 p-3 rounded-xl border border-slate-800">
          <label for="alertaActiva" class="font-bold text-white">Activar Alerta Diaria</label>
          <input type="checkbox" id="alertaActiva" name="alerta_activa" class="w-5 h-5 accent-emerald-500 rounded cursor-pointer">
        </div>

        <div>
          <label class="block text-slate-400 mb-1 font-semibold">Hora de envío:</label>
          <input type="time" id="alertaHora" name="alerta_hora" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
        </div>

        <div class="flex gap-2 pt-2 border-t border-slate-800">
          <button type="button" onclick="cerrarModal('modalAlerta')" class="flex-1 bg-slate-800 text-slate-300 font-semibold py-2.5 rounded-xl text-xs hover:bg-slate-700 transition">
            Cancelar
          </button>
          <button type="submit" class="flex-1 bg-emerald-500 text-slate-950 font-bold py-2.5 rounded-xl text-xs hover:bg-emerald-400 transition">
            Guardar
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: CONFIGURAR INSTAGRAM -->
  <div id="modalInstagram" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-5 space-y-4 shadow-2xl">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-brands fa-instagram text-emerald-400"></i> Configurar Instagram
        </h3>
        <button onclick="cerrarModal('modalInstagram')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>
      
      <form id="formInstagram" onsubmit="guardarInstagram(event)" class="space-y-4 text-xs">
        <p class="text-slate-400">
          Ingresa el usuario de Instagram del negocio (puedes incluirlo con o sin @).
        </p>

        <div>
          <label class="block text-slate-400 mb-1 font-semibold">Usuario de Instagram:</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500 font-bold">@</span>
            <input type="text" id="inputInstagram" name="instagram" value="<?php echo htmlspecialchars($instagram_negocio); ?>" placeholder="tu_negocio" class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-8 pr-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>
        </div>

        <div class="flex gap-2 pt-2 border-t border-slate-800">
          <button type="button" onclick="cerrarModal('modalInstagram')" class="flex-1 bg-slate-800 text-slate-300 font-semibold py-2.5 rounded-xl text-xs hover:bg-slate-700 transition">
            Cancelar
          </button>
          <button type="submit" class="flex-1 bg-emerald-500 text-slate-950 font-bold py-2.5 rounded-xl text-xs hover:bg-emerald-400 transition">
            Guardar
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: CONFIGURAR DOMICILIO -->
  <div id="modalDomicilio" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-lg rounded-2xl p-5 space-y-4 shadow-2xl max-h-[95vh] overflow-y-auto">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-location-dot text-emerald-400"></i> Ubicación del Comercio
        </h3>
        <button onclick="cerrarModal('modalDomicilio')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>
      
      <form id="formDomicilio" onsubmit="guardarDomicilio(event)" class="space-y-3 text-xs">
        <input type="hidden" id="domLatitud" name="latitud" value="<?php echo htmlspecialchars($latitud_negocio); ?>">
        <input type="hidden" id="domLongitud" name="longitud" value="<?php echo htmlspecialchars($longitud_negocio); ?>">

        <div>
          <label class="block text-slate-400 mb-1">Dirección (Calle y Número)</label>
          <input type="text" id="domDireccion" name="direccion" value="<?php echo htmlspecialchars($direccion_negocio); ?>" onblur="buscarUbicacionEnMapa()" placeholder="Ej: Av. Colón 1234" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
        </div>

        <div>
          <label class="block text-slate-400 mb-1">Localidad / Ciudad</label>
          <input type="text" id="domLocalidad" name="localidad" value="<?php echo htmlspecialchars($localidad_negocio); ?>" onblur="buscarUbicacionEnMapa()" placeholder="Ej: Córdoba Capital" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
        </div>

        <div class="space-y-1">
          <div class="flex justify-between items-center">
            <label class="block text-slate-400 font-medium">Ubicación en el Mapa</label>
            <span class="text-[10px] text-emerald-400"><i class="fa-solid fa-arrows-up-down-left-right"></i> Arrastra el marcador</span>
          </div>
          <div id="mapaDomicilio" class="w-full h-52 rounded-xl border border-slate-800 overflow-hidden shadow-inner z-10"></div>
        </div>

        <div class="flex gap-2 pt-2 border-t border-slate-800">
          <button type="button" onclick="cerrarModal('modalDomicilio')" class="flex-1 bg-slate-800 text-slate-300 font-semibold py-2.5 rounded-xl text-xs hover:bg-slate-700 transition">
            Cancelar
          </button>
          <button type="submit" class="flex-1 bg-emerald-500 text-slate-950 font-bold py-2.5 rounded-xl text-xs hover:bg-emerald-400 transition">
            Guardar Domicilio
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: DETALLE DE TURNO -->
  <div id="modalDetalle" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-5 space-y-4 shadow-2xl">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base">Detalle del Turno</h3>
        <button onclick="cerrarModal('modalDetalle')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>
      <div class="space-y-2 text-xs text-slate-300">
        <p><strong class="text-slate-100">Cliente/Servicio:</strong> <span id="detTitulo"></span></p>
        <p><strong class="text-slate-100">Teléfono:</strong> <span id="detTelefono"></span></p>
        <p><strong class="text-slate-100">Horario:</strong> <span id="detHorario"></span></p>
        <p><strong class="text-slate-100">Estado:</strong> <span id="detEstado" class="font-bold"></span></p>
        
        <div id="detComprobanteBox" class="hidden bg-slate-950 p-3 rounded-xl border border-amber-500/30 space-y-1 mt-2">
          <p class="font-bold text-amber-400 text-xs flex items-center gap-1.5">
            <i class="fa-solid fa-receipt"></i> Datos del Comprobante
          </p>
          <p class="text-slate-300">👤 <strong>Titular Origen:</strong> <span id="detCompOrigen"></span></p>
          <p class="text-slate-300">🏦 <strong>Banco / App:</strong> <span id="detCompBanco"></span></p>
          <p class="text-slate-300">💵 <strong>Monto Detectado:</strong> $<span id="detCompMonto"></span></p>
          <p class="text-slate-300">🔢 <strong>N° Operación:</strong> <span id="detCompOperacion"></span></p>
        </div>
      </div>

      <div id="detAccionesStandard" class="flex gap-2 pt-2 border-t border-slate-800">
        <button onclick="cambiarEstadoTurno('completado')" class="flex-1 bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 py-2 rounded-xl text-xs font-bold hover:bg-emerald-600 hover:text-white transition">
          <i class="fa-solid fa-check"></i> Completar
        </button>
        <button onclick="cambiarEstadoTurno('cancelado')" class="flex-1 bg-red-600/20 text-red-400 border border-red-500/30 py-2 rounded-xl text-xs font-bold hover:bg-red-600 hover:text-white transition">
          <i class="fa-solid fa-ban"></i> Cancelar Turno
        </button>
      </div>

      <div id="detAccionesRevision" class="hidden flex gap-2 pt-2 border-t border-slate-800">
        <button onclick="validarComprobanteDirecto('confirmado')" class="flex-1 bg-emerald-500 text-slate-950 font-bold py-2 rounded-xl text-xs hover:bg-emerald-400 transition flex items-center justify-center gap-1">
          <i class="fa-solid fa-check"></i> Aprobar Pago
        </button>
        <button onclick="validarComprobanteDirecto('cancelado')" class="flex-1 bg-red-500/20 text-red-400 border border-red-500/30 font-bold py-2 rounded-xl text-xs hover:bg-red-600 hover:text-white transition flex items-center justify-center gap-1">
          <i class="fa-solid fa-xmark"></i> Rechazar
        </button>
      </div>
    </div>
  </div>

  <!-- MODAL: NUEVO TURNO (INCLUYE SELECCIÓN DE RECURSO) -->
  <div id="modalNuevoTurno" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-5 space-y-4 shadow-2xl">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base">Agendar Turno Manual</h3>
        <button onclick="cerrarModal('modalNuevoTurno')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>
      
      <form id="formNuevoTurno" onsubmit="guardarTurno(event)" class="space-y-3 text-xs">
        <div>
          <label class="block text-slate-400 mb-1">Nombre del Cliente</label>
          <input type="text" id="inputNombre" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
        </div>

        <div>
          <label class="block text-slate-400 mb-1">Teléfono Móvil (WhatsApp)</label>
          <div class="flex gap-2">
            <select id="codigoPais" onchange="validarTelefonoWhatsApp()" class="bg-slate-950 border border-slate-800 rounded-xl px-2 py-2 text-white text-xs focus:outline-none focus:border-emerald-500 w-28 shrink-0">
              <option value="54" selected>🇦🇷 +54</option>
              <option value="55">🇧🇷 +55</option>
              <option value="56">🇨🇱 +56</option>
              <option value="598">🇺🇾 +598</option>
              <option value="595">🇵🇾 +595</option>
              <option value="591">🇧🇴 +591</option>
              <option value="51">🇵🇪 +51</option>
              <option value="52">🇲🇽 +52</option>
              <option value="34">🇪🇸 +34</option>
              <option value="1">🇺🇸 +1</option>
            </select>

            <input 
              type="tel" 
              id="inputTelefono" 
              oninput="validarTelefonoWhatsApp()" 
              placeholder="Ej: 3511234567" 
              class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
            >
          </div>
          <div id="msjValidacionTel" class="mt-1 text-[11px] min-h-[18px]"></div>
        </div>

        <div>
          <label class="block text-slate-400 mb-1">Servicio</label>
          <select id="selectServicio" onchange="cargarHorariosDisponibles()" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500"></select>
        </div>

        <!-- RECURSO / CANCHA / CONSULTORIO -->
        <div id="contenedorSelectRecurso" class="hidden">
          <label class="block text-slate-400 mb-1">Recurso / Espacio (Opcional)</label>
          <select id="selectRecursoTurno" onchange="cargarHorariosDisponibles()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
            <option value="">-- Asignar automáticamente --</option>
          </select>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-slate-400 mb-1">Fecha</label>
            <input type="date" id="inputFecha" onchange="cargarHorariosDisponibles()" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>
          <div>
            <label class="block text-slate-400 mb-1">Hora Disponible</label>
            <select id="selectHora" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
              <option value="">Seleccionar fecha...</option>
            </select>
          </div>
        </div>

        <button type="submit" class="w-full bg-emerald-500 text-slate-950 font-bold py-2.5 rounded-xl text-xs hover:bg-emerald-400 transition mt-2">
          Guardar Reserva
        </button>
      </form>
    </div>
  </div>

  <!-- MODAL: CONFIGURAR HORARIOS DE ATENCIÓN -->
  <div id="modalHorarios" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-2xl rounded-2xl p-5 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-clock text-emerald-400"></i> Configurar Horarios de Atención
        </h3>
        <button onclick="cerrarModal('modalHorarios')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>
      
      <form id="formHorarios" onsubmit="guardarHorarios(event)" class="space-y-3 text-xs">
        <div id="listaHorarios" class="space-y-3">
          <p class="text-slate-400 text-center py-4">Cargando horarios...</p>
        </div>

        <div class="pt-3 border-t border-slate-800 flex gap-2">
          <button type="button" onclick="cerrarModal('modalHorarios')" class="flex-1 bg-slate-800 text-slate-300 font-semibold py-2.5 rounded-xl text-xs hover:bg-slate-700 transition">
            Cancelar
          </button>
          <button type="submit" class="flex-1 bg-emerald-500 text-slate-950 font-bold py-2.5 rounded-xl text-xs hover:bg-emerald-400 transition">
            Guardar Cambios
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: CONFIGURAR SERVICIOS -->
  <div id="modalServicios" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-lg rounded-2xl p-5 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-list-check text-emerald-400"></i> Configurar Servicios
        </h3>
        <button onclick="cerrarModal('modalServicios')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>

      <form id="formNuevoServicio" onsubmit="guardarNuevoServicio(event)" class="bg-slate-950 p-3 rounded-xl border border-slate-800 space-y-2 text-xs">
        <h4 class="font-bold text-emerald-400 mb-1">Agregar Nuevo Servicio</h4>
        <div>
          <input type="text" id="servNombre" placeholder="Nombre (ej: Corte de Pelo)" required class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-white focus:outline-none focus:border-emerald-500">
        </div>
        <div class="grid grid-cols-3 gap-2">
          <div>
            <label class="block text-[10px] text-slate-400 mb-0.5 font-semibold">Precio ($)</label>
            <input type="number" step="0.01" id="servPrecio" placeholder="Precio ($)" required class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-white focus:outline-none focus:border-emerald-500">
          </div>
          <div>
            <label class="block text-[10px] text-slate-400 mb-0.5 font-semibold">Costo ($)</label>
            <input type="number" step="0.01" id="servCosto" placeholder="Costo ($)" value="0" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-white focus:outline-none focus:border-emerald-500">
          </div>
          <div>
            <label class="block text-[10px] text-slate-400 mb-0.5 font-semibold">Seña ($)</label>
            <input type="number" step="0.01" id="servSena" placeholder="Seña ($)" value="0" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-white focus:outline-none focus:border-emerald-500">
          </div>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <input type="number" id="servHoras" min="0" placeholder="Hs (ej: 1)" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-white focus:outline-none focus:border-emerald-500">
          <input type="number" id="servMinutos" min="0" max="59" placeholder="Min (ej: 15)" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-white focus:outline-none focus:border-emerald-500">
        </div>
        <button type="submit" class="w-full bg-emerald-500 text-slate-950 font-bold py-1.5 rounded-lg hover:bg-emerald-400 transition mt-1">
          + Agregar Servicio
        </button>
      </form>

      <div class="space-y-2">
        <h4 class="font-bold text-slate-300 text-xs">Servicios Existentes</h4>
        <div id="listaServiciosGestion" class="space-y-2 text-xs">
          <p class="text-slate-400 text-center py-2">Cargando servicios...</p>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: EDITAR SERVICIO -->
  <div id="modalEditarServicio" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-[60] flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-5 space-y-4 shadow-2xl">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-pen-to-square text-emerald-400"></i> Modificar Servicio
        </h3>
        <button onclick="cerrarModal('modalEditarServicio')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>

      <form id="formEditarServicio" onsubmit="guardarEdicionServicio(event)" class="space-y-3 text-xs">
        <input type="hidden" id="editServId">
        <div>
          <label class="block text-slate-400 mb-1 font-semibold">Nombre del Servicio</label>
          <input type="text" id="editServNombre" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
        </div>

        <div class="grid grid-cols-3 gap-2">
          <div>
            <label class="block text-slate-400 mb-1 font-semibold">Precio ($)</label>
            <input type="number" step="0.01" id="editServPrecio" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>
          <div>
            <label class="block text-slate-400 mb-1 font-semibold">Costo ($)</label>
            <input type="number" step="0.01" id="editServCosto" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>
          <div>
            <label class="block text-slate-400 mb-1 font-semibold">Seña ($)</label>
            <input type="number" step="0.01" id="editServSena" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-slate-400 mb-1 font-semibold">Horas</label>
            <input type="number" id="editServHoras" min="0" placeholder="0" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>
          <div>
            <label class="block text-slate-400 mb-1 font-semibold">Minutos</label>
            <input type="number" id="editServMinutos" min="0" max="59" placeholder="30" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>
        </div>

        <div class="flex gap-2 pt-2 border-t border-slate-800">
          <button type="button" onclick="cerrarModal('modalEditarServicio')" class="flex-1 bg-slate-800 text-slate-300 font-semibold py-2.5 rounded-xl text-xs hover:bg-slate-700 transition">
            Cancelar
          </button>
          <button type="submit" class="flex-1 bg-emerald-500 text-slate-950 font-bold py-2.5 rounded-xl text-xs hover:bg-emerald-400 transition">
            Guardar Cambios
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: CONFIGURAR RECURSOS / CANCHAS / CONSULTORIOS -->
  <div id="modalRecursos" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-lg rounded-2xl p-5 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-cubes text-emerald-400"></i> Gestión de Recursos / Espacios
        </h3>
        <button onclick="cerrarModal('modalRecursos')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>

      <!-- Formulario Nuevo Recurso -->
      <form id="formNuevoRecurso" onsubmit="guardarNuevoRecurso(event)" class="bg-slate-950 p-3 rounded-xl border border-slate-800 space-y-2 text-xs">
        <h4 class="font-bold text-emerald-400 mb-1">Agregar Nuevo Recurso</h4>
        
        <div>
          <input type="text" id="recNombre" placeholder="Nombre (ej: Cancha 1, Camilla A, Silla 2)" required class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-white focus:outline-none focus:border-emerald-500">
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-[10px] text-slate-400 mb-0.5 font-semibold">Tipo</label>
            <select id="recTipo" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-white focus:outline-none focus:border-emerald-500">
              <option value="físico" selected>Físico (Cancha / Camilla)</option>
              <option value="humano">Humano (Profesional)</option>
              <option value="espacio">Espacio (Sala / Aula)</option>
            </select>
          </div>
          <div>
            <label class="block text-[10px] text-slate-400 mb-0.5 font-semibold">Capacidad</label>
            <input type="number" id="recCapacidad" min="1" value="1" placeholder="1" required class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-white focus:outline-none focus:border-emerald-500">
          </div>
        </div>

        <button type="submit" class="w-full bg-emerald-500 text-slate-950 font-bold py-1.5 rounded-lg hover:bg-emerald-400 transition mt-1">
          + Agregar Recurso
        </button>
      </form>

      <!-- Lista de Recursos Existentes -->
      <div class="space-y-2">
        <h4 class="font-bold text-slate-300 text-xs">Recursos Existentes</h4>
        <div id="listaRecursosGestion" class="space-y-2 text-xs">
          <p class="text-slate-400 text-center py-2">Cargando recursos...</p>
        </div>
      </div>
    </div>
  </div>

  <script>
    var calendar;
    var turnoSeleccionadoId = null;
    var recursoFiltroActual = '';
    const diasNombres = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];

    const VAPID_PUBLIC_KEY = '<?php echo VAPID_PUBLIC_KEY; ?>';

    var mapaDomicilio = null;
    var marcadorDomicilio = null;

    document.addEventListener('DOMContentLoaded', function() {
      var urlReserva = document.getElementById("linkInstagram").value;
      var qrElemento = document.getElementById("qrcode");
      
      new QRCode(qrElemento, {
        text: urlReserva,
        width: 140,
        height: 140,
        colorDark: "#0f172a",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.H
      });

      var calendarEl = document.getElementById('calendar');
      var vistaInicial = window.innerWidth < 768 ? 'timeGridDay' : 'timeGridWeek';

      calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: vistaInicial,
        locale: 'es',
        height: 'auto',
        headerToolbar: {
          left: 'prev,next today',
          center: 'title',
          right: 'timeGridDay,timeGridWeek'
        },
        buttonText: { today: 'Hoy', week: 'Semana', day: 'Día' },
        slotMinTime: '<?php echo $slot_min; ?>',
        slotMaxTime: '<?php echo $slot_max; ?>',
        allDaySlot: false,
        events: function(info, successCallback, failureCallback) {
          var url = 'obtener_eventos_panel.php?start=' + info.startStr + '&end=' + info.endStr;
          if (recursoFiltroActual) {
            url += '&recurso_id=' + recursoFiltroActual;
          }
          fetch(url)
            .then(res => res.json())
            .then(data => successCallback(data))
            .catch(err => failureCallback(err));
        },
        eventClick: function(info) {
          var props = info.event.extendedProps;
          if (props.tipo === 'turno') {
            turnoSeleccionadoId = info.event.id;
            document.getElementById('detTitulo').innerText = info.event.title;
            document.getElementById('detTelefono').innerText = props.telefono || 'Sin datos';
            document.getElementById('detHorario').innerText = info.event.start.toLocaleString();
            document.getElementById('detEstado').innerText = props.estado;

            var boxComp = document.getElementById('detComprobanteBox');
            var accStd = document.getElementById('detAccionesStandard');
            var accRev = document.getElementById('detAccionesRevision');

            if (props.comprobante) {
              document.getElementById('detCompOrigen').innerText = props.comprobante.nombre_origen || 'No registrado';
              document.getElementById('detCompBanco').innerText = props.comprobante.banco_origen || 'No registrado';
              document.getElementById('detCompMonto').innerText = props.comprobante.monto_detectado || props.monto_sena;
              document.getElementById('detCompOperacion').innerText = props.comprobante.numero_operacion || 'Sin número';
              boxComp.classList.remove('hidden');
            } else {
              boxComp.classList.add('hidden');
            }

            if (props.estado === 'pendiente_revision') {
              accStd.classList.add('hidden');
              accRev.classList.remove('hidden');
            } else {
              accStd.classList.remove('hidden');
              accRev.classList.add('hidden');
            }

            document.getElementById('modalDetalle').classList.remove('hidden');
          }
        }
      });

      calendar.render();
      cargarServicios();
      cargarRecursosSelect();
      cargarFiltroRecursosCalendario();

      verificarSuscripcionPush();
    });

    function filtrarCalendarioPorRecurso() {
      recursoFiltroActual = document.getElementById('filtroRecursoCalendario').value;
      if (calendar) {
        calendar.refetchEvents();
      }
    }

    function cargarFiltroRecursosCalendario() {
      fetch('acciones_panel.php?accion=obtener_recursos')
        .then(res => res.json())
        .then(res => {
          const select = document.getElementById('filtroRecursoCalendario');
          const contenedor = document.getElementById('contenedorFiltroCalendario');
          select.innerHTML = '<option value="">-- Todos los Recursos --</option>';

          if (res.status === 'success' && res.data && res.data.length > 0) {
            const activos = res.data.filter(r => r.estado === 'activo');
            if (activos.length > 0) {
              activos.forEach(r => {
                select.innerHTML += `<option value="${r.id}">${r.nombre}</option>`;
              });
              if (contenedor) contenedor.classList.remove('hidden');
            } else {
              if (contenedor) contenedor.classList.add('hidden');
            }
          } else {
            if (contenedor) contenedor.classList.add('hidden');
          }
        })
        .catch(err => console.error("Error al cargar filtro de recursos:", err));
    }

    function cargarRecursosSelect() {
      fetch('acciones_panel.php?accion=obtener_recursos')
        .then(res => res.json())
        .then(res => {
          const select = document.getElementById('selectRecursoTurno');
          const contenedor = document.getElementById('contenedorSelectRecurso');
          select.innerHTML = '<option value="">-- Asignar automáticamente --</option>';

          if (res.status === 'success' && res.data && res.data.length > 0) {
            const activos = res.data.filter(r => r.estado === 'activo');
            if (activos.length > 0) {
              activos.forEach(r => {
                select.innerHTML += `<option value="${r.id}">${r.nombre}</option>`;
              });
              if (contenedor) contenedor.classList.remove('hidden');
            } else {
              if (contenedor) contenedor.classList.add('hidden');
            }
          } else {
            if (contenedor) contenedor.classList.add('hidden');
          }
        })
        .catch(err => console.error("Error al cargar select de recursos:", err));
    }

    function abrirModalMercadoPago() {
      document.getElementById('modalMercadoPago').classList.remove('hidden');
    }

    function urlBase64ToUint8Array(base64String) {
      const padding = '='.repeat((4 - base64String.length % 4) % 4);
      const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
      const rawData = window.atob(base64);
      const outputArray = new Uint8Array(rawData.length);
      for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
      }
      return outputArray;
    }

    async function verificarSuscripcionPush() {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

      try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        const btn = document.getElementById('btnNotificacionesPush');
        const txt = document.getElementById('txtBtnPush');

        if (sub) {
          if (btn) btn.className = 'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-2';
          if (txt) txt.innerText = 'Notificaciones Activas';
        } else {
          if (btn) btn.className = 'bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-2';
          if (txt) txt.innerText = 'Activar Notificaciones';
        }
      } catch (err) {
        console.error('Error al verificar suscripción Push:', err);
      }
    }

    async function toggleSuscripcionPush() {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        alert('Tu navegador no soporta notificaciones Push.');
        return;
      }

      try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();

        if (sub) {
          if (!confirm('¿Deseas desactivar las notificaciones Push en este dispositivo?')) return;

          const endpoint = sub.endpoint;
          await sub.unsubscribe();

          const res = await fetch('acciones_panel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'eliminar_suscripcion_push', endpoint: endpoint })
          });

          const text = await res.text();
          let data;
          try { data = JSON.parse(text); } catch (e) { data = {}; }

          if (data.status === 'success') {
            alert('Notificaciones Push desactivadas correctamente.');
          } else {
            alert('Suscripción cancelada en el navegador.');
          }
          verificarSuscripcionPush();

        } else {
          const permiso = await Notification.requestPermission();
          if (permiso !== 'granted') {
            alert('Debes permitir las notificaciones en tu navegador.');
            return;
          }

          if (typeof VAPID_PUBLIC_KEY === 'undefined' || !VAPID_PUBLIC_KEY) {
            throw new Error('VAPID_PUBLIC_KEY no está definida.');
          }

          const convertedKey = urlBase64ToUint8Array(VAPID_PUBLIC_KEY);
          const newSub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: convertedKey
          });

          const res = await fetch('acciones_panel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'guardar_suscripcion_push', subscription: newSub })
          });

          const text = await res.text();
          let data;
          try { data = JSON.parse(text); } catch (e) { throw new Error('El servidor no devolvió un JSON válido'); }

          if (data.status === 'success') {
            alert('¡Notificaciones Push activadas con éxito!');
            verificarSuscripcionPush();
          } else {
            alert('Error al guardar la suscripción: ' + (data.message || 'Desconocido'));
          }
        }
      } catch (err) {
        console.error('Error en el proceso Push:', err);
        alert('Ocurrió un problema: ' + err.message);
      }
    }

    function toggleMenu() {
      const menu = document.getElementById('dropdownMenu');
      menu.classList.toggle('hidden');
    }

    window.addEventListener('click', function(e) {
      const menu = document.getElementById('dropdownMenu');
      const btn = document.getElementById('btnMenu');
      if (!btn.contains(e.target) && !menu.contains(e.target)) {
        menu.classList.add('hidden');
      }
    });

    function abrirModalQR() {
      document.getElementById('modalQR').classList.remove('hidden');
    }

    function copiarLink(inputId, btnId) {
      var inputLink = document.getElementById(inputId);
      inputLink.select();
      inputLink.setSelectionRange(0, 99999);

      navigator.clipboard.writeText(inputLink.value).then(function() {
        var btn = document.getElementById(btnId);
        btn.innerHTML = `<i class="fa-solid fa-check"></i> ¡Copiado!`;
        btn.classList.replace("bg-emerald-500", "bg-teal-400");

        setTimeout(function() {
          btn.innerHTML = `<i class="fa-regular fa-copy"></i> Copiar`;
          btn.classList.replace("bg-teal-400", "bg-emerald-500");
        }, 2000);
      });
    }

    async function descargarQR() {
      var imgQR = document.getElementById("qrcode").querySelector("img");
      var srcData = imgQR ? imgQR.src : null;
      if (!srcData) {
        var canvasQR = document.getElementById("qrcode").querySelector("canvas");
        if (canvasQR) srcData = canvasQR.toDataURL("image/png");
      }

      if (!srcData) {
        alert("No se pudo obtener la imagen del QR.");
        return;
      }

      try {
        const response = await fetch(srcData);
        const blob = await response.blob();
        const nombreArchivo = "QR_Reservas_<?php echo preg_replace('/[^A-Za-z0-9]/', '', $negocio_nombre); ?>.png";
        const file = new File([blob], nombreArchivo, { type: "image/png" });

        if (navigator.canShare && navigator.canShare({ files: [file] })) {
          await navigator.share({
            files: [file],
            title: 'Código QR de Reservas',
            text: 'Código QR para agendar turnos'
          });
          return;
        }

        const blobUrl = URL.createObjectURL(blob);
        const enlaceDescarga = document.createElement("a");
        enlaceDescarga.href = blobUrl;
        enlaceDescarga.download = nombreArchivo;
        document.body.appendChild(enlaceDescarga);
        enlaceDescarga.click();
        document.body.removeChild(enlaceDescarga);

        setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);

      } catch (error) {
        console.error("Error al procesar/descargar el QR:", error);
        window.open(srcData, '_blank');
      }
    }

    function cerrarModal(id) {
      document.getElementById(id).classList.add('hidden');
    }

    function abrirModalNuevoTurno() {
      document.getElementById('inputFecha').value = new Date().toISOString().split('T')[0];
      document.getElementById('modalNuevoTurno').classList.remove('hidden');
      document.getElementById('inputTelefono').value = '';
      validarTelefonoWhatsApp();
      cargarHorariosDisponibles();
    }

    function validarTelefonoWhatsApp() {
      const codPais = document.getElementById('codigoPais').value;
      let rawTel = document.getElementById('inputTelefono').value.replace(/[^0-9]/g, '');
      const msjBox = document.getElementById('msjValidacionTel');

      if (!rawTel) {
        msjBox.innerHTML = `<span class="text-amber-400 font-semibold"><i class="fa-solid fa-triangle-exclamation"></i> Si no coloca un celular, no le llegará el aviso.</span>`;
        return { esCelular: false, numeroCompleto: '' };
      }

      let esCelular = false;
      let numeroLimpio = rawTel;

      if (codPais === '54') {
        if (numeroLimpio.startsWith('0')) numeroLimpio = numeroLimpio.substring(1);
        if (numeroLimpio.startsWith('15')) numeroLimpio = numeroLimpio.substring(2);

        if (numeroLimpio.length === 10) {
          esCelular = true;
          numeroLimpio = '549' + numeroLimpio;
        } else {
          numeroLimpio = '54' + numeroLimpio;
        }
      } else {
        if (numeroLimpio.length >= 8 && numeroLimpio.length <= 13) esCelular = true;
        numeroLimpio = codPais + numeroLimpio;
      }

      if (esCelular) {
        msjBox.innerHTML = `<span class="text-emerald-400 font-semibold"><i class="fa-brands fa-whatsapp"></i> Formato celular válido</span>`;
      } else {
        msjBox.innerHTML = `<span class="text-amber-400 font-semibold"><i class="fa-solid fa-circle-exclamation"></i> Ingrese código de área + número sin el 0 ni el 15. Ej: <strong>3511234567</strong></span>`;
      }

      return { esCelular, numeroCompleto: numeroLimpio };
    }

    function cargarServicios() {
      fetch('acciones_panel.php?accion=obtener_servicios')
        .then(res => res.json())
        .then(res => {
          var select = document.getElementById('selectServicio');
          select.innerHTML = '';
          if (res.status === 'success' && res.data.length > 0) {
            res.data.forEach(s => {
              select.innerHTML += `<option value="${s.id}">${s.nombre} ($${s.precio})</option>`;
            });
            cargarHorariosDisponibles();
          } else {
            select.innerHTML = '<option value="">Sin servicios registrados</option>';
          }
        })
        .catch(err => console.error("Error al cargar servicios:", err));
    }

    function cargarHorariosDisponibles() {
      var fecha = document.getElementById('inputFecha').value;
      var servicioId = document.getElementById('selectServicio').value;
      var recursoId = document.getElementById('selectRecursoTurno') ? document.getElementById('selectRecursoTurno').value : '';
      var selectHora = document.getElementById('selectHora');

      if (!fecha || !servicioId) {
        selectHora.innerHTML = '<option value="">Elegí servicio y fecha</option>';
        return;
      }

      selectHora.innerHTML = '<option value="">Cargando horarios...</option>';

      fetch(`acciones_panel.php?accion=obtener_horarios_disponibles&fecha=${fecha}&servicio_id=${servicioId}&recurso_id=${recursoId}`)
        .then(res => res.json())
        .then(res => {
          selectHora.innerHTML = '';
          if (res.status === 'success' && res.data.length > 0) {
            res.data.forEach(h => {
              selectHora.innerHTML += `<option value="${h}">${h} hs</option>`;
            });
          } else {
            selectHora.innerHTML = '<option value="">Sin horarios disponibles</option>';
          }
        })
        .catch(err => {
          console.error("Error al cargar horarios:", err);
          selectHora.innerHTML = '<option value="">Error al cargar</option>';
        });
    }

    function guardarTurno(e) {
      e.preventDefault();
      var hora = document.getElementById('selectHora').value;
      
      if (!hora) {
        alert('Por favor elegí un horario disponible.');
        return;
      }

      var validacionTel = validarTelefonoWhatsApp();

      var formData = new FormData();
      formData.append('accion', 'crear_turno');
      formData.append('cliente_nombre', document.getElementById('inputNombre').value);
      formData.append('cliente_telefono', validacionTel.numeroCompleto);
      formData.append('servicio_id', document.getElementById('selectServicio').value);
      formData.append('recurso_id', document.getElementById('selectRecursoTurno').value || '');
      formData.append('fecha', document.getElementById('inputFecha').value);
      formData.append('hora', hora);

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            cerrarModal('modalNuevoTurno');
            calendar.refetchEvents();
            document.getElementById('formNuevoTurno').reset();
            document.getElementById('msjValidacionTel').innerHTML = '';
          } else {
            alert('Error: ' + res.message);
          }
        });
    }

    function cambiarEstadoTurno(nuevoEstado) {
      if (!turnoSeleccionadoId) return;
      
      var formData = new FormData();
      formData.append('accion', 'cambiar_estado');
      formData.append('turno_id', turnoSeleccionadoId);
      formData.append('estado', nuevoEstado);

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            cerrarModal('modalDetalle');
            calendar.refetchEvents();
          } else {
            alert('Error: ' + res.message);
          }
        });
    }

    /* --- LÓGICA DE GESTIÓN DE RECURSOS / CANCHAS --- */
    function abrirModalRecursos() {
      document.getElementById('modalRecursos').classList.remove('hidden');
      cargarListaGestionRecursos();
    }

    function cargarListaGestionRecursos() {
      const cont = document.getElementById('listaRecursosGestion');
      cont.innerHTML = '<p class="text-slate-400 text-center py-2">Cargando recursos...</p>';

      fetch('acciones_panel.php?accion=obtener_recursos')
        .then(res => res.json())
        .then(res => {
          cont.innerHTML = '';
          if (res.status === 'success' && res.data.length > 0) {
            res.data.forEach(r => {
              let badgeEstado = '<span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Activo</span>';
              if (r.estado === 'mantenimiento') badgeEstado = '<span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Mantenimiento</span>';
              if (r.estado === 'inactivo') badgeEstado = '<span class="bg-red-500/10 text-red-400 border border-red-500/20 px-2 py-0.5 rounded text-[10px] font-bold">Inactivo</span>';

              cont.innerHTML += `
                <div class="flex items-center justify-between bg-slate-950 p-2.5 rounded-xl border border-slate-800">
                  <div>
                    <div class="flex items-center gap-2">
                      <span class="font-bold text-white block">${r.nombre}</span>
                      ${badgeEstado}
                    </div>
                    <span class="text-[11px] text-slate-400">Tipo: ${r.tipo_recurso} | Capacidad: ${r.capacidad} pers.</span>
                  </div>
                  <div class="flex items-center gap-1">
                    <select onchange="cambiarEstadoRecurso(${r.id}, this.value)" class="bg-slate-900 border border-slate-800 rounded px-1.5 py-1 text-[11px] text-white focus:outline-none">
                      <option value="activo" ${r.estado === 'activo' ? 'selected' : ''}>Activo</option>
                      <option value="mantenimiento" ${r.estado === 'mantenimiento' ? 'selected' : ''}>Mantenimiento</option>
                      <option value="inactivo" ${r.estado === 'inactivo' ? 'selected' : ''}>Inactivo</option>
                    </select>
                    <button onclick="eliminarRecurso(${r.id})" class="text-red-400 hover:text-red-300 p-1.5 rounded hover:bg-red-500/10 transition" title="Eliminar Recurso">
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </div>
                </div>
              `;
            });
          } else {
            cont.innerHTML = '<p class="text-slate-400 text-center py-2">No hay recursos configurados.</p>';
          }
        })
        .catch(err => console.error("Error al cargar lista de recursos:", err));
    }

    function guardarNuevoRecurso(e) {
      e.preventDefault();
      var formData = new FormData();
      formData.append('accion', 'crear_recurso');
      formData.append('nombre', document.getElementById('recNombre').value);
      formData.append('tipo_recurso', document.getElementById('recTipo').value);
      formData.append('capacidad', document.getElementById('recCapacidad').value);

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            document.getElementById('formNuevoRecurso').reset();
            cargarListaGestionRecursos();
            cargarRecursosSelect();
            cargarFiltroRecursosCalendario();
          } else {
            alert('Error: ' + res.message);
          }
        })
        .catch(err => console.error("Error al guardar recurso:", err));
    }

    function cambiarEstadoRecurso(recursoId, nuevoEstado) {
      var formData = new FormData();
      formData.append('accion', 'cambiar_estado_recurso');
      formData.append('recurso_id', recursoId);
      formData.append('estado', nuevoEstado);

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            cargarListaGestionRecursos();
            cargarRecursosSelect();
            cargarFiltroRecursosCalendario();
          } else {
            alert('Error: ' + res.message);
          }
        });
    }

    function eliminarRecurso(recursoId) {
      if (!confirm('¿Estás seguro de eliminar este recurso?')) return;

      var formData = new FormData();
      formData.append('accion', 'eliminar_recurso');
      formData.append('recurso_id', recursoId);

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            cargarListaGestionRecursos();
            cargarRecursosSelect();
            cargarFiltroRecursosCalendario();
          } else {
            alert('Error: ' + res.message);
          }
        });
    }

    /* --- OTRAS FUNCIONES EXISTENTES --- */
    function abrirModalAlerta() {
      document.getElementById('modalAlerta').classList.remove('hidden');
      fetch('acciones_panel.php?accion=obtener_config_alerta')
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            document.getElementById('alertaActiva').checked = res.data.activa === 1;
            document.getElementById('alertaHora').value = res.data.hora || '08:00';
          }
        })
        .catch(err => console.error("Error al obtener alerta:", err));
    }

    function guardarConfigAlerta(e) {
      e.preventDefault();
      var form = document.getElementById('formAlerta');
      var formData = new FormData(form);
      formData.append('accion', 'guardar_config_alerta');

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            alert('Configuración guardada con éxito.');
            cerrarModal('modalAlerta');
          } else {
            alert('Error: ' + res.message);
          }
        });
    }

    function abrirModalInstagram() {
      document.getElementById('modalInstagram').classList.remove('hidden');
      fetch('acciones_panel.php?accion=obtener_instagram')
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success' && res.data) {
            document.getElementById('inputInstagram').value = res.data.instagram || '';
          }
        })
        .catch(err => console.error("Error al consultar instagram:", err));
    }

    function guardarInstagram(e) {
      e.preventDefault();
      var form = document.getElementById('formInstagram');
      var formData = new FormData(form);
      formData.append('accion', 'guardar_instagram');

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            alert('Instagram actualizado con éxito.');
            cerrarModal('modalInstagram');
          } else {
            alert('Error: ' + res.message);
          }
        })
        .catch(err => console.error("Error al guardar instagram:", err));
    }

    function initMapaDomicilio(latDefault = -31.4167, lngDefault = -64.1833) {
      if (mapaDomicilio) return;

      mapaDomicilio = L.map('mapaDomicilio').setView([latDefault, lngDefault], 15);

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
      }).addTo(mapaDomicilio);

      marcadorDomicilio = L.marker([latDefault, lngDefault], { draggable: true }).addTo(mapaDomicilio);

      marcadorDomicilio.on('dragend', function(e) {
        var pos = marcadorDomicilio.getLatLng();
        document.getElementById('domLatitud').value = pos.lat.toFixed(6);
        document.getElementById('domLongitud').value = pos.lng.toFixed(6);
      });

      mapaDomicilio.on('click', function(e) {
        marcadorDomicilio.setLatLng(e.latlng);
        document.getElementById('domLatitud').value = e.latlng.lat.toFixed(6);
        document.getElementById('domLongitud').value = e.latlng.lng.toFixed(6);
      });
    }

    function abrirModalDomicilio() {
      document.getElementById('modalDomicilio').classList.remove('hidden');
      obtenerDomicilio();
    }

    function obtenerDomicilio() {
      fetch('acciones_panel.php?accion=obtener_domicilio')
        .then(res => res.json())
        .then(res => {
          let lat = -31.4167;
          let lng = -64.1833;

          if (res.status === 'success' && res.data) {
            document.getElementById('domDireccion').value = res.data.direccion || '';
            document.getElementById('domLocalidad').value = res.data.localidad || '';

            if (res.data.latitud && res.data.longitud) {
              lat = parseFloat(res.data.latitud);
              lng = parseFloat(res.data.longitud);
            }
          }

          document.getElementById('domLatitud').value  = lat;
          document.getElementById('domLongitud').value = lng;

          initMapaDomicilio(lat, lng);

          if (mapaDomicilio) {
            mapaDomicilio.setView([lat, lng], 15);
            marcadorDomicilio.setLatLng([lat, lng]);
            
            setTimeout(() => {
              mapaDomicilio.invalidateSize();
            }, 250);
          }
        })
        .catch(err => console.error("Error al consultar domicilio:", err));
    }

    function buscarUbicacionEnMapa() {
      var dir = document.getElementById('domDireccion').value.trim();
      var loc = document.getElementById('domLocalidad').value.trim();

      if (!dir) return;

      var consulta = encodeURIComponent(`${dir}, ${loc}`);
      fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${consulta}`)
        .then(res => res.json())
        .then(data => {
          if (data && data.length > 0) {
            var lat = parseFloat(data[0].lat);
            var lon = parseFloat(data[0].lon);

            document.getElementById('domLatitud').value  = lat;
            document.getElementById('domLongitud').value = lon;

            if (mapaDomicilio && marcadorDomicilio) {
              mapaDomicilio.setView([lat, lon], 16);
              marcadorDomicilio.setLatLng([lat, lon]);
            }
          }
        })
        .catch(err => console.error("Error al geocodificar dirección:", err));
    }

    function guardarDomicilio(e) {
      e.preventDefault();
      var form = document.getElementById('formDomicilio');
      var formData = new FormData(form);
      formData.append('accion', 'guardar_domicilio');

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            alert('Domicilio guardado con éxito.');
            cerrarModal('modalDomicilio');
          } else {
            alert('Error: ' + res.message);
          }
        })
        .catch(err => console.error("Error al guardar domicilio:", err));
    }

    function abrirModalRevisionPagos() {
      document.getElementById('modalRevisionPagos').classList.remove('hidden');
      cargarComprobantesPendientes();
    }

    function validarComprobanteDirecto(nuevoEstado) {
      if (!turnoSeleccionadoId) return;
      validarComprobante(turnoSeleccionadoId, nuevoEstado);
      cerrarModal('modalDetalle');
    }

    function cargarComprobantesPendientes() {
      const cont = document.getElementById('listaPendientesRevision');
      cont.innerHTML = '<p class="text-slate-400 text-center py-4">Cargando pagos por revisar...</p>';

      fetch('acciones_panel.php?accion=obtener_pendientes_revision')
        .then(res => res.json())
        .then(res => {
          cont.innerHTML = '';
          if (res.status === 'success' && res.data.length > 0) {
            res.data.forEach(item => {
              cont.innerHTML += `
                <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3">
                  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 border-b border-slate-800/60 pb-2">
                    <div>
                      <span class="font-bold text-white text-sm">${item.cliente_nombre}</span>
                      <p class="text-slate-400 text-xs">💈 ${item.servicio_nombre} | 📅 ${item.fecha_fmt} - ⏰ ${item.hora_fmt} hs</p>
                    </div>
                    <span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2.5 py-1 rounded-lg text-xs font-bold">
                      Seña Requerida: $${item.monto_sena}
                    </span>
                  </div>

                  <div class="bg-slate-900/80 p-3 rounded-lg border border-slate-800/80 text-xs grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <p class="text-slate-300">👤 <strong>Titular Origen:</strong> <span class="text-emerald-400 font-semibold">${item.nombre_origen}</span></p>
                    <p class="text-slate-300">🏦 <strong>Banco / App:</strong> ${item.banco_origen}</p>
                    <p class="text-slate-300">💵 <strong>Monto Transferido:</strong> <span class="text-emerald-400 font-bold">$${item.monto_detectado || item.monto_sena}</span></p>
                    <p class="text-slate-300">🔢 <strong>N° Operación:</strong> <span class="text-amber-300 font-mono">${item.numero_operacion}</span></p>
                  </div>

                  <div class="flex items-center gap-2 pt-1">
                    <button onclick="validarComprobante(${item.turno_id}, 'confirmado')" class="flex-1 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold px-3 py-2 rounded-xl text-xs transition flex items-center justify-center gap-1.5 shadow-lg shadow-emerald-500/10">
                      <i class="fa-solid fa-check"></i> Aprobar
                    </button>
                    <button onclick="validarComprobante(${item.turno_id}, 'cancelado')" class="flex-1 bg-red-500/20 hover:bg-red-500/30 text-red-400 border border-red-500/40 px-3 py-2 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5">
                      <i class="fa-solid fa-xmark"></i> Rechazar
                    </button>
                  </div>
                </div>
              `;
            });
          } else {
            cont.innerHTML = '<p class="text-slate-400 text-center py-6">🎉 ¡Excelente! No tenés comprobantes pendientes de revisión.</p>';
          }
        })
        .catch(err => console.error("Error al cargar comprobantes:", err));
    }

    function validarComprobante(turnoId, nuevoEstado) {
      const accionTexto = nuevoEstado === 'confirmado' ? 'aprobar' : 'rechazar';
      if (!confirm(`¿Estás seguro de ${accionTexto} este comprobante?`)) return;

      var formData = new FormData();
      formData.append('accion', 'validar_comprobante');
      formData.append('turno_id', turnoId);
      formData.append('estado', nuevoEstado);

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            cargarComprobantesPendientes();
            if (typeof calendar !== 'undefined') calendar.refetchEvents();
          } else {
            alert('Error: ' + res.message);
          }
        })
        .catch(err => console.error("Error al procesar validación:", err));
    }

    function abrirModalDatosCobro() {
      document.getElementById('modalDatosCobro').classList.remove('hidden');
      obtenerDatosCobro();
    }

    function obtenerDatosCobro() {
      fetch('acciones_panel.php?accion=obtener_datos_cobro')
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success' && res.data) {
            if (res.data.permite_transferencia == 1) {
              document.getElementById('permiteTransf').checked = true;
            } else {
              document.getElementById('permiteMP').checked = true;
            }
            document.getElementById('bancoNombre').value = res.data.banco_nombre || '';
            document.getElementById('bancoTitular').value = res.data.banco_titular || '';
            document.getElementById('bancoCuit').value = res.data.banco_cuit_cuil || '';
            document.getElementById('bancoAlias').value = res.data.banco_alias || '';
            document.getElementById('bancoCbu').value = res.data.banco_cbu_cvu || '';
            document.getElementById('bancoInstrucciones').value = res.data.banco_instrucciones || '';
          }
        })
        .catch(err => console.error("Error al obtener datos de cobro:", err));
    }

    function guardarDatosCobro(e) {
      e.preventDefault();
      var form = document.getElementById('formDatosCobro');
      var formData = new FormData(form);
      formData.append('accion', 'guardar_datos_cobro');

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            alert('Datos de cobro actualizados con éxito.');
            cerrarModal('modalDatosCobro');
          } else {
            alert('Error: ' + res.message);
          }
        })
        .catch(err => console.error("Error al guardar datos de cobro:", err));
    }

    function abrirModalHorarios() {
      document.getElementById('modalHorarios').classList.remove('hidden');
      cargarHorariosConfiguracion();
    }

    function toggleModoHorario(diaIndex) {
      const esCortado = document.getElementById(`tipo_cortado_${diaIndex}`).checked;
      const contCorrido = document.getElementById(`seccion_corrido_${diaIndex}`);
      const contCortado = document.getElementById(`seccion_cortado_${diaIndex}`);

      if (esCortado) {
        contCorrido.classList.add('hidden');
        contCortado.classList.remove('hidden');
      } else {
        contCorrido.classList.remove('hidden');
        contCortado.classList.add('hidden');
      }
    }

    function cargarHorariosConfiguracion() {
      const cont = document.getElementById('listaHorarios');
      cont.innerHTML = '<p class="text-slate-400 text-center py-4">Cargando horarios...</p>';

      fetch('acciones_panel.php?accion=obtener_horarios_config')
        .then(res => res.json())
        .then(res => {
          cont.innerHTML = '';
          const horariosGuardados = (res.status === 'success' && Array.isArray(res.data)) ? res.data : [];

          for (let i = 0; i < 7; i++) {
            const h = horariosGuardados.find(item => parseInt(item.dia_semana) === i) || {};
            const activo = h.esta_activo == 1 || h.esta_activo === undefined;

            const apertura = h.hora_apertura ? h.hora_apertura.substring(0, 5) : '09:00';
            const cierre   = h.hora_cierre ? h.hora_cierre.substring(0, 5) : '18:00';
            const cierreManana = h.hora_cierre_manta ? h.hora_cierre_manta.substring(0, 5) : '13:00';
            const aperturaTarde = h.hora_apertura_tarde ? h.hora_apertura_tarde.substring(0, 5) : '16:00';

            const esCortado = (h.hora_cierre_manta && h.hora_apertura_tarde && h.hora_cierre_manta !== '' && h.hora_apertura_tarde !== '');

            cont.innerHTML += `
              <div class="bg-slate-950 p-3 rounded-xl border border-slate-800 space-y-2">
                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                  <div class="flex items-center gap-2">
                    <input type="checkbox" name="activo[${i}]" value="1" id="activo_${i}" ${activo ? 'checked' : ''} class="w-4 h-4 accent-emerald-500 rounded cursor-pointer">
                    <label for="activo_${i}" class="font-bold text-white text-xs cursor-pointer">${diasNombres[i]}</label>
                  </div>

                  <div class="flex items-center gap-3 bg-slate-900 px-2.5 py-1 rounded-lg border border-slate-800 text-[11px]">
                    <label class="flex items-center gap-1 cursor-pointer text-slate-300">
                      <input type="radio" name="modo_horario_${i}" id="tipo_corrido_${i}" value="corrido" ${!esCortado ? 'checked' : ''} onchange="toggleModoHorario(${i})" class="accent-emerald-500">
                      Corrido
                    </label>
                    <label class="flex items-center gap-1 cursor-pointer text-slate-300">
                      <input type="radio" name="modo_horario_${i}" id="tipo_cortado_${i}" value="cortado" ${esCortado ? 'checked' : ''} onchange="toggleModoHorario(${i})" class="accent-emerald-500">
                      Cortado
                    </label>
                  </div>
                </div>

                <div id="seccion_corrido_${i}" class="${esCortado ? 'hidden' : ''} grid grid-cols-2 gap-2 pt-1">
                  <div>
                    <label class="block text-[10px] text-slate-400 mb-0.5">Apertura</label>
                    <input type="time" name="apertura[${i}]" value="${apertura}" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2 py-1.5 text-white text-xs focus:outline-none focus:border-emerald-500">
                  </div>
                  <div>
                    <label class="block text-[10px] text-slate-400 mb-0.5">Cierre</label>
                    <input type="time" name="cierre[${i}]" value="${cierre}" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2 py-1.5 text-white text-xs focus:outline-none focus:border-emerald-500">
                  </div>
                </div>

                <div id="seccion_cortado_${i}" class="${!esCortado ? 'hidden' : ''} grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1">
                  <div>
                    <label class="block text-[10px] text-emerald-400 font-semibold mb-0.5">Apertura Mañana</label>
                    <input type="time" name="apertura_cortado[${i}]" value="${apertura}" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2 py-1.5 text-white text-xs focus:outline-none focus:border-emerald-500">
                  </div>
                  <div>
                    <label class="block text-[10px] text-emerald-400 font-semibold mb-0.5">Cierre Mañana</label>
                    <input type="time" name="cierre_manana[${i}]" value="${cierreManana}" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2 py-1.5 text-white text-xs focus:outline-none focus:border-emerald-500">
                  </div>
                  <div>
                    <label class="block text-[10px] text-amber-400 font-semibold mb-0.5">Apertura Tarde</label>
                    <input type="time" name="apertura_tarde[${i}]" value="${aperturaTarde}" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2 py-1.5 text-white text-xs focus:outline-none focus:border-emerald-500">
                  </div>
                  <div>
                    <label class="block text-[10px] text-amber-400 font-semibold mb-0.5">Cierre Tarde</label>
                    <input type="time" name="cierre_tarde[${i}]" value="${cierre}" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2 py-1.5 text-white text-xs focus:outline-none focus:border-emerald-500">
                  </div>
                </div>
              </div>
            `;
          }
        })
        .catch(err => console.error("Error al cargar configuración de horarios:", err));
    }

    function guardarHorarios(e) {
      e.preventDefault();
      var formData = new FormData();
      formData.append('accion', 'guardar_horarios_config');

      for (let i = 0; i < 7; i++) {
        const activo = document.getElementById(`activo_${i}`).checked;
        if (activo) formData.append(`activo[${i}]`, '1');

        const esCortado = document.getElementById(`tipo_cortado_${i}`).checked;

        if (esCortado) {
          const apMa = document.querySelector(`input[name="apertura_cortado[${i}]"]`).value;
          const ciMa = document.querySelector(`input[name="cierre_manana[${i}]"]`).value;
          const apTa = document.querySelector(`input[name="apertura_tarde[${i}]"]`).value;
          const ciTa = document.querySelector(`input[name="cierre_tarde[${i}]"]`).value;

          formData.append(`apertura[${i}]`, apMa);
          formData.append(`cierre_manana[${i}]`, ciMa);
          formData.append(`apertura_tarde[${i}]`, apTa);
          formData.append(`cierre[${i}]`, ciTa);
        } else {
          const ap = document.querySelector(`input[name="apertura[${i}]"]`).value;
          const ci = document.querySelector(`input[name="cierre[${i}]"]`).value;

          formData.append(`apertura[${i}]`, ap);
          formData.append(`cierre[${i}]`, ci);
          formData.append(`cierre_manana[${i}]`, '');
          formData.append(`apertura_tarde[${i}]`, '');
        }
      }

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            alert('Horarios actualizados con éxito.');
            cerrarModal('modalHorarios');
            location.reload();
          } else {
            alert('Error: ' + res.message);
          }
        })
        .catch(err => console.error("Error al guardar horarios:", err));
    }

    function abrirModalServicios() {
      document.getElementById('modalServicios').classList.remove('hidden');
      cargarListaGestionServicios();
    }

    function cargarListaGestionServicios() {
      const cont = document.getElementById('listaServiciosGestion');
      cont.innerHTML = '<p class="text-slate-400 text-center py-2">Cargando servicios...</p>';

      fetch('acciones_panel.php?accion=obtener_servicios')
        .then(res => res.json())
        .then(res => {
          cont.innerHTML = '';
          if (res.status === 'success' && res.data.length > 0) {
            res.data.forEach(s => {
              const hs = Math.floor(s.duracion_min / 60);
              const mins = s.duracion_min % 60;
              let duracionTexto = '';
              if (hs > 0) duracionTexto += `${hs} hs `;
              if (mins > 0 || hs === 0) duracionTexto += `${mins} min`;

              const costoVal = s.costo !== undefined && s.costo !== null ? s.costo : 0;
              const senaVal = s.sena !== undefined && s.sena !== null ? s.sena : 0;

              cont.innerHTML += `
                <div class="flex items-center justify-between bg-slate-950 p-2.5 rounded-xl border border-slate-800">
                  <div>
                    <span class="font-bold text-white block">${s.nombre}</span>
                    <span class="text-[11px] text-slate-400">Precio: $${s.precio} | Costo: $${costoVal} | Seña: $${senaVal} — ${duracionTexto}</span>
                  </div>
                  <div class="flex items-center gap-1">
                    <button onclick="abrirModalEditarServicio(${s.id}, '${encodeURIComponent(s.nombre)}', ${s.precio}, ${costoVal}, ${senaVal}, ${s.duracion_min})" class="text-amber-400 hover:text-amber-300 p-2 rounded-lg hover:bg-amber-500/10 transition" title="Editar Servicio">
                      <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                    <button onclick="eliminarServicio(${s.id})" class="text-red-400 hover:text-red-300 p-2 rounded-lg hover:bg-red-500/10 transition" title="Eliminar Servicio">
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </div>
                </div>
              `;
            });
          } else {
            cont.innerHTML = '<p class="text-slate-400 text-center py-2">No hay servicios guardados.</p>';
          }
        })
        .catch(err => console.error("Error al cargar lista de servicios:", err));
    }

    function abrirModalEditarServicio(id, nombreEncoded, precio, costo, sena, duracionMin) {
      const nombre = decodeURIComponent(nombreEncoded);
      document.getElementById('editServId').value = id;
      document.getElementById('editServNombre').value = nombre;
      document.getElementById('editServPrecio').value = precio;
      document.getElementById('editServCosto').value = costo;
      document.getElementById('editServSena').value = sena;

      const hs = Math.floor(duracionMin / 60);
      const mins = duracionMin % 60;

      document.getElementById('editServHoras').value = hs;
      document.getElementById('editServMinutos').value = mins;

      document.getElementById('modalEditarServicio').classList.remove('hidden');
    }

    function guardarEdicionServicio(e) {
      e.preventDefault();
      var id = document.getElementById('editServId').value;
      var horas = parseInt(document.getElementById('editServHoras').value) || 0;
      var minutos = parseInt(document.getElementById('editServMinutos').value) || 0;

      var duracionTotal = (horas * 60) + minutos;

      if (duracionTotal <= 0) {
        alert('Por favor ingrese una duración válida.');
        return;
      }

      var formData = new FormData();
      formData.append('accion', 'editar_servicio');
      formData.append('servicio_id', id);
      formData.append('nombre', document.getElementById('editServNombre').value);
      formData.append('precio', document.getElementById('editServPrecio').value);
      formData.append('costo', document.getElementById('editServCosto').value);
      formData.append('sena', document.getElementById('editServSena').value);
      formData.append('duracion_min', duracionTotal);

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            cerrarModal('modalEditarServicio');
            cargarListaGestionServicios();
            cargarServicios();
          } else {
            alert('Error: ' + res.message);
          }
        })
        .catch(err => console.error("Error al modificar servicio:", err));
    }

    function guardarNuevoServicio(e) {
      e.preventDefault();
      var horas = parseInt(document.getElementById('servHoras').value) || 0;
      var minutos = parseInt(document.getElementById('servMinutos').value) || 0;

      var duracionTotal = (horas * 60) + minutos;

      if (duracionTotal <= 0) {
        alert('Por favor ingrese una duración válida.');
        return;
      }

      var formData = new FormData();
      formData.append('accion', 'crear_servicio');
      formData.append('nombre', document.getElementById('servNombre').value);
      formData.append('precio', document.getElementById('servPrecio').value);
      formData.append('costo', document.getElementById('servCosto').value);
      formData.append('sena', document.getElementById('servSena').value);
      formData.append('duracion_min', duracionTotal);

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            document.getElementById('formNuevoServicio').reset();
            cargarListaGestionServicios();
            cargarServicios();
          } else {
            alert('Error: ' + res.message);
          }
        })
        .catch(err => console.error("Error al guardar servicio:", err));
    }

    function eliminarServicio(servicioId) {
      if (!confirm('¿Estás seguro de eliminar este servicio?')) return;

      var formData = new FormData();
      formData.append('accion', 'eliminar_servicio');
      formData.append('servicio_id', servicioId);

      fetch('acciones_panel.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            cargarListaGestionServicios();
            cargarServicios();
          } else {
            alert('Error: ' + res.message);
          }
        })
        .catch(err => console.error("Error al eliminar servicio:", err));
    }
  </script>

  <!-- SCRIPT PARA INSTALACIÓN PWA Y SERVICE WORKER -->
  <script>
    let deferredPrompt;

    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('sw.js').then(reg => {
        console.log('Service Worker registrado con éxito:', reg);
      }).catch(err => console.log('SW error:', err));
    }

    const esIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    const esStandalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;

    document.addEventListener('DOMContentLoaded', () => {
      const btnInstalar = document.getElementById('btnInstalarApp');
      if (btnInstalar && !esStandalone) {
        btnInstalar.classList.remove('hidden');
        btnInstalar.classList.add('flex');
      }
    });

    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferredPrompt = e;
      const btnInstalar = document.getElementById('btnInstalarApp');
      if (btnInstalar) {
        btnInstalar.classList.remove('hidden');
        btnInstalar.classList.add('flex');
      }
    });

    async function instalarPWA() {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        if (outcome === 'accepted') {
          console.log('El usuario instaló la PWA');
        }
        deferredPrompt = null;
        document.getElementById('btnInstalarApp').classList.add('hidden');
      } else if (esIOS) {
        alert("Para instalar en iOS: toca 'Compartir' en Safari y selecciona 'Agregar a la pantalla de inicio'.");
      } else {
        alert("Para instalar: toca el menú de opciones de tu navegador y selecciona 'Instalar aplicación'.");
      }
    }

    window.addEventListener('appinstalled', () => {
      const btnInstalar = document.getElementById('btnInstalarApp');
      if (btnInstalar) btnInstalar.classList.add('hidden');
      deferredPrompt = null;
    });
  </script>
</body>
</html>