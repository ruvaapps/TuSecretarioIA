<?php
// cron_resumen_diario.php

// 1. Incluimos la conexión a la base de datos
require_once __DIR__ . '/conexion.php'; 

// 2. Credenciales globales de Meta / WhatsApp API
define('WHATSAPP_PHONE_NUMBER_ID', '1297893416744537');
define('WHATSAPP_ACCESS_TOKEN', 'EAAUJIfJBzp0BSdlljUGVo0bDbADhLC6I6ilZCVZBBtBqXmWMZBckitoaIfdlQKqXEZCIM7hRFESbifOTgFkF9CmiIwFB4rYxrTYhP2HTGpRZAuHbX8594DCgnE64PHjVxTEMYBGvsi8UvUtROzB1VioWurXTctZAeVg7xAxbDYLgdcoBSzFUCn5G4IZABNvtZAUZApQZDZD');

date_default_timezone_set('America/Argentina/Cordoba');
$fechaHoy        = date('Y-m-d');
$fechaFormateada = date('d/m/Y');
$horaActual      = date('H:i'); // Ej: "08:00"

/**
 * Normaliza el número de teléfono para Meta (ej: 549351xxxxxxx)
 */
function normalizarTelefonoMeta($telefono) {
    $num = preg_replace('/[^0-9]/', '', $telefono);
    
    if (str_starts_with($num, '0')) {
        $num = substr($num, 1);
    }
    
    if (str_starts_with($num, '15')) {
        $num = substr($num, 2);
    }
    
    if (!str_starts_with($num, '549') && !str_starts_with($num, '54')) {
        $num = '549' . $num;
    } elseif (str_starts_with($num, '54') && !str_starts_with($num, '549')) {
        $num = '549' . substr($num, 2);
    }
    
    return $num;
}

try {
    // 3. Obtener negocios activos que tengan la alerta activada y coincidan con la hora actual
    $sqlNegocios = "
        SELECT n.id, n.nombre, n.telefono_dueno 
        FROM saas_negocios n
        INNER JOIN saas_configuracion_agenda c ON n.id = c.negocio_id
        WHERE n.estado = 'activo' 
          AND n.telefono_dueno IS NOT NULL 
          AND n.telefono_dueno != ''
          AND c.alerta_resumen_activa = 1
          AND TIME_FORMAT(c.alerta_resumen_hora, '%H:%i') = :hora_actual
    ";
    
    $stmtNegocios = $pdo->prepare($sqlNegocios);
    $stmtNegocios->execute([':hora_actual' => $horaActual]);
    $negocios = $stmtNegocios->fetchAll(PDO::FETCH_ASSOC);

    foreach ($negocios as $negocio) {
        $negocioId       = $negocio['id'];
        $telefonoDestino = normalizarTelefonoMeta($negocio['telefono_dueno']);
        $nombreComercio  = $negocio['nombre'];

        // 4. Consultar cantidad de turnos del día
        $sqlTurnos = "
            SELECT COUNT(*) 
            FROM saas_turnos 
            WHERE negocio_id = :negocio_id 
              AND DATE(fecha_hora_inicio) = :fecha_hoy 
              AND estado IN ('reservado', 'confirmado')
        ";

        $stmtTurnos = $pdo->prepare($sqlTurnos);
        $stmtTurnos->execute([
            ':negocio_id' => $negocioId,
            ':fecha_hoy'  => $fechaHoy
        ]);
        $cantTurnos = $stmtTurnos->fetchColumn();

        // 5. Enviar plantilla de WhatsApp
        enviarPlantillaResumenDiario($telefonoDestino, $nombreComercio, $fechaFormateada, $cantTurnos);
    }
} catch (PDOException $e) {
    error_log("Error en ejecucion de CRON Resumen Diario: " . $e->getMessage());
}

/**
 * Función para enviar la plantilla 'resumen_diario_agenda' por WhatsApp Cloud API
 */
function enviarPlantillaResumenDiario($to, $nombreComercio, $fecha, $cantTurnos) {
    $url = "https://graph.facebook.com/v20.0/" . WHATSAPP_PHONE_NUMBER_ID . "/messages";

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => 'resumen_diario_agenda',
            'language' => ['code' => 'es_AR'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreComercio], // {{1}}
                        ['type' => 'text', 'text' => $fecha],          // {{2}}
                        ['type' => 'text', 'text' => (string)$cantTurnos] // {{3}}
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . WHATSAPP_ACCESS_TOKEN,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
}