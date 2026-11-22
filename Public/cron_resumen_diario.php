<?php
// cron_resumen_diario.php

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/turnos_helper.php';

date_default_timezone_set('America/Argentina/Cordoba');
$fechaHoy        = date('Y-m-d');
$fechaFormateada = date('d/m/Y');
$horaActual      = date('H:i'); // Ej: "08:00"

try {
    // 1. Obtener negocios activos con alerta activada y coincidencia horaria
    $sqlNegocios = "
        SELECT n.id, n.nombre
        FROM saas_negocios n
        INNER JOIN saas_configuracion_agenda c ON n.id = c.negocio_id
        WHERE n.estado = 'activo'
          AND c.alerta_resumen_activa = 1
          AND TIME_FORMAT(c.alerta_resumen_hora, '%H:%i') = :hora_actual
    ";

    $stmtNegocios = $pdo->prepare($sqlNegocios);
    $stmtNegocios->execute([':hora_actual' => $horaActual]);
    $negocios = $stmtNegocios->fetchAll(PDO::FETCH_ASSOC);

    foreach ($negocios as $negocio) {
        $negocioId      = (int)$negocio['id'];
        $nombreComercio = $negocio['nombre'] ?? 'Negocio';

        // 2. Consultar cantidad de turnos del día
        $sqlTurnos = "
            SELECT COUNT(*)
            FROM saas_turnos
            WHERE negocio_id = :negocio_id
              AND DATE(fecha_hora_inicio) = :fecha_hoy
        ";

        $stmtTurnos = $pdo->prepare($sqlTurnos);
        $stmtTurnos->execute([
            ':negocio_id' => $negocioId,
            ':fecha_hoy'  => $fechaHoy
        ]);
        $cantTurnos = (int)$stmtTurnos->fetchColumn();

        // 3. Enviar resumen diario vía WebPush
        $titulo = "Resumen diario - {$nombreComercio}";
        $cuerpo = "Hoy ({$fechaFormateada}) tenés {$cantTurnos} turno(s) reservado(s)/confirmado(s).";

        if (function_exists('notificarNuevoTurnoWebPush')) {
            notificarNuevoTurnoWebPush(
                $pdo,
                $negocioId,
                $nombreComercio,
                'Resumen Diario',
                $fechaHoy,
                $horaActual . ':00',
                $titulo,
                $cuerpo,
                null
            );

            if (function_exists('logTurnosHelper')) {
                logTurnosHelper("Resumen diario WebPush procesado para negocio_id={$negocioId} ({$cantTurnos} turnos)");
            }
        } else {
            error_log("notificarNuevoTurnoWebPush no disponible en cron_resumen_diario.php");
        }
    }
} catch (PDOException $e) {
    error_log("Error en ejecucion de CRON Resumen Diario (WebPush): " . $e->getMessage());
}