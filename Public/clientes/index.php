<?php
session_start();

if (!isset($_SESSION['negocio_id'])) {
    header('Location: ../login_negocio.php?redirect=clientes/index.php');
    exit;
}

$negocio_id     = $_SESSION['negocio_id'];
$negocio_nombre =$_SESSION['negocio_nombre'] ?? 'Mi Negocio';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Clientes & Promociones - <?php echo htmlspecialchars($negocio_nombre); ?></title>
  
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- FontAwesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <style>
    .badge-tag { background-color: #334155; color: #cbd5e1; font-weight: 500; padding: 2px 8px; border-radius: 6px; font-size: 11px; margin-right: 4px; }
    .preview-whatsapp { background-color: #0b141a; border: 1px solid #1f2937; border-radius: 12px; padding: 14px; color: #e9edef; font-size: 13px; line-height: 1.5; white-space: pre-wrap; }
  </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen select-none pb-20">

  <!-- Header Fijo -->
  <header class="bg-slate-950/90 backdrop-blur border-b border-slate-800 sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
      <div class="flex items-center space-x-3">
        <a href="../panel_negocio.php" class="w-9 h-9 bg-emerald-500 rounded-xl flex items-center justify-center text-slate-950 font-bold text-base shadow-lg shadow-emerald-500/20 hover:bg-emerald-400 transition" title="Volver al Panel">
          <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
          <h1 class="text-sm font-bold text-white leading-tight"><?php echo htmlspecialchars($negocio_nombre); ?></h1>
          <p class="text-[10px] text-emerald-400 font-medium">Gestión de Clientes & Promociones</p>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <a href="../panel_negocio.php" class="bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 px-3 py-1.5 rounded-xl text-xs font-semibold transition flex items-center gap-2">
          <i class="fa-solid fa-calendar-days text-emerald-400"></i>
          <span class="hidden sm:inline">Panel Turnos</span>
        </a>
        <button onclick="limpiarFormularioCliente(); abrirModal('modalCliente')" class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold px-3 py-1.5 rounded-xl text-xs transition flex items-center gap-2 shadow-lg shadow-emerald-500/20">
          <i class="fa-solid fa-user-plus"></i>
          <span class="hidden sm:inline">Nuevo Cliente</span>
        </button>
      </div>
    </div>
  </header>

  <main class="max-w-7xl mx-auto px-2 sm:px-4 py-4 space-y-4">

    <!-- Navegación por Pestañas Modulares -->
    <div class="flex bg-slate-950 p-1.5 rounded-2xl border border-slate-800 gap-2">
      <button id="btnTabClientes" onclick="cambiarTab('clientes')" class="flex-1 py-2.5 px-4 rounded-xl text-xs font-bold text-slate-950 bg-emerald-500 transition flex items-center justify-center gap-2">
        <i class="fa-solid fa-address-book"></i> Mis Clientes
      </button>
      <button id="btnTabPromos" onclick="cambiarTab('promos'); cargarCampanas();" class="flex-1 py-2.5 px-4 rounded-xl text-xs font-bold text-slate-400 hover:text-white hover:bg-slate-900 transition flex items-center justify-center gap-2">
        <i class="fa-solid fa-bullhorn"></i> Campañas y Promos
      </button>
    </div>

    <!-- PESTAÑA 1: LISTADO DE CLIENTES -->
    <div id="content-clientes" class="space-y-4">
      
      <!-- Buscador y Filtros -->
      <div class="bg-slate-950 p-3 sm:p-4 rounded-2xl border border-slate-800 flex flex-col md:flex-row gap-3 justify-between items-stretch md:items-center shadow-xl">
        <div class="relative flex-1">
          <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-3 text-slate-500 text-xs"></i>
          <input type="text" id="buscarCliente" class="w-full bg-slate-900 border border-slate-800 rounded-xl pl-9 pr-3 py-2 text-xs text-white focus:outline-none focus:border-emerald-500" placeholder="Buscar por nombre o WhatsApp..." onkeyup="filtrarTablaLocal()">
        </div>
        <div class="flex items-center gap-1.5 bg-slate-900 p-1 rounded-xl border border-slate-800 self-center">
          <button type="button" class="btn-filtro-cliente active px-3 py-1.5 rounded-lg text-[11px] font-semibold bg-emerald-500 text-slate-950 transition" onclick="cargarClientes('todos', this)">Todos</button>
          <button type="button" class="btn-filtro-cliente px-3 py-1.5 rounded-lg text-[11px] font-semibold text-slate-400 hover:text-white transition" onclick="cargarClientes('inactivos', this)">Inactivos (+30 días)</button>
          <button type="button" class="btn-filtro-cliente px-3 py-1.5 rounded-lg text-[11px] font-semibold text-slate-400 hover:text-white transition" onclick="cargarClientes('cumpleanios', this)">Cumpleañeros del Mes</button>
        </div>
      </div>

      <!-- Tabla de Clientes -->
      <div class="bg-slate-950 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-slate-300">
            <thead class="bg-slate-900 text-slate-400 border-b border-slate-800 uppercase text-[10px] tracking-wider">
              <tr>
                <th class="p-3 sm:p-4">Cliente</th>
                <th class="p-3 sm:p-4">WhatsApp</th>
                <th class="p-3 sm:p-4">Última Visita</th>
                <th class="p-3 sm:p-4">Turnos</th>
                <th class="p-3 sm:p-4">Etiquetas / Notas</th>
                <th class="p-3 sm:p-4 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody id="tbodyClientes" class="divide-y divide-slate-800/60">
              <tr><td colspan="6" class="text-center py-8 text-slate-500"><i class="fa-solid fa-circle-notch fa-spin text-emerald-400 text-xl mb-2 block"></i>Cargando clientes...</td></tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <!-- PESTAÑA 2: CAMPAÑAS Y PROMOS -->
    <div id="content-promos" class="space-y-4 hidden">
      
      <!-- Sub pestañas -->
      <div class="flex border-b border-slate-800 gap-4 text-xs font-semibold">
        <button id="btnSubtabNueva" onclick="cambiarSubtab('nueva')" class="pb-2 border-b-2 border-emerald-400 text-emerald-400 transition flex items-center gap-1.5">
          <i class="fa-solid fa-plus"></i> Nueva Campaña
        </button>
        <button id="btnSubtabMonitor" onclick="cambiarSubtab('monitor'); cargarCampanas();" class="pb-2 border-b-2 border-transparent text-slate-400 hover:text-white transition flex items-center gap-1.5">
          <i class="fa-solid fa-chart-line"></i> Monitor de Campañas
        </button>
      </div>

      <!-- SUB-PESTAÑA A: NUEVA CAMPAÑA -->
      <div id="content-sub-nueva" class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        
        <div class="lg:col-span-7">
          <div class="bg-slate-950 border border-slate-800 rounded-2xl p-4 sm:p-5 shadow-2xl space-y-4">
            <div class="border-b border-slate-800 pb-3 flex items-center gap-2">
              <i class="fa-solid fa-paper-plane text-emerald-400"></i>
              <h3 class="font-bold text-white text-sm">Crear Nueva Campaña de WhatsApp</h3>
            </div>

            <form id="formCampana" onsubmit="guardarCampana(event)" class="space-y-4 text-xs">
              
              <!-- Reutilizar Plantilla -->
              <div class="bg-slate-900/80 p-3 rounded-xl border border-slate-800 space-y-1">
                <label class="block text-emerald-400 font-semibold flex items-center gap-1.5">
                  <i class="fa-solid fa-copy"></i> Reutilizar campaña anterior
                </label>
                <select id="selectPlantillas" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500" onchange="cargarPlantillaSeleccionada(this.value)">
                  <option value="">-- Seleccionar una campaña existente --</option>
                </select>
              </div>

              <div>
                <label class="block text-slate-400 mb-1 font-semibold">Título de la Campaña (Uso Interno)</label>
                <input type="text" id="campanaTitulo" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500" placeholder="Ej: Promo Día de la Madre - 15% OFF" required>
              </div>

              <div>
                <label class="block text-slate-400 mb-1 font-semibold">Público Destinatario</label>
                <select id="campanaFiltro" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500" required onchange="toggleSeleccionClientes(this.value)">
                  <option value="todos">Todos los clientes</option>
                  <option value="inactivos">Clientes inactivos (+30 días)</option>
                  <option value="cumpleanios">Cumpleañeros de este mes</option>
                  <option value="manual">🎯 Seleccionar clientes individualmente</option>
                </select>
              </div>

              <!-- Lista de Selección Manual de Clientes (Oculta por defecto) -->
              <div id="contenedorClientesManual" class="hidden space-y-2 bg-slate-900/90 p-3 rounded-xl border border-slate-800">
                <div class="flex justify-between items-center pb-2 border-b border-slate-800 text-[11px]">
                  <span class="text-slate-300 font-semibold">Clientes seleccionados: <strong id="cantClientesSeleccionados" class="text-emerald-400">0</strong></span>
                  <button type="button" class="text-emerald-400 hover:underline" onclick="toggleTodosClientesManual()">Seleccionar todos</button>
                </div>
                <input type="text" id="buscarClienteManual" placeholder="Buscar cliente por nombre o teléfono..." onkeyup="filtrarClientesManual()" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-emerald-500">
                
                <div id="listaClientesManual" class="max-h-48 overflow-y-auto space-y-1 pr-1">
                  <!-- Se genera dinámicamente mediante JS -->
                </div>
              </div>

              <div>
                <label class="block text-slate-400 mb-1 font-semibold">Detalle de la Promoción</label>
                <textarea id="campanaMensaje" rows="4" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 resize-none" placeholder="15% de descuento en todos nuestros lavados por el día de la madre..." required onkeyup="actualizarPreview()"></textarea>
              </div>

              <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold py-3 rounded-xl text-xs transition shadow-lg shadow-emerald-500/20 flex items-center justify-center gap-2">
                <i class="fa-solid fa-rocket"></i> Lanzar Campaña por WhatsApp
              </button>
            </form>
          </div>
        </div>

        <!-- Previsualización -->
        <div class="lg:col-span-5">
          <div class="bg-slate-950 border border-slate-800 rounded-2xl p-4 sm:p-5 shadow-2xl space-y-3">
            <div class="border-b border-slate-800 pb-3 flex items-center gap-2">
              <i class="fa-brands fa-whatsapp text-emerald-400"></i>
              <h3 class="font-bold text-white text-sm">Vista Previa en Celular</h3>
            </div>
            
            <div class="preview-whatsapp shadow-inner" id="whatsappPreview">
              ¡Hola <strong>Camila</strong>! 🎁 Queríamos contarte que en <strong><?php echo htmlspecialchars($negocio_nombre); ?></strong> tenemos una promoción especial para vos: 15% de descuento en todos nuestros lavados por el día de la madre... ¡Te esperamos!
            </div>
          </div>
        </div>

      </div>

      <!-- SUB-PESTAÑA B: MONITOR DE CAMPAÑAS -->
      <div id="content-sub-monitor" class="space-y-4 hidden">
        <div class="bg-slate-950 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden">
          <div class="p-4 border-b border-slate-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-sm flex items-center gap-2">
              <i class="fa-solid fa-list-check text-emerald-400"></i> Historial y Progreso de Envíos
            </h3>
            <button onclick="cargarCampanas()" class="bg-slate-900 hover:bg-slate-800 text-slate-300 border border-slate-800 px-3 py-1.5 rounded-xl text-xs transition flex items-center gap-1.5">
              <i class="fa-solid fa-rotate"></i> Actualizar
            </button>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
              <thead class="bg-slate-900 text-slate-400 border-b border-slate-800 uppercase text-[10px] tracking-wider">
                <tr>
                  <th class="p-3 sm:p-4">Campaña</th>
                  <th class="p-3 sm:p-4">Filtro</th>
                  <th class="p-3 sm:p-4">Progreso / Envíos</th>
                  <th class="p-3 sm:p-4">Estado</th>
                  <th class="p-3 sm:p-4">Fecha</th>
                  <th class="p-3 sm:p-4 text-right">Acciones</th>
                </tr>
              </thead>
              <tbody id="tbodyCampanas" class="divide-y divide-slate-800/60">
                <tr><td colspan="6" class="text-center py-8 text-slate-500"><i class="fa-solid fa-circle-notch fa-spin text-emerald-400 text-xl mb-2 block"></i>Cargando campañas...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>

  </main>

  <!-- MODAL: CREAR / EDITAR CLIENTE -->
  <div id="modalCliente" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-5 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2" id="modalClienteTitulo">
          <i class="fa-solid fa-user-pen text-emerald-400"></i> Nuevo Cliente
        </h3>
        <button onclick="cerrarModal('modalCliente')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>

      <form id="formCliente" onsubmit="guardarCliente(event)" class="space-y-3 text-xs">
        <input type="hidden" id="clienteId">
        
        <div>
          <label class="block text-slate-400 mb-1 font-semibold">Nombre Completo</label>
          <input type="text" id="clienteNombre" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500" placeholder="Ej: Camila Roldán">
        </div>

        <div>
          <label class="block text-slate-400 mb-1 font-semibold">Teléfono / WhatsApp *</label>
          <div class="flex gap-2">
            <select id="codigoPaisCliente" onchange="validarTelefonoCliente()" class="bg-slate-950 border border-slate-800 rounded-xl px-2 py-2 text-white text-xs focus:outline-none focus:border-emerald-500 w-28 shrink-0">
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
              id="clienteTelefono" 
              required
              oninput="this.value = this.value.replace(/[^0-9]/g, ''); validarTelefonoCliente();" 
              placeholder="Ej: 3511234567" 
              class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
            >
          </div>
          <div id="msjValidacionClienteTel" class="mt-1 text-[11px] min-h-[18px]"></div>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-slate-400 mb-1 font-semibold">Email</label>
            <input type="email" id="clienteEmail" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>
          <div>
            <label class="block text-slate-400 mb-1 font-semibold">Fecha Nacimiento</label>
            <input type="date" id="clienteFechaNac" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500">
          </div>
        </div>

        <div>
          <label class="block text-slate-400 mb-1 font-semibold">Etiquetas (Separadas por coma)</label>
          <input type="text" id="clienteEtiquetas" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500" placeholder="VIP, Lavado, Promo2026">
        </div>

        <div>
          <label class="block text-slate-400 mb-1 font-semibold">Notas / Observaciones</label>
          <textarea id="clienteNotas" rows="2" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 resize-none"></textarea>
        </div>

        <div class="flex gap-2 pt-2 border-t border-slate-800">
          <button type="button" onclick="cerrarModal('modalCliente')" class="flex-1 bg-slate-800 text-slate-300 font-semibold py-2.5 rounded-xl text-xs hover:bg-slate-700 transition">
            Cancelar
          </button>
          <button type="submit" class="flex-1 bg-emerald-500 text-slate-950 font-bold py-2.5 rounded-xl text-xs hover:bg-emerald-400 transition">
            Guardar Cliente
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: DETALLE Y DETALLE DE ENVÍOS DE CAMPAÑA -->
  <div id="modalDetalleCampana" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-2xl rounded-2xl p-5 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex justify-between items-center border-b border-slate-800 pb-3">
        <h3 class="font-bold text-white text-base flex items-center gap-2">
          <i class="fa-solid fa-circle-info text-emerald-400"></i> Detalle de Envíos
        </h3>
        <button onclick="cerrarModal('modalDetalleCampana')" class="text-slate-400 hover:text-white text-lg">&times;</button>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-300">
          <thead class="bg-slate-950 text-slate-400 border-b border-slate-800 uppercase text-[10px] tracking-wider">
            <tr>
              <th class="p-3">Cliente</th>
              <th class="p-3">WhatsApp</th>
              <th class="p-3">Estado</th>
              <th class="p-3">Fecha Envío</th>
              <th class="p-3">Detalle</th>
            </tr>
          </thead>
          <tbody id="tbodyDetalleCampana" class="divide-y divide-slate-800/60">
            <tr><td colspan="5" class="text-center py-6 text-slate-500">Cargando envíos...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
  let clientesData = [];
  let campanasData = [];
  const negocioNombreActual = <?php echo json_encode($negocio_nombre); ?>;

  document.addEventListener('DOMContentLoaded', () => {
      cargarClientes('todos');
  });

  // Control de Modales
  function abrirModal(id) {
      document.getElementById(id).classList.remove('hidden');
  }

  function cerrarModal(id) {
      document.getElementById(id).classList.add('hidden');
  }

  // Cambio de Pestañas Principales
  function cambiarTab(tab) {
      const btnC = document.getElementById('btnTabClientes');
      const btnP = document.getElementById('btnTabPromos');
      const contC = document.getElementById('content-clientes');
      const contP = document.getElementById('content-promos');

      if (tab === 'clientes') {
          btnC.className = "flex-1 py-2.5 px-4 rounded-xl text-xs font-bold text-slate-950 bg-emerald-500 transition flex items-center justify-center gap-2";
          btnP.className = "flex-1 py-2.5 px-4 rounded-xl text-xs font-bold text-slate-400 hover:text-white hover:bg-slate-900 transition flex items-center justify-center gap-2";
          contC.classList.remove('hidden');
          contP.classList.add('hidden');
      } else {
          btnP.className = "flex-1 py-2.5 px-4 rounded-xl text-xs font-bold text-slate-950 bg-emerald-500 transition flex items-center justify-center gap-2";
          btnC.className = "flex-1 py-2.5 px-4 rounded-xl text-xs font-bold text-slate-400 hover:text-white hover:bg-slate-900 transition flex items-center justify-center gap-2";
          contP.classList.remove('hidden');
          contC.classList.add('hidden');
      }
  }

  // Cambio de Subpestañas Promos
  function cambiarSubtab(sub) {
      const btnN = document.getElementById('btnSubtabNueva');
      const btnM = document.getElementById('btnSubtabMonitor');
      const contN = document.getElementById('content-sub-nueva');
      const contM = document.getElementById('content-sub-monitor');

      if (sub === 'nueva') {
          btnN.className = "pb-2 border-b-2 border-emerald-400 text-emerald-400 transition flex items-center gap-1.5";
          btnM.className = "pb-2 border-b-2 border-transparent text-slate-400 hover:text-white transition flex items-center gap-1.5";
          contN.classList.remove('hidden');
          contM.classList.add('hidden');
      } else {
          btnM.className = "pb-2 border-b-2 border-emerald-400 text-emerald-400 transition flex items-center gap-1.5";
          btnN.className = "pb-2 border-b-2 border-transparent text-slate-400 hover:text-white transition flex items-center gap-1.5";
          contM.classList.remove('hidden');
          contN.classList.add('hidden');
      }
  }

  // Selección manual de clientes para campañas
  function toggleSeleccionClientes(val) {
      const cont = document.getElementById('contenedorClientesManual');
      if (val === 'manual') {
          cont.classList.remove('hidden');
          renderizarListaManual();
      } else {
          cont.classList.add('hidden');
      }
  }

  function renderizarListaManual() {
      const div = document.getElementById('listaClientesManual');
      if (!clientesData || clientesData.length === 0) {
          div.innerHTML = '<p class="text-slate-500 text-center py-2">Sin clientes disponibles.</p>';
          return;
      }

      div.innerHTML = clientesData.map(c => `
          <label class="flex items-center justify-between p-1.5 hover:bg-slate-800/60 rounded-lg cursor-pointer text-xs text-slate-200 item-cliente-manual">
              <div class="flex items-center gap-2">
                  <input type="checkbox" value="${c.id}" class="chk-cliente-manual accent-emerald-500 rounded" onchange="actualizarContadorClientesManual()">
                  <span>${c.nombre || 'Sin nombre'}</span>
              </div>
              <span class="text-slate-500 text-[11px]">${c.telefono}</span>
          </label>
      `).join('');

      actualizarContadorClientesManual();
  }

  function filtrarClientesManual() {
      const q = document.getElementById('buscarClienteManual').value.toLowerCase();
      document.querySelectorAll('.item-cliente-manual').forEach(el => {
          const text = el.innerText.toLowerCase();
          el.style.display = text.includes(q) ? 'flex' : 'none';
      });
  }

  function actualizarContadorClientesManual() {
      const marcados = document.querySelectorAll('.chk-cliente-manual:checked').length;
      document.getElementById('cantClientesSeleccionados').innerText = marcados;
  }

  function toggleTodosClientesManual() {
      const chks = document.querySelectorAll('.chk-cliente-manual');
      const todosMarcados = Array.from(chks).length > 0 && Array.from(chks).every(c => c.checked);
      chks.forEach(c => c.checked = !todosMarcados);
      actualizarContadorClientesManual();
  }

  // Validación y Normalización de Teléfono
  function validarTelefonoCliente() {
      const codPais = document.getElementById('codigoPaisCliente').value;
      let rawTel = document.getElementById('clienteTelefono').value.replace(/[^0-9]/g, '');
      const msjBox = document.getElementById('msjValidacionClienteTel');

      if (!rawTel) {
          msjBox.innerHTML = `<span class="text-amber-400 font-semibold"><i class="fa-solid fa-triangle-exclamation"></i> Ingrese el número sin el 0 ni el 15.</span>`;
          return { esValido: false, numeroCompleto: '' };
      }

      let esValido = false;
      let numeroLimpio = rawTel;

      if (codPais === '54') {
          if (numeroLimpio.startsWith('0')) {
              numeroLimpio = numeroLimpio.substring(1);
          }
          if (numeroLimpio.startsWith('15')) {
              numeroLimpio = numeroLimpio.substring(2);
          }

          if (numeroLimpio.length === 10) {
              esValido = true;
              numeroLimpio = '549' + numeroLimpio;
          } else {
              numeroLimpio = '54' + numeroLimpio;
          }
      } else {
          if (numeroLimpio.length >= 8 && numeroLimpio.length <= 13) {
              esValido = true;
          }
          numeroLimpio = codPais + numeroLimpio;
      }

      if (esValido) {
          msjBox.innerHTML = `<span class="text-emerald-400 font-semibold"><i class="fa-brands fa-whatsapp"></i> Formato válido: +${numeroLimpio}</span>`;
      } else {
          msjBox.innerHTML = `<span class="text-amber-400 font-semibold"><i class="fa-solid fa-circle-exclamation"></i> Código de área + número sin 0 ni 15. Ej: <strong>3511234567</strong></span>`;
      }

      return { esValido, numeroCompleto: numeroLimpio };
  }

  // Cargar Clientes desde el backend
  function cargarClientes(filtro = 'todos', btn = null) {
      if (btn) {
          document.querySelectorAll('.btn-filtro-cliente').forEach(b => {
              b.className = "btn-filtro-cliente px-3 py-1.5 rounded-lg text-[11px] font-semibold text-slate-400 hover:text-white transition";
          });
          btn.className = "btn-filtro-cliente active px-3 py-1.5 rounded-lg text-[11px] font-semibold bg-emerald-500 text-slate-950 transition";
      }

      const tbody = document.getElementById('tbodyClientes');
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-8 text-slate-500"><i class="fa-solid fa-circle-notch fa-spin text-emerald-400 text-xl mb-2 block"></i>Cargando clientes...</td></tr>';

      fetch(`api_clientes.php?action=listar&filtro=${filtro}`)
          .then(res => res.json())
          .then(res => {
              if (res.status === 'success') {
                  clientesData = res.data;
                  renderizarTabla(clientesData);
              } else {
                  tbody.innerHTML = `<tr><td colspan="6" class="text-center text-red-400 py-6">${res.message}</td></tr>`;
              }
          });
  }

  function renderizarTabla(lista) {
      const tbody = document.getElementById('tbodyClientes');
      if (lista.length === 0) {
          tbody.innerHTML = '<tr><td colspan="6" class="text-center text-slate-500 py-6">No se encontraron clientes.</td></tr>';
          return;
      }

      tbody.innerHTML = lista.map(c => {
          const tags = c.etiquetas ? c.etiquetas.split(',').map(t => `<span class="badge-tag">${t.trim()}</span>`).join('') : '';
          const ultimaVisita = c.ultima_visita ? new Date(c.ultima_visita).toLocaleDateString() : 'Sin visitas';
          
          return `
              <tr class="hover:bg-slate-900/60 transition">
                  <td class="p-3 sm:p-4 font-semibold text-white">${c.nombre || 'Sin nombre'}</td>
                  <td class="p-3 sm:p-4"><a href="https://wa.me/${c.telefono}" target="_blank" class="text-emerald-400 hover:underline font-medium"><i class="fa-brands fa-whatsapp me-1"></i>${c.telefono}</a></td>
                  <td class="p-3 sm:p-4 text-slate-400">${ultimaVisita}</td>
                  <td class="p-3 sm:p-4"><span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2 py-0.5 rounded-md font-semibold">${c.total_turnos} turnos</span></td>
                  <td class="p-3 sm:p-4">${tags}</td>
                  <td class="p-3 sm:p-4 text-right">
                      <button class="text-amber-400 hover:text-amber-300 p-1.5 rounded-lg hover:bg-amber-500/10 transition" title="Editar Cliente" onclick="editarCliente(${c.id})"><i class="fa-solid fa-pen"></i></button>
                  </td>
              </tr>
          `;
      }).join('');
  }

  function filtrarTablaLocal() {
      const query = document.getElementById('buscarCliente').value.toLowerCase();
      const filtrados = clientesData.filter(c => 
          (c.nombre && c.nombre.toLowerCase().includes(query)) || 
          c.telefono.includes(query)
      );
      renderizarTabla(filtrados);
  }

  function guardarCliente(e) {
      e.preventDefault();
      
      const valTel = validarTelefonoCliente();
      if (!valTel.numeroCompleto) {
          alert('Por favor verifique el número de teléfono ingresado.');
          return;
      }

      const payload = {
          id: document.getElementById('clienteId').value,
          nombre: document.getElementById('clienteNombre').value,
          telefono: valTel.numeroCompleto,
          email: document.getElementById('clienteEmail').value,
          fecha_nacimiento: document.getElementById('clienteFechaNac').value,
          etiquetas: document.getElementById('clienteEtiquetas').value,
          notas: document.getElementById('clienteNotas').value
      };

      fetch('api_clientes.php?action=guardar', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(res => {
          if (res.status === 'success') {
              cerrarModal('modalCliente');
              cargarClientes('todos');
          } else {
              alert(res.message);
          }
      });
  }

  function editarCliente(id) {
      const c = clientesData.find(item => item.id == id);
      if (!c) return;

      document.getElementById('clienteId').value = c.id;
      document.getElementById('clienteNombre').value = c.nombre || '';
      
      let telLimpio = c.telefono || '';
      if (telLimpio.startsWith('549') && telLimpio.length === 13) {
          document.getElementById('codigoPaisCliente').value = '54';
          telLimpio = telLimpio.substring(3);
      } else if (telLimpio.startsWith('54') && telLimpio.length === 12) {
          document.getElementById('codigoPaisCliente').value = '54';
          telLimpio = telLimpio.substring(2);
      }
      
      document.getElementById('clienteTelefono').value = telLimpio;
      validarTelefonoCliente();

      document.getElementById('clienteEmail').value = c.email || '';
      document.getElementById('clienteFechaNac').value = c.fecha_nacimiento || '';
      document.getElementById('clienteEtiquetas').value = c.etiquetas || '';
      document.getElementById('clienteNotas').value = c.notas || '';

      document.getElementById('modalClienteTitulo').innerHTML = '<i class="fa-solid fa-user-pen text-emerald-400 me-2"></i>Editar Cliente';
      abrirModal('modalCliente');
  }

  function limpiarFormularioCliente() {
      document.getElementById('formCliente').reset();
      document.getElementById('clienteId').value = '';
      document.getElementById('codigoPaisCliente').value = '54';
      document.getElementById('msjValidacionClienteTel').innerHTML = '';
      document.getElementById('modalClienteTitulo').innerHTML = '<i class="fa-solid fa-user-plus text-emerald-400 me-2"></i>Nuevo Cliente';
  }

  function actualizarPreview() {
      const msg = document.getElementById('campanaMensaje').value;
      document.getElementById('whatsappPreview').innerHTML = 
          `¡Hola <strong>Camila</strong>! 🎁 Queríamos contarte que en <strong>${negocioNombreActual}</strong> tenemos una promoción especial para vos: ${msg} ¡Te esperamos!`;
  }

  function guardarCampana(e) {
      e.preventDefault();
      const filtro = document.getElementById('campanaFiltro').value;
      
      let clientesIds = [];
      if (filtro === 'manual') {
          clientesIds = Array.from(document.querySelectorAll('.chk-cliente-manual:checked')).map(c => parseInt(c.value));
          if (clientesIds.length === 0) {
              alert('Por favor selecciona al menos un cliente.');
              return;
          }
      }

      const payload = {
          titulo: document.getElementById('campanaTitulo').value,
          filtro: filtro,
          mensaje: document.getElementById('campanaMensaje').value,
          clientes_ids: clientesIds
      };

      fetch('api_campanas.php?action=crear', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(res => {
          alert(res.message);
          if (res.status === 'success') {
              document.getElementById('formCampana').reset();
              document.getElementById('selectPlantillas').value = '';
              document.getElementById('contenedorClientesManual').classList.add('hidden');
              actualizarPreview();
              cargarCampanas();
              cambiarSubtab('monitor');
          }
      });
  }

  // Monitor y Gestión de Campañas
  function cargarCampanas() {
      const tbody = document.getElementById('tbodyCampanas');
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-8 text-slate-500"><i class="fa-solid fa-circle-notch fa-spin text-emerald-400 text-xl mb-2 block"></i>Cargando campañas...</td></tr>';

      fetch('api_campanas.php?action=listar')
          .then(res => res.json())
          .then(res => {
              if (res.status === 'success') {
                  campanasData = res.data;
                  renderizarCampanas(campanasData);
                  poblarSelectPlantillas(campanasData);
              } else {
                  tbody.innerHTML = `<tr><td colspan="6" class="text-center text-red-400 py-6">${res.message}</td></tr>`;
              }
          });
  }

  function renderizarCampanas(lista) {
      const tbody = document.getElementById('tbodyCampanas');
      if (lista.length === 0) {
          tbody.innerHTML = '<tr><td colspan="6" class="text-center text-slate-500 py-6">No hay campañas registradas.</td></tr>';
          return;
      }

      tbody.innerHTML = lista.map(c => {
          const total = parseInt(c.total_destinatarios) || 0;
          const enviados = parseInt(c.total_enviados) || 0;
          const fallidos = parseInt(c.total_fallidos) || 0;
          const porcentaje = total > 0 ? Math.round(((enviados + fallidos) / total) * 100) : 0;

          let badgeEstado = '<span class="bg-slate-800 text-slate-300 px-2 py-0.5 rounded-md font-semibold">Borrador</span>';
          if (c.estado === 'en_proceso') badgeEstado = '<span class="bg-sky-500/10 text-sky-400 border border-sky-500/20 px-2 py-0.5 rounded-md font-semibold"><i class="fa-solid fa-spinner fa-spin me-1"></i>En Proceso</span>';
          if (c.estado === 'completada') badgeEstado = '<span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2 py-0.5 rounded-md font-semibold"><i class="fa-solid fa-check me-1"></i>Completada</span>';
          if (c.estado === 'cancelada') badgeEstado = '<span class="bg-red-500/10 text-red-400 border border-red-500/20 px-2 py-0.5 rounded-md font-semibold">Cancelada</span>';

          const btnCancelar = c.estado === 'en_proceso' 
              ? `<button class="text-red-400 hover:text-red-300 p-1.5 rounded-lg hover:bg-red-500/10 transition" title="Cancelar Campaña" onclick="cambiarEstadoCampana(${c.id}, 'cancelada')"><i class="fa-solid fa-ban"></i></button>` 
              : '';

          return `
              <tr class="hover:bg-slate-900/60 transition">
                  <td class="p-3 sm:p-4 font-semibold text-white">${c.titulo}</td>
                  <td class="p-3 sm:p-4"><span class="bg-slate-900 text-slate-300 border border-slate-800 px-2 py-0.5 rounded-md">${c.filtro_etiqueta}</span></td>
                  <td class="p-3 sm:p-4" style="min-width: 180px;">
                      <div class="flex justify-between text-[11px] mb-1">
                          <span><strong>${enviados}</strong>/${total} enviados</span>
                          <span>${porcentaje}%</span>
                      </div>
                      <div class="w-full bg-slate-900 rounded-full h-1.5 overflow-hidden">
                          <div class="bg-emerald-500 h-1.5" style="width: ${porcentaje}%"></div>
                      </div>
                      ${fallidos > 0 ? `<small class="text-red-400 block mt-1">${fallidos} fallidos</small>` : ''}
                  </td>
                  <td class="p-3 sm:p-4">${badgeEstado}</td>
                  <td class="p-3 sm:p-4 text-slate-400">${new Date(c.creado_el).toLocaleString()}</td>
                  <td class="p-3 sm:p-4 text-right">
                      ${btnCancelar}
                      <button class="text-slate-400 hover:text-white p-1.5 rounded-lg hover:bg-slate-800 transition me-1" title="Reutilizar Plantilla" onclick="clonarCampana(${c.id})"><i class="fa-solid fa-clone"></i></button>
                      <button class="text-emerald-400 hover:text-emerald-300 p-1.5 rounded-lg hover:bg-emerald-500/10 transition" title="Ver Detalle" onclick="verDetalleCampana(${c.id})"><i class="fa-solid fa-eye"></i></button>
                  </td>
              </tr>
          `;
      }).join('');
  }

  function poblarSelectPlantillas(lista) {
      const select = document.getElementById('selectPlantillas');
      select.innerHTML = '<option value="">-- Seleccionar una campaña existente --</option>' + 
          lista.map(c => `<option value="${c.id}">${c.titulo} (${c.filtro_etiqueta})</option>`).join('');
  }

  function cargarPlantillaSeleccionada(id) {
      if (!id) return;
      const c = campanasData.find(item => item.id == id);
      if (!c) return;

      document.getElementById('campanaTitulo').value = c.titulo + ' (Copia)';
      document.getElementById('campanaFiltro').value = c.filtro_etiqueta;
      toggleSeleccionClientes(c.filtro_etiqueta);
      document.getElementById('campanaMensaje').value = c.mensaje;
      actualizarPreview();
  }

  function clonarCampana(id) {
      cargarPlantillaSeleccionada(id);
      cambiarSubtab('nueva');
  }

  function cambiarEstadoCampana(id, nuevoEstado) {
      if (!confirm('¿Seguro que deseas cancelar esta campaña? Los envíos pendientes no se procesarán.')) return;

      fetch('api_campanas.php?action=cambiar_estado', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id, estado: nuevoEstado })
      })
      .then(res => res.json())
      .then(res => {
          if (res.status === 'success') {
              cargarCampanas();
          } else {
              alert(res.message);
          }
      });
  }

  function verDetalleCampana(id) {
      const tbody = document.getElementById('tbodyDetalleCampana');
      tbody.innerHTML = '<tr><td colspan="5" class="text-center py-6 text-slate-500">Cargando envíos...</td></tr>';

      abrirModal('modalDetalleCampana');

      fetch(`api_campanas.php?action=detalle&id=${id}`)
          .then(res => res.json())
          .then(res => {
              if (res.status === 'success') {
                  if (res.data.length === 0) {
                      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-slate-500 py-6">No hay envíos en esta campaña.</td></tr>';
                      return;
                  }

                  tbody.innerHTML = res.data.map(d => {
                      let st = '<span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2 py-0.5 rounded-md font-semibold">Pendiente</span>';
                      if (d.estado === 'enviado') st = '<span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2 py-0.5 rounded-md font-semibold">Enviado</span>';
                      if (d.estado === 'fallido') st = '<span class="bg-red-500/10 text-red-400 border border-red-500/20 px-2 py-0.5 rounded-md font-semibold">Fallido</span>';

                      return `
                          <tr class="hover:bg-slate-950 transition">
                              <td class="p-3 font-semibold text-white">${d.cliente_nombre || 'Sin nombre'}</td>
                              <td class="p-3">${d.cliente_telefono}</td>
                              <td class="p-3">${st}</td>
                              <td class="p-3 text-slate-400">${d.enviado_el ? new Date(d.enviado_el).toLocaleString() : '-'}</td>
                              <td class="p-3 text-slate-400">${d.error_mensaje || '-'}</td>
                          </tr>
                      `;
                  }).join('');
              }
          });
  }
  </script>
</body>
</html>