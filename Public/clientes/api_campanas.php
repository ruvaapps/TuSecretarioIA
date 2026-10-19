<?php
// clientes/api_campanas.php
header('Content-Type: application/json');
session_start();
require_once '../conexion.php'; 

$negocioId = $_SESSION['negocio_id'] ?? 4;
$action = $_GET['action'] ?? 'crear';

try {
    switch ($action) {

        // 1. CREAR NUEVA CAMPAÑA
        case 'crear':
		    $data = json_decode(file_get_contents('php://input'), true);
		    $titulo = trim($data['titulo'] ?? '');
		    $mensaje = trim($data['mensaje'] ?? '');
		    $filtro = $data['filtro'] ?? 'todos';
		    $clientesIds = $data['clientes_ids'] ?? []; // Array de IDs si el filtro es manual
		
		    if (empty($titulo) || empty($mensaje)) {
		        echo json_encode(['status' => 'error', 'message' => 'Título y mensaje son requeridos.']);
		        exit;
		    }
		
		    // Validación si selecciona filtro manual
		    if ($filtro === 'manual' && empty($clientesIds)) {
		        echo json_encode(['status' => 'error', 'message' => 'Debe seleccionar al menos un cliente.']);
		        exit;
		    }
		
		    $pdo->beginTransaction();
		
		    $stmt = $pdo->prepare("
		        INSERT INTO saas_campanas_promos (negocio_id, titulo, mensaje, filtro_etiqueta, estado)
		        VALUES (:negocio_id, :titulo, :mensaje, :filtro, 'en_proceso')
		    ");
		    $stmt->execute([
		        ':negocio_id' => $negocioId,
		        ':titulo' => $titulo,
		        ':mensaje' => $mensaje,
		        ':filtro' => $filtro
		    ]);
		    $campanaId = $pdo->lastInsertId();
		
		    $clientes = [];
		
		    if ($filtro === 'manual') {
		        // Asegurar que sean enteros válidos que pertenezcan al negocio
		        $inQuery = implode(',', array_map('intval', $clientesIds));
		        if (!empty($inQuery)) {
		            $stmtCl = $pdo->prepare("SELECT id FROM saas_clientes WHERE id IN ($inQuery) AND negocio_id = :negocio_id AND deleted_at IS NULL");
		            $stmtCl->execute([':negocio_id' => $negocioId]);
		            $clientes = $stmtCl->fetchAll(PDO::FETCH_COLUMN);
		        }
		    } else {
		        // Lógica de filtros automáticos previos
		        $sqlClientes = "SELECT id FROM saas_clientes WHERE negocio_id = :negocio_id AND deleted_at IS NULL";
		
		        if ($filtro === 'inactivos') {
		            $sqlClientes = "
		                SELECT c.id 
		                FROM saas_clientes c
		                LEFT JOIN saas_turnos t ON c.id = t.cliente_id
		                WHERE c.negocio_id = :negocio_id AND c.deleted_at IS NULL
		                GROUP BY c.id HAVING MAX(t.fecha_hora_inicio) < DATE_SUB(NOW(), INTERVAL 30 DAY) OR MAX(t.fecha_hora_inicio) IS NULL
		            ";
		        } elseif ($filtro === 'cumpleanios') {
		            $sqlClientes .= " AND MONTH(fecha_nacimiento) = MONTH(CURRENT_DATE())";
		        } elseif (strpos($filtro, 'etiqueta_') === 0) {
		            $tag = str_replace('etiqueta_', '', $filtro);
		            $sqlClientes .= " AND etiquetas LIKE " . $pdo->quote('%' . $tag . '%');
		        }
		
		        $stmtCl = $pdo->prepare($sqlClientes);
		        $stmtCl->execute([':negocio_id' => $negocioId]);
		        $clientes = $stmtCl->fetchAll(PDO::FETCH_COLUMN);
		    }
		
		    if (!empty($clientes)) {
		        $stmtEnvio = $pdo->prepare("
		            INSERT INTO saas_campanas_envios (campana_id, cliente_id, estado)
		            VALUES (:campana_id, :cliente_id, 'pendiente')
		        ");
		        foreach ($clientes as $clienteId) {
		            $stmtEnvio->execute([':campana_id' => $campanaId, ':cliente_id' => $clienteId]);
		        }
		    }
		
		    $pdo->commit();
		    echo json_encode(['status' => 'success', 'message' => 'Campaña creada con ' . count($clientes) . ' destinatarios en cola.']);
		    break;

        // 2. LISTAR CAMPAÑAS CON MÉTRICAS DE MONITOREO
        case 'listar':
            $stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    p.titulo,
                    p.mensaje,
                    p.filtro_etiqueta,
                    p.estado,
                    p.creado_el,
                    COUNT(e.id) AS total_destinatarios,
                    SUM(CASE WHEN e.estado = 'enviado' THEN 1 ELSE 0 END) AS total_enviados,
                    SUM(CASE WHEN e.estado = 'pendiente' THEN 1 ELSE 0 END) AS total_pendientes,
                    SUM(CASE WHEN e.estado = 'fallido' THEN 1 ELSE 0 END) AS total_fallidos
                FROM saas_campanas_promos p
                LEFT JOIN saas_campanas_envios e ON p.id = e.campana_id
                WHERE p.negocio_id = :negocio_id
                GROUP BY p.id
                ORDER BY p.id DESC
            ");
            $stmt->execute([':negocio_id' => $negocioId]);
            $campanas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $campanas]);
            break;

        // 3. OBTENER DETALLE DE ENVÍOS DE UNA CAMPAÑA
        case 'detalle':
            $campanaId = $_GET['id'] ?? 0;

            $stmt = $pdo->prepare("
                SELECT 
                    e.id AS envio_id,
                    e.estado,
                    e.enviado_el,
                    e.error_mensaje,
                    c.nombre AS cliente_nombre,
                    c.telefono AS cliente_telefono
                FROM saas_campanas_envios e
                INNER JOIN saas_clientes c ON e.cliente_id = c.id
                INNER JOIN saas_campanas_promos p ON e.campana_id = p.id
                WHERE e.campana_id = :campana_id AND p.negocio_id = :negocio_id
                ORDER BY e.id ASC
            ");
            $stmt->execute([':campana_id' => $campanaId, ':negocio_id' => $negocioId]);
            $detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $detalles]);
            break;

        // 4. CAMBIAR ESTADO DE LA CAMPAÑA (Cancelar / Pausar)
        case 'cambiar_estado':
            $data = json_decode(file_get_contents('php://input'), true);
            $campanaId = $data['id'] ?? 0;
            $nuevoEstado = $data['estado'] ?? '';

            if (!in_array($nuevoEstado, ['cancelada', 'en_proceso'])) {
                echo json_encode(['status' => 'error', 'message' => 'Estado no válido.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE saas_campanas_promos 
                SET estado = :estado 
                WHERE id = :id AND negocio_id = :negocio_id
            ");
            $stmt->execute([':estado' => $nuevoEstado, ':id' => $campanaId, ':negocio_id' => $negocioId]);

            echo json_encode(['status' => 'success', 'message' => 'Estado de campaña actualizado.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}