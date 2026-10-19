<?php
// admin/index.php
session_start();

// Credenciales
$user_admin = "admin";
$pass_admin = "Lovar2026Secretario!";

if (!isset($_SERVER['PHP_AUTH_USER']) || $_SERVER['PHP_AUTH_USER'] !== $user_admin || $_SERVER['PHP_AUTH_PW'] !== $pass_admin) {
    header('WWW-Authenticate: Basic realm="Panel Secretario IA"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Acceso denegado';
    exit;
}

// Conexión
$host = 'localhost';
$db   = 'admlova_secretario';
$user = 'admlova_secretario';
$pass = 'secretario2026';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

$tab = $_GET['tab'] ?? 'cobros';
$mensaje_estado = null;

// ==========================================
// ACCIÓN: Eliminar Negocio Completamente
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar_negocio') {
    $negocio_id_borrar = intval($_POST['negocio_id_borrar'] ?? 0);
    $confirmacion_nombre = trim($_POST['confirmacion_nombre'] ?? '');

    if ($negocio_id_borrar > 0) {
        // Verificar que el negocio exista
        $stmtCheck = $pdo->prepare("SELECT nombre FROM saas_negocios WHERE id = :id");
        $stmtCheck->execute(['id' => $negocio_id_borrar]);
        $negocio_info = $stmtCheck->fetch();

        if ($negocio_info) {
            if ($confirmacion_nombre === $negocio_info['nombre']) {
                try {
                    $pdo->beginTransaction();

                    // 1. Eliminar de tablas hijas explícitamente para evitar problemas de claves foráneas
                    $pdo->prepare("DELETE FROM cobros_turnos WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);
                    $pdo->prepare("DELETE FROM saas_push_subscriptions WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);
                    $pdo->prepare("DELETE FROM saas_comprobantes_pago WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);
                    $pdo->prepare("DELETE FROM saas_turnos WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);
                    $pdo->prepare("DELETE FROM saas_bloqueos WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);
                    $pdo->prepare("DELETE FROM saas_clientes WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);
                    $pdo->prepare("DELETE FROM saas_servicios WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);
                    $pdo->prepare("DELETE FROM saas_horarios_atencion WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);
                    $pdo->prepare("DELETE FROM saas_configuracion_agenda WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);
                    
                    // Limpiar referencias en sesiones activas si aplica
                    $pdo->prepare("UPDATE saas_clientes_sesiones SET negocio_id = NULL WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);
                    $pdo->prepare("UPDATE saas_negocios_sesiones SET negocio_id = NULL WHERE negocio_id = :id")->execute(['id' => $negocio_id_borrar]);

                    // 2. Eliminar de la tabla principal
                    $pdo->prepare("DELETE FROM saas_negocios WHERE id = :id")->execute(['id' => $negocio_id_borrar]);

                    $pdo->commit();
                    $mensaje_estado = [
                        'tipo' => 'exito',
                        'texto' => "El negocio '{$negocio_info['nombre']}' (ID #{$negocio_id_borrar}) y todos sus datos vinculados fueron eliminados correctamente."
                    ];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $mensaje_estado = [
                        'tipo' => 'error',
                        'texto' => "Error al intentar eliminar el negocio: " . $e->getMessage()
                    ];
                }
            } else {
                $mensaje_estado = [
                    'tipo' => 'error',
                    'texto' => "El nombre de confirmación ingresado no coincide con el del negocio."
                ];
            }
        } else {
            $mensaje_estado = [
                'tipo' => 'error',
                'texto' => "El negocio indicado no fue encontrado."
            ];
        }
    }
}

// Parámetros de filtros generales
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-t');
$negocio_filtro = $_GET['negocio_id'] ?? '';
$estado_filtro = $_GET['estado_turno'] ?? '';

// Obtenemos todos los negocios para los desplegables de filtro
$negocios = $pdo->query("SELECT id, nombre, email, telefono_dueno, estado, creado_el FROM saas_negocios ORDER BY nombre ASC")->fetchAll();

// ==========================================
// 1. Lógica para Pestaña "Liquidación & Cobros"
// ==========================================
$liquidaciones = [];
if ($tab === 'cobros') {
    $stmtCobros = $pdo->prepare("
        SELECT 
            n.id AS negocio_id,
            n.nombre AS negocio_nombre,
            n.telefono_dueno,
            n.email,
            COUNT(ct.id) AS total_turnos,
            SUM(CASE WHEN ct.estado = 'pendiente' THEN ct.monto ELSE 0 END) AS total_pendiente,
            SUM(CASE WHEN ct.estado = 'cobrado' THEN ct.monto ELSE 0 END) AS total_cobrado
        FROM saas_negocios n
        LEFT JOIN cobros_turnos ct ON n.id = ct.negocio_id AND DATE(ct.fecha_registro) BETWEEN :desde AND :hasta
        GROUP BY n.id
    ");
    $stmtCobros->execute(['desde' => $fecha_desde, 'hasta' => $fecha_hasta]);
    $liquidaciones = $stmtCobros->fetchAll();
}

// ==========================================
// 2. Lógica para Pestaña "Reporte de Turnos"
// ==========================================
$turnos = [];
$totalesReporte = ['total' => 0, 'confirmados' => 0, 'cancelados' => 0, 'monto_senas' => 0];

if ($tab === 'turnos') {
    $where = ["DATE(t.fecha_hora_inicio) BETWEEN :desde AND :hasta"];
    $params = ['desde' => $fecha_desde, 'hasta' => $fecha_hasta];

    if (!empty($negocio_filtro)) {
        $where[] = "t.negocio_id = :negocio_id";
        $params['negocio_id'] = $negocio_filtro;
    }

    if (!empty($estado_filtro)) {
        $where[] = "t.estado = :estado";
        $params['estado'] = $estado_filtro;
    }

    $whereSql = implode(" AND ", $where);

    $sqlTurnos = "
        SELECT 
            t.id AS turno_id,
            t.fecha_hora_inicio,
            t.fecha_hora_fin,
            t.estado AS estado_turno,
            t.monto_sena,
            t.metodo_pago,
            t.creado_el,
            n.nombre AS negocio_nombre,
            c.nombre AS cliente_nombre,
            c.telefono AS cliente_telefono,
            s.nombre AS servicio_nombre,
            s.precio AS servicio_precio,
            cp.url_comprobante,
            cp.estado AS estado_comprobante,
            cp.monto_detectado
        FROM saas_turnos t
        JOIN saas_negocios n ON t.negocio_id = n.id
        JOIN saas_clientes c ON t.cliente_id = c.id
        JOIN saas_servicios s ON t.servicio_id = s.id
        LEFT JOIN saas_comprobantes_pago cp ON t.id = cp.turno_id
        WHERE {$whereSql}
        ORDER BY t.fecha_hora_inicio DESC
    ";

    $stmtTurnos = $pdo->prepare($sqlTurnos);
    $stmtTurnos->execute($params);
    $turnos = $stmtTurnos->fetchAll();

    foreach ($turnos as $tur) {
        $totalesReporte['total']++;
        if (in_array($tur['estado_turno'], ['confirmado', 'completado'])) {
            $totalesReporte['confirmados']++;
            $totalesReporte['monto_senas'] += $tur['monto_sena'];
        } elseif ($tur['estado_turno'] === 'cancelado') {
            $totalesReporte['cancelados']++;
        }
    }
}

// ==========================================
// 3. Lógica para Pestaña "Soporte & Negocios"
// ==========================================
$resumenSoporte = [];
if ($tab === 'soporte') {
    $sqlSoporte = "
        SELECT 
            n.id,
            n.nombre,
            n.email,
            n.telefono_dueno,
            n.rubro,
            n.estado,
            n.creado_el,
            (SELECT COUNT(*) FROM saas_turnos WHERE negocio_id = n.id) AS total_turnos,
            (SELECT COUNT(*) FROM saas_clientes WHERE negocio_id = n.id) AS total_clientes,
            (SELECT COUNT(*) FROM saas_servicios WHERE negocio_id = n.id) AS total_servicios
        FROM saas_negocios n
        ORDER BY n.nombre ASC
    ";
    $resumenSoporte = $pdo->query($sqlSoporte)->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control | Secretario IA</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen p-6 font-sans selection:bg-emerald-500 selection:text-slate-950">

    <div class="max-w-7xl mx-auto space-y-6">
        
        <!-- Header & Menú de Navegación -->
        <div class="flex flex-col md:flex-row justify-between md:items-center gap-4 border-b border-slate-800 pb-4">
            <div>
                <h1 class="text-2xl font-bold text-emerald-400">Secretario IA — Módulo de Administración</h1>
                <p class="text-xs text-slate-400">Gestión de liquidaciones, reporte de turnos y soporte técnico SaaS</p>
            </div>
            
            <nav class="flex space-x-2 bg-slate-900 p-1.5 rounded-lg border border-slate-800">
                <a href="?tab=cobros&fecha_desde=<?=$fecha_desde?>&fecha_hasta=<?=$fecha_hasta?>" 
                   class="px-4 py-1.5 text-xs font-semibold rounded-md transition <?= $tab === 'cobros' ? 'bg-emerald-500 text-slate-950' : 'text-slate-400 hover:text-white' ?>">
                    Liquidación & Cobros
                </a>
                <a href="?tab=turnos&fecha_desde=<?=$fecha_desde?>&fecha_hasta=<?=$fecha_hasta?>" 
                   class="px-4 py-1.5 text-xs font-semibold rounded-md transition <?= $tab === 'turnos' ? 'bg-emerald-500 text-slate-950' : 'text-slate-400 hover:text-white' ?>">
                    Reporte de Turnos
                </a>
                <a href="?tab=soporte" 
                   class="px-4 py-1.5 text-xs font-semibold rounded-md transition <?= $tab === 'soporte' ? 'bg-rose-500 text-white' : 'text-slate-400 hover:text-white' ?>">
                    🛠️ Soporte & Bajas
                </a>
            </nav>
        </div>

        <!-- NOTIFICACIONES Y ALERTAS -->
        <?php if ($mensaje_estado): ?>
            <div class="p-4 rounded-xl text-xs font-semibold border flex items-center justify-between <?= $mensaje_estado['tipo'] === 'exito' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400' : 'bg-rose-500/10 border-rose-500/30 text-rose-400' ?>">
                <span><?= htmlspecialchars($mensaje_estado['texto']) ?></span>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white ml-4">✕</button>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'cobros'): ?>
        <!-- FILTROS LIQUIDACION -->
        <form method="GET" class="bg-slate-900 p-4 rounded-xl border border-slate-800 flex flex-wrap gap-4 items-end">
            <input type="hidden" name="tab" value="cobros">
            <div>
                <label class="block text-xs text-slate-400 mb-1">Fecha Desde</label>
                <input type="date" name="fecha_desde" value="<?=$fecha_desde?>" class="bg-slate-950 border border-slate-800 text-xs px-3 py-2 rounded-lg text-white">
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1">Fecha Hasta</label>
                <input type="date" name="fecha_hasta" value="<?=$fecha_hasta?>" class="bg-slate-950 border border-slate-800 text-xs px-3 py-2 rounded-lg text-white">
            </div>
            <button type="submit" class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-4 py-2 rounded-lg text-xs font-semibold hover:bg-emerald-500 hover:text-slate-950 transition">
                Filtrar Periodo
            </button>
        </form>

        <!-- TABLA COBROS -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800 flex justify-between items-center">
                <h2 class="font-semibold text-white">Resumen de Cobros por Negocio</h2>
                <span class="text-xs text-slate-500">Periodo: <?=$fecha_desde?> al <?=$fecha_hasta?></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-slate-950 text-slate-400 text-xs uppercase border-b border-slate-800">
                        <tr>
                            <th class="p-4">Negocio</th>
                            <th class="p-4">Contacto</th>
                            <th class="p-4">Turnos Cobrables</th>
                            <th class="p-4">Total Pendiente</th>
                            <th class="p-4">Total Cobrado</th>
                            <th class="p-4 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($liquidaciones as $liq): ?>
                            <tr class="hover:bg-slate-800/50 transition">
                                <td class="p-4 font-medium text-white"><?=htmlspecialchars($liq['negocio_nombre'])?></td>
                                <td class="p-4 text-xs text-slate-400"><?=$liq['telefono_dueno']?></td>
                                <td class="p-4 font-semibold text-white"><?=$liq['total_turnos']?></td>
                                <td class="p-4 font-bold text-amber-400">$<?=number_format($liq['total_pendiente'], 2)?></td>
                                <td class="p-4 font-bold text-emerald-400">$<?=number_format($liq['total_cobrado'], 2)?></td>
                                <td class="p-4 text-center">
                                    <a href="generar_factura.php?negocio_id=<?=$liq['negocio_id']?>&desde=<?=$fecha_desde?>&hasta=<?=$fecha_hasta?>" 
                                       target="_blank"
                                       class="bg-sky-500/10 text-sky-400 border border-sky-500/20 px-3 py-1.5 rounded-lg text-xs hover:bg-sky-500 hover:text-slate-950 transition">
                                        📄 Emitir Factura / Resumen
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'turnos'): ?>
        <!-- FILTROS DE BÚSQUEDA DE TURNOS -->
        <form method="GET" class="bg-slate-900 p-4 rounded-xl border border-slate-800 flex flex-wrap gap-4 items-end">
            <input type="hidden" name="tab" value="turnos">
            
            <div>
                <label class="block text-xs text-slate-400 mb-1">Negocio</label>
                <select name="negocio_id" class="bg-slate-950 border border-slate-800 text-xs px-3 py-2 rounded-lg text-white min-w-[200px]">
                    <option value="">-- Todos los Negocios --</option>
                    <?php foreach ($negocios as $neg): ?>
                        <option value="<?=$neg['id']?>" <?=$negocio_filtro == $neg['id'] ? 'selected' : ''?>>
                            <?=htmlspecialchars($neg['nombre'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1">Estado del Turno</label>
                <select name="estado_turno" class="bg-slate-950 border border-slate-800 text-xs px-3 py-2 rounded-lg text-white">
                    <option value="">-- Todos los Estados --</option>
                    <option value="confirmado" <?=$estado_filtro === 'confirmado' ? 'selected' : ''?>>Confirmado</option>
                    <option value="completado" <?=$estado_filtro === 'completado' ? 'selected' : ''?>>Completado</option>
                    <option value="reservado" <?=$estado_filtro === 'reservado' ? 'selected' : ''?>>Reservado</option>
                    <option value="pendiente_pago" <?=$estado_filtro === 'pendiente_pago' ? 'selected' : ''?>>Pendiente de Pago</option>
                    <option value="cancelado" <?=$estado_filtro === 'cancelado' ? 'selected' : ''?>>Cancelado</option>
                </select>
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1">Desde</label>
                <input type="date" name="fecha_desde" value="<?=$fecha_desde?>" class="bg-slate-950 border border-slate-800 text-xs px-3 py-2 rounded-lg text-white">
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1">Hasta</label>
                <input type="date" name="fecha_hasta" value="<?=$fecha_hasta?>" class="bg-slate-950 border border-slate-800 text-xs px-3 py-2 rounded-lg text-white">
            </div>

            <button type="submit" class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-4 py-2 rounded-lg text-xs font-semibold hover:bg-emerald-500 hover:text-slate-950 transition">
                🔍 Buscar Turnos
            </button>
        </form>

        <!-- TARJETAS DE MÉTRICAS DEL FILTRO -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl">
                <span class="text-slate-400 text-xs uppercase font-semibold">Total Turnos</span>
                <p class="text-2xl font-bold text-white mt-1"><?=$totalesReporte['total']?></p>
            </div>
            <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl">
                <span class="text-slate-400 text-xs uppercase font-semibold">Confirmados / Completados</span>
                <p class="text-2xl font-bold text-emerald-400 mt-1"><?=$totalesReporte['confirmados']?></p>
            </div>
            <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl">
                <span class="text-slate-400 text-xs uppercase font-semibold">Cancelados</span>
                <p class="text-2xl font-bold text-rose-400 mt-1"><?=$totalesReporte['cancelados']?></p>
            </div>
            <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl">
                <span class="text-slate-400 text-xs uppercase font-semibold">Señas Generadas</span>
                <p class="text-2xl font-bold text-sky-400 mt-1">$<?=number_format($totalesReporte['monto_senas'], 2)?></p>
            </div>
        </div>

        <!-- TABLA DETALLADA DE TURNOS -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800 flex justify-between items-center">
                <h2 class="font-semibold text-white">Detalle de Turnos Registrados</h2>
                <span class="text-xs text-slate-500"><?=count($turnos)?> resultados en el rango</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-slate-950 text-slate-400 text-xs uppercase border-b border-slate-800">
                        <tr>
                            <th class="p-4">Turno #</th>
                            <th class="p-4">Negocio</th>
                            <th class="p-4">Cliente</th>
                            <th class="p-4">Servicio / Precio</th>
                            <th class="p-4">Fecha & Hora</th>
                            <th class="p-4">Estado Turno</th>
                            <th class="p-4">Cobro Seña</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php if (empty($turnos)): ?>
                            <tr>
                                <td colspan="7" class="p-6 text-center text-slate-500">No se encontraron turnos con los filtros seleccionados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($turnos as $t): ?>
                                <tr class="hover:bg-slate-800/50 transition">
                                    <td class="p-4 font-mono text-xs text-slate-400">#<?=$t['turno_id']?></td>
                                    <td class="p-4 font-semibold text-white"><?=htmlspecialchars($t['negocio_nombre'])?></td>
                                    <td class="p-4">
                                        <div class="text-white font-medium"><?=htmlspecialchars($t['cliente_nombre'])?></div>
                                        <div class="text-xs text-slate-400 font-mono"><?=$t['cliente_telefono']?></div>
                                    </td>
                                    <td class="p-4">
                                        <div class="text-emerald-400"><?=htmlspecialchars($t['servicio_nombre'])?></div>
                                        <div class="text-xs text-slate-400">$<?=number_format($t['servicio_precio'], 2)?></div>
                                    </td>
                                    <td class="p-4 text-xs font-mono text-slate-200">
                                        <?=date('d/m/Y H:i', strtotime($t['fecha_hora_inicio']))?> hs
                                    </td>
                                    <td class="p-4">
                                        <?php
                                        $badgeClass = match($t['estado_turno']) {
                                            'confirmado', 'completado' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
                                            'cancelado' => 'bg-rose-500/10 text-rose-400 border-rose-500/20',
                                            'pendiente_pago', 'pendiente_revision' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                                            default => 'bg-slate-500/10 text-slate-400 border-slate-500/20'
                                        };
                                        ?>
                                        <span class="px-2.5 py-1 text-xs rounded-md border font-medium <?=$badgeClass?>">
                                            <?=strtoupper(str_replace('_', ' ', $t['estado_turno']))?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-xs">
                                        <?php if ($t['monto_sena'] > 0): ?>
                                            <div class="font-bold text-sky-400">$<?=number_format($t['monto_sena'], 2)?></div>
                                            <div class="text-slate-400 uppercase text-[10px]">
                                                <?= $t['metodo_pago'] ? htmlspecialchars($t['metodo_pago']) : 'Sin Método' ?>
                                            </div>
                                            <?php if (!empty($t['url_comprobante'])): ?>
                                                <a href="<?=htmlspecialchars($t['url_comprobante'])?>" target="_blank" class="text-xs text-indigo-400 hover:underline block mt-0.5">
                                                    📎 Ver comprobante
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-slate-500">Sin seña</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'soporte'): ?>
        <!-- ==========================================
             4. Pestaña "Soporte & Bajas de Negocios"
             ========================================== -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800 flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-white">Gestión de Negocios y Bajas Definitivas</h2>
                    <p class="text-xs text-slate-400">Eliminación completa de cuentas SaaS y sus datos vinculados</p>
                </div>
                <span class="text-xs bg-slate-800 text-slate-300 px-3 py-1 rounded-full border border-slate-700">
                    <?=count($resumenSoporte)?> Negocios Registrados
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-slate-950 text-slate-400 text-xs uppercase border-b border-slate-800">
                        <tr>
                            <th class="p-4">ID / Negocio</th>
                            <th class="p-4">Contacto</th>
                            <th class="p-4">Rubro</th>
                            <th class="p-4 text-center">Servicios</th>
                            <th class="p-4 text-center">Clientes</th>
                            <th class="p-4 text-center">Turnos Totales</th>
                            <th class="p-4 text-center">Estado</th>
                            <th class="p-4 text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($resumenSoporte as $neg): ?>
                            <tr class="hover:bg-slate-800/50 transition">
                                <td class="p-4">
                                    <div class="font-bold text-white"><?=htmlspecialchars($neg['nombre'])?></div>
                                    <div class="text-xs text-slate-500 font-mono">ID: #<?=$neg['id']?> | Reg: <?=date('d/m/Y', strtotime($neg['creado_el']))?></div>
                                </td>
                                <td class="p-4 text-xs">
                                    <div class="text-slate-200"><?=htmlspecialchars($neg['email'])?></div>
                                    <div class="text-slate-400 font-mono"><?=$neg['telefono_dueno']?></div>
                                </td>
                                <td class="p-4 text-xs text-slate-400">
                                    <?=htmlspecialchars($neg['rubro'] ?? 'Sin Rubro')?>
                                </td>
                                <td class="p-4 text-center font-mono text-slate-300"><?=$neg['total_servicios']?></td>
                                <td class="p-4 text-center font-mono text-slate-300"><?=$neg['total_clientes']?></td>
                                <td class="p-4 text-center font-mono text-slate-300"><?=$neg['total_turnos']?></td>
                                <td class="p-4 text-center">
                                    <span class="px-2.5 py-0.5 text-[11px] rounded-full border font-medium uppercase <?= $neg['estado'] === 'activo' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20' ?>">
                                        <?=$neg['estado']?>
                                    </span>
                                </td>
                                <td class="p-4 text-center">
                                    <button onclick="abrirModalBorrado(<?=$neg['id']?>, '<?=htmlspecialchars($neg['nombre'], ENT_QUOTES)?>')" 
                                            class="bg-rose-500/10 text-rose-400 border border-rose-500/20 px-3 py-1.5 rounded-lg text-xs hover:bg-rose-500 hover:text-white transition font-semibold flex items-center justify-center gap-1.5 mx-auto">
                                        🗑️ Borrar Negocio
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MODAL CONFIRMACIÓN DE BORRADO DE NEGOCIO -->
        <div id="modalBorrado" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
            <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl p-6 shadow-2xl relative">
                
                <div class="flex items-center gap-3 text-rose-400 mb-4">
                    <div class="w-10 h-10 bg-rose-500/10 border border-rose-500/20 rounded-xl flex items-center justify-center text-xl font-bold">⚠️</div>
                    <div>
                        <h3 class="font-bold text-white text-base">¿Eliminar Negocio?</h3>
                        <p class="text-xs text-slate-400">Esta acción no se puede deshacer.</p>
                    </div>
                </div>

                <p class="text-xs text-slate-300 leading-relaxed mb-4">
                    Estás a punto de borrar definitivamente el negocio <strong id="modalNombreNegocio" class="text-white"></strong> y todas sus tablas asociadas (turnos, servicios, clientes, liquidaciones, bloqueos y suscripciones).
                </p>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="accion" value="eliminar_negocio">
                    <input type="hidden" name="negocio_id_borrar" id="modalNegocioId">

                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Para confirmar, escribí el nombre exacto del negocio:</label>
                        <input type="text" name="confirmacion_nombre" id="inputConfirmacionNombre" required autocomplete="off"
                               placeholder="Escribí el nombre exacto..."
                               class="w-full bg-slate-950 border border-slate-800 text-xs px-3 py-2.5 rounded-xl text-white focus:outline-none focus:border-rose-500">
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="button" onclick="cerrarModalBorrado()" 
                                class="flex-1 bg-slate-800 text-slate-300 hover:bg-slate-700 py-2.5 rounded-xl text-xs font-semibold transition">
                            Cancelar
                        </button>
                        <button type="submit" 
                                class="flex-1 bg-rose-500 hover:bg-rose-400 text-white py-2.5 rounded-xl text-xs font-bold transition shadow-lg shadow-rose-500/20">
                            Confirmar Borrado
                        </button>
                    </div>
                </form>

            </div>
        </div>

        <script>
            function abrirModalBorrado(id, nombre) {
                document.getElementById('modalNegocioId').value = id;
                document.getElementById('modalNombreNegocio').innerText = nombre;
                document.getElementById('inputConfirmacionNombre').value = '';
                document.getElementById('modalBorrado').classList.remove('hidden');
            }

            function cerrarModalBorrado() {
                document.getElementById('modalBorrado').classList.add('hidden');
            }
        </script>
        <?php endif; ?>

    </div>

</body>
</html>