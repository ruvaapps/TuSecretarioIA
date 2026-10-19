<?php
// Habilitar reporte de errores para depuración (comentar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Validar sesión del negocio
if (!isset($_SESSION['negocio_id'])) {
    header('Location: login.php');
    exit;
}

// Requerir el archivo de conexión existente
require_once __DIR__ . '/conexion.php'; 

$negocio_id = $_SESSION['negocio_id'];

// Filtro de Fechas (por defecto el mes actual)
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01 00:00:00');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-t 23:59:59');

try {
    // 1. Obtener Totales
    $stmtResumen = $pdo->prepare("
        SELECT 
            COUNT(t.id) AS total_otorgados,
            SUM(CASE WHEN t.estado IN ('confirmado', 'completado') THEN 1 ELSE 0 END) AS confirmados,
            SUM(CASE WHEN t.estado = 'cancelado' THEN 1 ELSE 0 END) AS cancelados,
            COALESCE(SUM(CASE WHEN t.estado IN ('confirmado', 'completado') THEN s.precio ELSE 0 END), 0) AS ingresos,
            COALESCE(SUM(CASE WHEN t.estado IN ('confirmado', 'completado') THEN s.costo ELSE 0 END), 0) AS costos,
            COALESCE(SUM(CASE WHEN t.estado IN ('confirmado', 'completado') THEN (s.precio - s.costo) ELSE 0 END), 0) AS ganancia
        FROM saas_turnos t
        INNER JOIN saas_servicios s ON t.servicio_id = s.id
        WHERE t.negocio_id = :negocio_id
          AND t.fecha_hora_inicio >= :fecha_desde
          AND t.fecha_hora_inicio <= :fecha_hasta
    ");
    $stmtResumen->execute([
        'negocio_id' => $negocio_id,
        'fecha_desde' => $fecha_desde,
        'fecha_hasta' => $fecha_hasta
    ]);
    $resumen = $stmtResumen->fetch();

    // 2. Obtener Desglose por Servicio
    $stmtServicios = $pdo->prepare("
        SELECT 
            s.nombre AS servicio,
            COUNT(t.id) AS total_turnos,
            SUM(CASE WHEN t.estado IN ('confirmado', 'completado') THEN 1 ELSE 0 END) AS confirmados,
            SUM(CASE WHEN t.estado IN ('confirmado', 'completado') THEN s.precio ELSE 0 END) AS ingresos,
            SUM(CASE WHEN t.estado IN ('confirmado', 'completado') THEN s.costo ELSE 0 END) AS costos,
            SUM(CASE WHEN t.estado IN ('confirmado', 'completado') THEN (s.precio - s.costo) ELSE 0 END) AS ganancia
        FROM saas_turnos t
        INNER JOIN saas_servicios s ON t.servicio_id = s.id
        WHERE t.negocio_id = :negocio_id
          AND t.fecha_hora_inicio >= :fecha_desde
          AND t.fecha_hora_inicio <= :fecha_hasta
        GROUP BY s.id, s.nombre
        ORDER BY confirmados DESC
    ");
    $stmtServicios->execute([
        'negocio_id' => $negocio_id,
        'fecha_desde' => $fecha_desde,
        'fecha_hasta' => $fecha_hasta
    ]);
    $servicios = $stmtServicios->fetchAll();

} catch (PDOException $e) {
    die("Error en la consulta: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Estadísticas del Negocio</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container my-4">
    <h2 class="mb-4">Estadísticas y Financiero</h2>

    <!-- Filtro de Rango de Fechas -->
    <form method="GET" class="card card-body mb-4 shadow-sm">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Desde:</label>
                <input type="datetime-local" name="fecha_desde" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime($fecha_desde)) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Hasta:</label>
                <input type="datetime-local" name="fecha_hasta" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime($fecha_hasta)) ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">Filtrar Resultados</button>
            </div>
        </div>
    </form>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Turnos Otorgados</h6>
                    <h3><?= $resumen['total_otorgados'] ?? 0 ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-success shadow-sm">
                <div class="card-body">
                    <h6 class="text-success">Confirmados / Realizados</h6>
                    <h3><?= $resumen['confirmados'] ?? 0 ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-danger shadow-sm">
                <div class="card-body">
                    <h6 class="text-danger">Cancelados</h6>
                    <h3><?= $resumen['cancelados'] ?? 0 ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-primary shadow-sm bg-white">
                <div class="card-body">
                    <h6 class="text-primary">Ganancia Neta</h6>
                    <h3 class="text-primary">$<?= number_format($resumen['ganancia'] ?? 0, 2, ',', '.') ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Resumen Financiero -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5>Ingresos Brutos</h5>
                    <p class="fs-4 text-success">$<?= number_format($resumen['ingresos'] ?? 0, 2, ',', '.') ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5>Costos Operativos</h5>
                    <p class="fs-4 text-danger">$<?= number_format($resumen['costos'] ?? 0, 2, ',', '.') ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla Detalle por Servicio -->
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">Rendimiento por Servicio</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>Servicio</th>
                        <th>Solicitados</th>
                        <th>Confirmados</th>
                        <th>Ingresos</th>
                        <th>Costos</th>
                        <th>Ganancia Neta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($servicios)): ?>
                        <?php foreach ($servicios as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['servicio']) ?></td>
                            <td><?= $row['total_turnos'] ?></td>
                            <td><?= $row['confirmados'] ?></td>
                            <td>$<?= number_format($row['ingresos'] ?? 0, 2, ',', '.') ?></td>
                            <td>$<?= number_format($row['costos'] ?? 0, 2, ',', '.') ?></td>
                            <td class="fw-bold">$<?= number_format($row['ganancia'] ?? 0, 2, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-3 text-muted">No se encontraron registros para el rango seleccionado.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>