<?php
// obtener_eventos_panel.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['negocio_id'])) {
    echo json_encode([]);
    exit;
}

require_once 'conexion.php';

$negocio_id = $_SESSION['negocio_id'];

$start      = $_GET['start'] ?? date('Y-m-01');
$end        = $_GET['end']   ?? date('Y-m-t');
$recurso_id = !empty($_GET['recurso_id']) ? (int)$_GET['recurso_id'] : null;

$eventos = [];

try {
    // Construcción de la consulta base para Turnos
    $sqlTurnos = "
        SELECT 
            t.id,
            t.fecha_hora_inicio AS start,
            t.fecha_hora_fin AS end,
            t.estado,
            t.monto_sena,
            t.metodo_pago,
            t.recurso_id,
            c.nombre AS cliente_nombre,
            c.telefono AS cliente_telefono,
            s.nombre AS servicio_nombre,
            r.nombre AS recurso_nombre,
            cp.monto_detectado,
            cp.numero_operacion,
            cp.nombre_origen,
            cp.banco_origen,
            cp.estado AS comprobante_estado,
            cp.url_comprobante
        FROM saas_turnos t
        LEFT JOIN saas_clientes c ON t.cliente_id = c.id
        LEFT JOIN saas_servicios s ON t.servicio_id = s.id
        LEFT JOIN saas_recursos r ON t.recurso_id = r.id
        LEFT JOIN saas_comprobantes_pago cp ON cp.turno_id = t.id
        WHERE t.negocio_id = :negocio_id 
          AND t.estado != 'cancelado'
          AND t.fecha_hora_inicio >= :start 
          AND t.fecha_hora_fin <= :end
    ";

    // Filtrar por recurso si viene el parámetro desde el panel
    if ($recurso_id) {
        $sqlTurnos .= " AND t.recurso_id = :recurso_id";
    }

    $sqlTurnos .= " ORDER BY cp.id DESC";

    $stmtTurnos = $pdo->prepare($sqlTurnos);
    
    $paramsTurnos = [
        ':negocio_id' => $negocio_id,
        ':start'      => $start,
        ':end'        => $end
    ];

    if ($recurso_id) {
        $paramsTurnos[':recurso_id'] = $recurso_id;
    }

    $stmtTurnos->execute($paramsTurnos);
    $turnos = $stmtTurnos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($turnos as $t) {
        $nombreCliente  = $t['cliente_nombre'] ?: 'Cliente';
        $nombreServicio = $t['servicio_nombre'] ?: 'Servicio';
        $nombreRecurso  = $t['recurso_nombre'] ?? '';

        // Si hay recurso asignado y estamos viendo la lista general (sin filtrar un solo recurso),
        // concatenamos [Cancha/Recurso] en el título para diferenciarlo
        $titulo = '';
        if (!empty($nombreRecurso) && !$recurso_id) {
            $titulo = '[' . $nombreRecurso . '] ';
        }
        $titulo .= $nombreCliente . ' - ' . $nombreServicio;
        
        $color = '#3b82f6'; // Azul (confirmado)

        if ($t['estado'] === 'pendiente_pago') {
            $color = '#f97316'; // Naranja
        } elseif ($t['estado'] === 'pendiente_revision') {
            $color = '#eab308'; // Amarillo
        } elseif ($t['estado'] === 'completado') {
            $color = '#10b981'; // Verde
        }

        $eventos[] = [
            'id' => $t['id'],
            'title' => $titulo,
            'start' => $t['start'],
            'end' => $t['end'],
            'backgroundColor' => $color,
            'borderColor' => $color,
            'extendedProps' => [
                'tipo' => 'turno',
                'estado' => $t['estado'],
                'telefono' => $t['cliente_telefono'] ?? 'Sin datos',
                'recurso_id' => $t['recurso_id'],
                'recurso_nombre' => $nombreRecurso,
                'monto_sena' => $t['monto_sena'] ?? 0,
                'metodo_pago' => $t['metodo_pago'] ?? '',
                'comprobante' => !empty($t['monto_detectado']) || !empty($t['nombre_origen']) ? [
                    'monto_detectado'  => $t['monto_detectado'],
                    'numero_operacion' => $t['numero_operacion'],
                    'nombre_origen'    => $t['nombre_origen'],
                    'banco_origen'     => $t['banco_origen'],
                    'estado'           => $t['comprobante_estado'],
                    'url'              => $t['url_comprobante']
                ] : null
            ]
        ];
    }

    // 2. OBTENER HORARIOS BLOQUEADOS
    $stmtBloqueos = $pdo->prepare("
        SELECT 
            id,
            motivo,
            fecha_hora_inicio AS start,
            fecha_hora_fin AS end
        FROM saas_bloqueos
        WHERE negocio_id = ?
          AND fecha_hora_inicio >= ? 
          AND fecha_hora_fin <= ?
    ");
    $stmtBloqueos->execute([$negocio_id, $start, $end]);
    $bloqueos = $stmtBloqueos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bloqueos as $b) {
        $eventos[] = [
            'id'    => 'bloqueo_' . $b['id'],
            'title' => '🚫 ' . ($b['motivo'] ?: 'Horario Bloqueado'),
            'start' => $b['start'],
            'end'   => $b['end'],
            'color' => '#ef4444',
            'textColor' => '#ffffff',
            'extendedProps' => [
                'tipo' => 'bloqueo'
            ]
        ];
    }

    echo json_encode($eventos);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}