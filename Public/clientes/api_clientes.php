<?php
// api_clientes.php
header('Content-Type: application/json');
session_start();

// TODO: Reemplazar por tu archivo de conexión PDO y validación de sesión
require_once '../conexion.php'; 

$negocioId = $_SESSION['negocio_id'] ?? 4; // Ejemplo o ID de sesión
$action = $_GET['action'] ?? 'listar';

try {
    switch ($action) {

        // 1. LISTAR CLIENTES CON MÉTRICAS EN TIEMPO REAL
        case 'listar':
            $filtro = $_GET['filtro'] ?? 'todos';
            $sql = "
                SELECT 
                    c.id,
                    c.nombre,
                    c.telefono,
                    c.email,
                    c.fecha_nacimiento,
                    c.etiquetas,
                    c.notas,
                    c.creado_el,
                    COUNT(t.id) AS total_turnos,
                    MAX(t.fecha_hora_inicio) AS ultima_visita,
                    COALESCE(SUM(CASE WHEN t.estado = 'completado' THEN s.precio ELSE 0 END), 0) AS total_gastado
                FROM saas_clientes c
                LEFT JOIN saas_turnos t ON c.id = t.cliente_id
                LEFT JOIN saas_servicios s ON t.servicio_id = s.id
                WHERE c.negocio_id = :negocio_id AND c.deleted_at IS NULL
            ";

            // Aplicar filtros especiales
            if ($filtro === 'inactivos') {
                $sql .= " GROUP BY c.id HAVING ultima_visita < DATE_SUB(NOW(), INTERVAL 30 DAY) OR ultima_visita IS NULL";
            } elseif ($filtro === 'cumpleanios') {
                $sql .= " AND MONTH(c.fecha_nacimiento) = MONTH(CURRENT_DATE()) GROUP BY c.id";
            } else {
                $sql .= " GROUP BY c.id ORDER BY c.id DESC";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':negocio_id' => $negocioId]);
            $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $clientes]);
            break;

        // 2. GUARDAR / EDITAR CLIENTE
        case 'guardar':
            $data = json_decode(file_get_contents('php://input'), true);

            $id = $data['id'] ?? null;
            $nombre = trim($data['nombre'] ?? '');
            $telefono = trim($data['telefono'] ?? '');
            $email = trim($data['email'] ?? null);
            $fechaNac = !empty($data['fecha_nacimiento']) ? $data['fecha_nacimiento'] : null;
            $etiquetas = trim($data['etiquetas'] ?? '');
            $notas = trim($data['notas'] ?? '');

            if (empty($telefono)) {
                echo json_encode(['status' => 'error', 'message' => 'El teléfono es obligatorio.']);
                exit;
            }

            if ($id) {
                // Actualizar existente
                $stmt = $pdo->prepare("
                    UPDATE saas_clientes 
                    SET nombre = :nombre, telefono = :telefono, email = :email, 
                        fecha_nacimiento = :fecha_nac, etiquetas = :etiquetas, notas = :notas
                    WHERE id = :id AND negocio_id = :negocio_id
                ");
                $stmt->execute([
                    ':nombre' => $nombre, ':telefono' => $telefono, ':email' => $email,
                    ':fecha_nac' => $fechaNac, ':etiquetas' => $etiquetas, ':notas' => $notas,
                    ':id' => $id, ':negocio_id' => $negocioId
                ]);
            } else {
                // Insertar nuevo con ON DUPLICATE KEY por si el teléfono ya existe
                $stmt = $pdo->prepare("
                    INSERT INTO saas_clientes (negocio_id, telefono, nombre, email, fecha_nacimiento, etiquetas, notas, origen_alta)
                    VALUES (:negocio_id, :telefono, :nombre, :email, :fecha_nac, :etiquetas, :notas, 'web_manual')
                    ON DUPLICATE KEY UPDATE 
                        nombre = VALUES(nombre), email = VALUES(email), 
                        fecha_nacimiento = VALUES(fecha_nacimiento), etiquetas = VALUES(etiquetas), notas = VALUES(notas), deleted_at = NULL
                ");
                $stmt->execute([
                    ':negocio_id' => $negocioId, ':telefono' => $telefono, ':nombre' => $nombre,
                    ':email' => $email, ':fecha_nac' => $fechaNac, ':etiquetas' => $etiquetas, ':notas' => $notas
                ]);
            }

            echo json_encode(['status' => 'success', 'message' => 'Cliente guardado correctamente.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}