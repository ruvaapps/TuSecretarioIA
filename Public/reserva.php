<?php
// Configuración de Logging seguro
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/app_errors.log');

try {
    require_once 'conexion.php';
} catch (Exception $e) {
    error_log("[ERROR CONEXION BD] " . $e->getMessage());
    die("<div style='font-family:sans-serif; padding:20px; background:#fee2e2; color:#991b1b; border-radius:8px;'>
            <h2>Error de conexión</h2>
            <p>Ocurrió un problema al conectar con el servicio. Por favor, intenta de nuevo más tarde.</p>
         </div>");
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log("[ERROR RESERVA] La variable \$pdo no está definida o no es una instancia de PDO.");
    die("Página no disponible temporalmente. (Err: C2)");
}

$slug = filter_input(INPUT_GET, 'slug', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (!$slug || trim($slug) === '') {
    die("Página no disponible. Enlace de reserva inválido.");
}

try {
    // Obtener negocio explícitamente desde el slug de la URL
    $stmt =$pdo->prepare("SELECT id, nombre, direccion, localidad, instagram FROM saas_negocios WHERE slug = ?");
    $stmt->execute([$slug]);
    $negocio =$stmt->fetch(PDO::FETCH_ASSOC);

    if (!$negocio) {
        die("El negocio solicitado no existe o no se encuentra disponible.");
    }
    $negocio_id     = (int)$negocio['id'];
    $negocio_nombre =$negocio['nombre'] ?? 'Reserva de Turnos';
    $direccion      =$negocio['direccion'] ?? '';
    $localidad      =$negocio['localidad'] ?? '';
    $instagram      =$negocio['instagram'] ?? '';

    // Cargar Servicios Activos vinculados únicamente a este negocio_id
    $stmt_serv =$pdo->prepare("SELECT id, nombre, precio, duracion_minutos FROM saas_servicios WHERE negocio_id = ? AND estado = 'activo' ORDER BY nombre ASC");
    $stmt_serv->execute([$negocio_id]);
    $servicios =$stmt_serv->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("[ERROR RESERVA SQL] ID negocio: {$negocio_id} - Mensaje: " . $e->getMessage());
    die("Ocurrió un error al cargar la información del negocio.");
} catch (Throwable $t) {
    error_log("[ERROR RESERVA SYSTEM] ID negocio: {$negocio_id} - Mensaje: " . $t->getMessage());
    die("Ocurrió un error inesperado.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Reservar Turno - <?php echo htmlspecialchars($negocio_nombre); ?></title>
  
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <meta name="theme-color" content="#0f172a">

  <style>
    /* Forzar visibilidad del icono del calendario en navegadores WebKit/Samsung */
    input[type="date"]::-webkit-calendar-picker-indicator {
      display: block;
      background: transparent;
      bottom: 0;
      color: transparent;
      cursor: pointer;
      height: auto;
      left: 0;
      position: absolute;
      right: 0;
      top: 0;
      width: auto;
    }
    .date-wrapper {
      position: relative;
    }
  </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen flex flex-col justify-between">

  <!-- Header -->
  <header class="bg-slate-950/90 backdrop-blur border-b border-slate-800 sticky top-0 z-40">
    <div class="max-w-lg mx-auto px-4 py-3 flex items-center justify-between">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 bg-emerald-500 rounded-xl flex items-center justify-center text-slate-950 font-bold text-lg shadow-lg shadow-emerald-500/20">
          <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div>
          <h1 class="text-base font-bold text-white leading-tight"><?php echo htmlspecialchars($negocio_nombre); ?></h1>
          <?php if (!empty($direccion)): ?>
            <p class="text-xs text-slate-400 flex items-center gap-1">
              <i class="fa-solid fa-location-dot text-emerald-400 text-[10px]"></i>
              <?php echo htmlspecialchars($direccion . ($localidad ? ", $localidad" : '')); ?>
            </p>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($instagram)): ?>
        <a href="https://instagram.com/<?php echo htmlspecialchars(ltrim($instagram, '@')); ?>" target="_blank" class="text-slate-400 hover:text-emerald-400 text-xl transition">
          <i class="fa-brands fa-instagram"></i>
        </a>
      <?php endif; ?>
    </div>
  </header>

  <!-- Contenido Principal -->
  <main class="max-w-lg mx-auto w-full px-4 py-6 space-y-5">
    
    <!-- Vista de Confirmación Final -->
    <div id="vistaFinalizado" class="hidden bg-slate-950 border border-slate-800 rounded-2xl p-8 text-center space-y-5 shadow-2xl">
      <div class="w-16 h-16 bg-emerald-500/20 text-emerald-400 rounded-full flex items-center justify-center text-3xl mx-auto border border-emerald-500/30">
        <i class="fa-solid fa-circle-check"></i>
      </div>
      <div class="space-y-2">
        <h2 class="text-xl font-bold text-white">¡Reserva Confirmada!</h2>
        <p class="text-xs text-slate-300 leading-relaxed" id="msjFinalizadoDetalle">
          Tu turno ha sido registrado correctamente.
        </p>
      </div>
      <p class="text-[11px] text-slate-500">Ya puedes cerrar esta ventana.</p>
      <button 
        onclick="cerrarVentana()" 
        class="w-full bg-slate-800 text-slate-300 hover:bg-slate-700 font-bold py-2.5 rounded-xl text-xs transition"
      >
        Cerrar página
      </button>
    </div>

    <!-- Formulario de Reserva -->
    <div id="contenedorFormulario" class="space-y-5">
      <div class="text-center space-y-1">
        <h2 class="text-lg font-bold text-white">Reserva tu turno en segundos</h2>
        <p class="text-xs text-slate-400">Completa el formulario para agendar tu cita de forma automática.</p>
      </div>

      <form id="formReservaPublica" onsubmit="confirmarReserva(event)" class="bg-slate-950 border border-slate-800 rounded-2xl p-5 space-y-4 shadow-2xl">

        <!-- ID de Negocio estricto fijado por el slug -->
        <input type="hidden" id="negocioId" value="<?php echo $negocio_id; ?>">

        <!-- Paso 1: Datos Personales -->
        <div class="space-y-3">
          <h3 class="text-xs font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-2">
            <i class="fa-solid fa-user"></i> 1. Tus Datos
          </h3>
          
          <div>
            <label class="block text-xs text-slate-400 mb-1">Nombre y Apellido</label>
            <input type="text" id="inputNombre" required placeholder="Ej: María González" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-emerald-500">
          </div>

          <div>
            <label class="block text-xs text-slate-400 mb-1">Teléfono Móvil (WhatsApp)</label>
            <div class="flex gap-2">
              <select id="codigoPais" onchange="validarTelefonoWhatsApp()" class="bg-slate-900 border border-slate-800 rounded-xl px-2 py-2.5 text-white text-xs focus:outline-none focus:border-emerald-500 w-28 shrink-0">
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
                required
                placeholder="Ej: 3511234567" 
                class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-emerald-500"
              >
            </div>
            <div id="msjValidacionTel" class="mt-1 text-[11px] min-h-[18px]"></div>
          </div>
        </div>

        <div class="border-t border-slate-800"></div>

        <!-- Paso 2: Servicio -->
        <div class="space-y-3">
          <h3 class="text-xs font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-2">
            <i class="fa-solid fa-list-check"></i> 2. Selecciona un Servicio
          </h3>

          <?php if (empty($servicios)): ?>
            <p class="text-xs text-slate-400 text-center py-2">No hay servicios disponibles por el momento.</p>
          <?php else: ?>
            <div>
              <select id="selectServicio" onchange="cargarHorariosDisponibles()" required class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-emerald-500">
                <option value="">-- Elige un servicio --</option>
                <?php foreach ($servicios as $s):$duracion = $s['duracion_minutos'] ?? $s['duracion_min'] ?? 0;
                  $hs = floor($duracion / 60);
                  $mins =$duracion % 60;
                  $duracionTexto = ($hs > 0 ? "{$hs}h " : '') . ($mins > 0 || $hs === 0 ? "{$mins}min" : '');
                ?>
                  <option value="<?php echo $s['id']; ?>">
                    <?php echo htmlspecialchars($s['nombre']); ?> -$<?php echo number_format($s['precio'], 2, ',', '.'); ?> (<?php echo$duracionTexto; ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
        </div>

        <div class="border-t border-slate-800"></div>

        <!-- Paso 3: Fecha y Hora -->
        <div class="space-y-3">
          <h3 class="text-xs font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-2">
            <i class="fa-solid fa-clock"></i> 3. Fecha y Hora
          </h3>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="date-wrapper">
              <label class="block text-xs text-slate-400 mb-1">Fecha</label>
              <input 
                type="date" 
                id="inputFecha" 
                min="<?php echo date('Y-m-d'); ?>" 
                value="<?php echo date('Y-m-d'); ?>" 
                onchange="cargarHorariosDisponibles()" 
                onclick="abrirCalendario(this)"
                required 
                class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-emerald-500 cursor-pointer"
              >
            </div>

            <div>
              <label class="block text-xs text-slate-400 mb-1">Hora Disponible</label>
              <select id="selectHora" required class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-emerald-500">
                <option value="">Elegí servicio y fecha...</option>
              </select>
            </div>
          </div>
        </div>

        <button 
          type="submit" 
          id="btnConfirmar"
          class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold py-3 rounded-xl text-xs transition shadow-lg shadow-emerald-500/20 mt-4 flex items-center justify-center gap-2"
        >
          <i class="fa-solid fa-check-circle text-base"></i> Confirmar Reserva
        </button>

      </form>
    </div>
  </main>

  <!-- Footer -->
  <footer class="text-center py-4 text-[11px] text-slate-500 border-t border-slate-800/60">
    Desarrollado con <i class="fa-solid fa-heart text-red-500"></i> por Secretario IA
  </footer>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      validarTelefonoWhatsApp();
    });

    function abrirCalendario(element) {
      if ('showPicker' in HTMLInputElement.prototype) {
        try {
          element.showPicker();
        } catch (e) {
          console.log("showPicker no soportado en esta interacción", e);
        }
      }
    }

    function validarTelefonoWhatsApp() {
      const codPais = document.getElementById('codigoPais').value;
      let rawTel = document.getElementById('inputTelefono').value.replace(/[^0-9]/g, '');
      const msjBox = document.getElementById('msjValidacionTel');

      if (!rawTel) {
        msjBox.innerHTML = `<span class="text-slate-500">Ingresa tu número de WhatsApp para mandarte recordatorios.</span>`;
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
        msjBox.innerHTML = `<span class="text-emerald-400 font-semibold"><i class="fa-brands fa-whatsapp"></i> Número válido</span>`;
      } else {
        msjBox.innerHTML = `<span class="text-amber-400 font-semibold"><i class="fa-solid fa-circle-exclamation"></i> Ingrese código de área sin el 0 ni el 15. Ej: 3511234567</span>`;
      }

      return { esCelular, numeroCompleto: numeroLimpio };
    }

    function cargarHorariosDisponibles() {
      const negocioId = document.getElementById('negocioId').value;
      const fecha = document.getElementById('inputFecha').value;
      const servicioId = document.getElementById('selectServicio').value;
      const selectHora = document.getElementById('selectHora');

      if (!fecha || !servicioId) {
        selectHora.innerHTML = '<option value="">Elegí servicio y fecha</option>';
        return;
      }

      selectHora.innerHTML = '<option value="">Cargando horarios...</option>';

      // Sin recurso_id para que el backend asigne de forma automática el recurso disponible
      fetch(`acciones_panel.php?accion=obtener_horarios_disponibles&fecha=${fecha}&servicio_id=${servicioId}&negocio_id=${negocioId}`)
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

    function confirmarReserva(e) {
      e.preventDefault();

      const hora = document.getElementById('selectHora').value;
      if (!hora) {
        alert('Por favor selecciona una hora disponible.');
        return;
      }

      const validacionTel = validarTelefonoWhatsApp();
      if (!validacionTel.esCelular) {
        alert('Por favor ingresa un número de celular válido.');
        return;
      }

      const btn = document.getElementById('btnConfirmar');
      btn.disabled = true;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Guardando reserva...`;

      const formData = new FormData();
      formData.append('accion', 'crear_turno_publico');
      formData.append('negocio_id', document.getElementById('negocioId').value);
      formData.append('cliente_nombre', document.getElementById('inputNombre').value);
      formData.append('cliente_telefono', validacionTel.numeroCompleto);
      formData.append('servicio_id', document.getElementById('selectServicio').value);
      formData.append('fecha', document.getElementById('inputFecha').value);
      formData.append('hora', hora);

      fetch('acciones_panel.php', { method: 'POST', body: formData })
      .then(res => res.json())
      .then(res => {
        btn.disabled = false;
        btn.innerHTML = `<i class="fa-solid fa-check-circle text-base"></i> Confirmar Reserva`;

        if (res.status === 'success') {
          document.getElementById('contenedorFormulario').classList.add('hidden');
          
          if (res.requiere_sena && res.link_pago) {
            document.getElementById('msjFinalizadoDetalle').innerHTML = 
              `Tu turno fue registrado. Para confirmarlo, debés abonar la seña desde el siguiente enlace que también te enviamos por WhatsApp:<br><br>` +
              `<a href="${res.link_pago}" target="_blank" class="inline-block bg-emerald-500 text-slate-950 font-bold px-4 py-2 rounded-xl">Pagar Seña</a>`;
          } else {
            document.getElementById('msjFinalizadoDetalle').innerText = 
              `Tu turno fue agendado con éxito para el día ${document.getElementById('inputFecha').value} a las ${hora} hs.`;
          }
          
          document.getElementById('vistaFinalizado').classList.remove('hidden');
        } else {
          alert('Error: ' + res.message);
        }
      })
      .catch(err => {
        btn.disabled = false;
        btn.innerHTML = `<i class="fa-solid fa-check-circle text-base"></i> Confirmar Reserva`;
        alert('Ocurrió un error al procesar la reserva.');
      });
    }

    function cerrarVentana() {
      window.close();
      setTimeout(() => {
        window.location.href = "about:blank";
      }, 300);
    }
  </script>
</body>
</html>