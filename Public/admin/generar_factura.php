<?php
// admin/generar_factura.php
$negocio_id = $_GET['negocio_id'] ?? null;
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-t');

if (!$negocio_id) die("Negocio no especificado.");

$pdo = new PDO("mysql:host=localhost;dbname=admlova_secretario;charset=utf8mb4", "admlova_secretario", "secretario2026");

// Datos Negocio
$stmtNegocio = $pdo->prepare("SELECT * FROM saas_negocios WHERE id = :id");
$stmtNegocio->execute(['id' => $negocio_id]);
$negocio = $stmtNegocio->fetch(PDO::FETCH_ASSOC);

// Detalle de Turnos
$stmtItems = $pdo->prepare("
    SELECT ct.*, t.fecha_hora_inicio 
    FROM cobros_turnos ct 
    JOIN saas_turnos t ON ct.turno_id = t.id
    WHERE ct.negocio_id = :negocio_id 
      AND DATE(ct.fecha_registro) BETWEEN :desde AND :hasta
");
$stmtItems->execute(['negocio_id' => $negocio_id, 'desde' => $desde, 'hasta' => $hasta]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_column($items, 'monto'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Liquidación - <?=htmlspecialchars($negocio['nombre'])?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white text-slate-900 p-8">

    <div class="max-w-3xl mx-auto border border-slate-200 p-8 rounded-lg shadow-sm">
        
        <div class="flex justify-between border-b pb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">SECRETARIO IA</h1>
                <p class="text-xs text-slate-500">Comprobante de Liquidación por Servicio SaaS</p>
            </div>
            <div class="text-right">
                <span class="text-xs font-bold text-slate-400">PERIODO</span>
                <p class="text-sm font-semibold"><?=$desde?> al <?=$hasta?></p>
            </div>
        </div>

        <div class="my-6">
            <h2 class="text-xs font-bold uppercase text-slate-400">Cliente / Negocio</h2>
            <p class="text-lg font-bold text-slate-800"><?=htmlspecialchars($negocio['nombre'])?></p>
            <p class="text-sm text-slate-600">Email: <?=$negocio['email']?></p>
            <p class="text-sm text-slate-600">Teléfono: <?=$negocio['telefono_dueno']?></p>
        </div>

        <table class="w-full text-left text-sm border-collapse my-6">
            <thead>
                <tr class="border-b bg-slate-50 text-slate-500 text-xs">
                    <th class="py-2">ID Turno</th>
                    <th class="py-2">Fecha / Hora</th>
                    <th class="py-2">Teléfono Cliente</th>
                    <th class="py-2 text-right">Monto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr class="border-b text-slate-700">
                    <td class="py-2">#<?=$item['turno_id']?></td>
                    <td class="py-2"><?=$item['fecha_hora_inicio']?></td>
                    <td class="py-2"><?=$item['cliente_telefono']?></td>
                    <td class="py-2 text-right font-mono">$<?=number_format($item['monto'], 2)?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="flex justify-end border-t pt-4">
            <div class="text-right">
                <span class="text-slate-500 font-semibold">Total a Liquidar:</span>
                <p class="text-3xl font-bold text-slate-900">$<?=number_format($total, 2)?></p>
            </div>
        </div>

        <div class="mt-8 text-center print:hidden">
            <button onclick="window.print()" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-semibold hover:bg-blue-700 transition">
                🖨️ Imprimir / Guardar en PDF
            </button>
        </div>

    </div>

</body>
</html>