<?php
session_start();
header('Content-Type: application/json');

// Carga de autoload para Minishlink\WebPush
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

require_once __DIR__ . '/mercadopago_helper.php';

$existeAutoload = file_exists($composerAutoload) ? 'SI' : 'NO';
$existeClase = class_exists('Minishlink\WebPush\WebPush') ? 'SI' : 'NO';

logAccionesPanel("[WEB PUSH DEBUG] Autoload existe: {$existeAutoload} | Clase WebPush existe: {$existeClase}");

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// -----------------------------------------------------------------------------
// 1. FUNCIÓN DE LOG Y CONFIGURACIÓN INICIAL
// -----------------------------------------------------------------------------
function logAccionesPanel($msg) {
    file_put_contents(__DIR__ . '/log_acciones_panel.txt', "[" . date('Y-m-d H:i:s') . "] [ACCIONES_PANEL] " . $msg . "\n", FILE_APPEND);
}

require_once 'conexion.php';

// Configuración de llaves VAPID para Web Push
if (!defined('VAPID_PUBLIC_KEY')) {
    define('VAPID_PUBLIC_KEY', 'BB_sUL_80YWtAyK5m995PeOr_K0m_M3aYG89QOMVHXUege_PKuh4FJ7VB2-NSesPJcAfTKUm7_4MXYSFpybqJ1I');
}
if (!defined('VAPID_PRIVATE_KEY')) {
    define('VAPID_PRIVATE_KEY', 'tB2EnpYIX_LalPzTQCbqOnyjbVWp3Pbi8SGZ79vY8KM');
}
if (!defined('VAPID_SUBJECT')) {
    define('VAPID_SUBJECT', 'Notificacion de turno');
}

// Definir credenciales globales de WhatsApp Cloud API (Meta)
if (!defined('WHATSAPP_PHONE_NUMBER_ID')) {
    define('WHATSAPP_PHONE_NUMBER_ID', '1297893416744537');
}

if (!defined('WHATSAPP_ACCESS_TOKEN')) {
    define('WHATSAPP_ACCESS_TOKEN', 'EAAUJIfJBzp0BSdlljUGVo0bDbADhLC6I6ilZCVZBBtBqXmWMZBckitoaIfdlQKqXEZCIM7hRFESbifOTgFkF9CmiIwFB4rYxrTYhP2HTGpRZAuHbX8594DCgnE64PHjVxTEMYBGvsi8UvUtROzB1VioWurXTctZAeVg7xAxbDYLgdcoBSzFUCn5G4IZABNvtZAUZApQZDZD');
}

$accion = $_REQUEST['accion'] ?? '';

// Captura de body JSON para peticiones fetch
$inputJSON = json_decode(file_get_contents('php://input'), true);
if (empty($accion) && isset($inputJSON['accion'])) {
    $accion = $inputJSON['accion'];
}

// Determinamos las acciones públicas que no requieren la sesión del panel
$acciones_publicas = ['crear_turno_publico', 'obtener_servicios_publico', 'obtener_horarios_disponibles'];

if (!in_array($accion, $acciones_publicas) && !isset($_SESSION['negocio_id'])) {
    logAccionesPanel("Intento de acceso no autorizado sin sesión activa para la acción: '{$accion}'");
    echo json_encode(['status' => 'error', 'message' => 'Sesión no válida']);
    exit;
}

// DETERMINACIÓN INTELIGENTE DE NEGOCIO_ID:
// 1. Si la petición trae un 'negocio_id' explícito (vía GET/POST/JSON), se usa ese ID (Prevalece para reservas públicas).
// 2. Si no viene explícito, se utiliza el ID guardado en la sesión ($_SESSION['negocio_id']).
$negocio_id_peticion = isset($_REQUEST['negocio_id']) ? (int)$_REQUEST['negocio_id'] : (isset($inputJSON['negocio_id']) ? (int)$inputJSON['negocio_id'] : null);

if ($negocio_id_peticion && $negocio_id_peticion > 0) {
    $negocio_id = $negocio_id_peticion;
} elseif (isset($_SESSION['negocio_id'])) {
    $negocio_id = (int)$_SESSION['negocio_id'];
} else {
    $negocio_id = null;
}

logAccionesPanel("Ejecutando acción '{$accion}' para Negocio ID: {$negocio_id}");

// -----------------------------------------------------------------------------
// 2. EJECUCIÓN DE ACCIONES
// -----------------------------------------------------------------------------
try {
    if ($accion === 'guardar_suscripcion_push') {
        try {    
            $subscription = $inputJSON['subscription'] ?? ($_POST['subscription'] ?? null);

            if (is_string($subscription)) {
                $subscription = json_decode($subscription, true);
            }

            logAccionesPanel("Datos recibidos [guardar_suscripcion_push]: " . json_encode($subscription));

            $endpoint = $subscription['endpoint'] ?? null;
            $p256dh   = $subscription['keys']['p256dh'] ?? '';
            $auth     = $subscription['keys']['auth'] ?? '';

            if (!$negocio_id || !$endpoint) {
                logAccionesPanel("ERROR [guardar_suscripcion_push]: Datos de suscripción incompletos. Negocio ID: {$negocio_id}, Endpoint: {$endpoint}");
                echo json_encode(['status' => 'error', 'message' => 'Datos de suscripción incompletos']);
                exit;
            }

            $stmtDel = $pdo->prepare("DELETE FROM saas_push_subscriptions WHERE endpoint = ?");
            $stmtDel->execute([$endpoint]);

            $stmtPush = $pdo->prepare("
                INSERT INTO saas_push_subscriptions (negocio_id, endpoint, p256dh, auth)
                VALUES (?, ?, ?, ?)
            ");
            $stmtPush->execute([$negocio_id, $endpoint, $p256dh, $auth]);

            logAccionesPanel("Suscripción Push guardada exitosamente en BD para Negocio ID: {$negocio_id}");
            echo json_encode(['status' => 'success', 'message' => 'Suscripción Web Push guardada correctamente']);
            exit;

        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [guardar_suscripcion_push]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($accion === 'eliminar_suscripcion_push') {
        try {
            $subscription = $inputJSON['subscription'] ?? ($_POST['subscription'] ?? null);

            if (is_string($subscription)) {
                $subscription = json_decode($subscription, true);
            }

            $endpoint = $subscription['endpoint'] ?? ($inputJSON['endpoint'] ?? ($_POST['endpoint'] ?? null));

            if (!$negocio_id || !$endpoint) {
                echo json_encode(['status' => 'error', 'message' => 'Falta el negocio ID o el endpoint']);
                exit;
            }

            $stmtPush = $pdo->prepare("DELETE FROM saas_push_subscriptions WHERE negocio_id = ? AND endpoint = ?");
            $stmtPush->execute([$negocio_id, $endpoint]);

            logAccionesPanel("Suscripción Push eliminada correctamente para Negocio ID: {$negocio_id}");
            echo json_encode(['status' => 'success', 'message' => 'Suscripción cancelada correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [eliminar_suscripcion_push]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la suscripción push']);
        }
    } elseif ($accion === 'obtener_servicios') {
        try {
            $stmt = $pdo->prepare("SELECT id, nombre, precio, costo, sena, duracion_minutos AS duracion_min FROM saas_servicios WHERE negocio_id = ? AND estado = 'activo'");
            $stmt->execute([$negocio_id]);
            $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);

            logAccionesPanel("Consulta de servicios exitosa. Total obtenidos: " . count($servicios));
            echo json_encode(['status' => 'success', 'data' => $servicios]);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [obtener_servicios]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al consultar los servicios']);
        }

    } elseif ($accion === 'obtener_servicios_publico') {
        if (!$negocio_id) {
            echo json_encode(['status' => 'error', 'message' => 'ID de negocio requerido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, nombre, precio, costo, sena, duracion_minutos AS duracion_min FROM saas_servicios WHERE negocio_id = ? AND estado = 'activo'");
            $stmt->execute([$negocio_id]);
            $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $servicios]);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [obtener_servicios_publico]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al obtener servicios']);
        }

    } elseif ($accion === 'crear_servicio') {
        $nombre   = trim($_POST['nombre'] ?? '');
        $precio   = $_POST['precio'] ?? 0;
        $costo    = $_POST['costo'] ?? 0;
        $sena     = $_POST['sena'] ?? 0;
        $duracion = $_POST['duracion_min'] ?? 30;

        if (!$nombre) {
            logAccionesPanel("Validación fallida en [crear_servicio]: Nombre requerido.");
            echo json_encode(['status' => 'error', 'message' => 'El nombre del servicio es obligatorio']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO saas_servicios (negocio_id, nombre, precio, costo, sena, duracion_minutos, estado) VALUES (?, ?, ?, ?, ?, ?, 'activo')");
            $stmt->execute([$negocio_id, $nombre, $precio, $costo, $sena, $duracion]);

            logAccionesPanel("Servicio creado exitosamente para Negocio ID: {$negocio_id}");
            echo json_encode(['status' => 'success', 'message' => 'Servicio creado correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [crear_servicio]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar el servicio']);
        }

    } elseif ($accion === 'editar_servicio') {
        $servicio_id = (int)($_POST['servicio_id'] ?? 0);
        $nombre      = trim($_POST['nombre'] ?? '');
        $precio      = $_POST['precio'] ?? 0;
        $costo       = $_POST['costo'] ?? 0;
        $sena        = $_POST['sena'] ?? 0;
        $duracion    = $_POST['duracion_min'] ?? 30;

        if (!$servicio_id || !$nombre) {
            logAccionesPanel("Validación fallida en [editar_servicio]: ID y Nombre requeridos.");
            echo json_encode(['status' => 'error', 'message' => 'ID de servicio y nombre son obligatorios']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE saas_servicios SET nombre = ?, precio = ?, costo = ?, sena = ?, duracion_minutos = ? WHERE id = ? AND negocio_id = ?");
            $stmt->execute([$nombre, $precio, $costo, $sena, $duracion, $servicio_id, $negocio_id]);

            logAccionesPanel("Servicio ID {$servicio_id} modificado exitosamente.");
            echo json_encode(['status' => 'success', 'message' => 'Servicio modificado correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [editar_servicio]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al modificar el servicio']);
        }

    } elseif ($accion === 'eliminar_servicio') {
        $servicio_id = (int)($_POST['servicio_id'] ?? 0);

        if (!$servicio_id) {
            echo json_encode(['status' => 'error', 'message' => 'ID de servicio no válido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM saas_servicios WHERE id = ? AND negocio_id = ?");
            $stmt->execute([$servicio_id, $negocio_id]);

            logAccionesPanel("Servicio ID {$servicio_id} eliminado.");
            echo json_encode(['status' => 'success', 'message' => 'Servicio eliminado correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [eliminar_servicio]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al eliminar el servicio']);
        }

    // -------------------------------------------------------------------------
    // SECCIÓN NUEVA: GESTIÓN DE RECURSOS / CANCHAS / CONSULTORIOS
    // -------------------------------------------------------------------------
    } elseif ($accion === 'obtener_recursos') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM saas_recursos WHERE negocio_id = ? ORDER BY id ASC");
            $stmt->execute([$negocio_id]);
            $recursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            logAccionesPanel("Consulta de recursos exitosa. Total: " . count($recursos));
            echo json_encode(['status' => 'success', 'data' => $recursos]);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [obtener_recursos]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al consultar recursos']);
        }

    } elseif ($accion === 'crear_recurso') {
        $nombre       = trim($_POST['nombre'] ?? '');
        $tipo_recurso = $_POST['tipo_recurso'] ?? 'físico';
        $capacidad    = intval($_POST['capacidad'] ?? 1);

        if (empty($nombre)) {
            logAccionesPanel("Validación fallida en [crear_recurso]: Nombre vacio.");
            echo json_encode(['status' => 'error', 'message' => 'El nombre del recurso es obligatorio']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO saas_recursos (negocio_id, nombre, tipo_recurso, capacidad, estado) VALUES (?, ?, ?, ?, 'activo')");
            $stmt->execute([$negocio_id, $nombre, $tipo_recurso, $capacidad]);

            logAccionesPanel("Recurso '{$nombre}' creado correctamente.");
            echo json_encode(['status' => 'success', 'message' => 'Recurso creado correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [crear_recurso]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar el recurso']);
        }

    } elseif ($accion === 'cambiar_estado_recurso') {
        $recurso_id = intval($_POST['recurso_id'] ?? 0);
        $estado     = $_POST['estado'] ?? 'activo';

        if (!$recurso_id || !in_array($estado, ['activo', 'mantenimiento', 'inactivo'])) {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros inválidos']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE saas_recursos SET estado = ? WHERE id = ? AND negocio_id = ?");
            $stmt->execute([$estado, $recurso_id, $negocio_id]);

            logAccionesPanel("Estado del recurso ID {$recurso_id} actualizado a '{$estado}'.");
            echo json_encode(['status' => 'success', 'message' => 'Estado del recurso actualizado']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [cambiar_estado_recurso]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al actualizar estado del recurso']);
        }

    } elseif ($accion === 'eliminar_recurso') {
        $recurso_id = intval($_POST['recurso_id'] ?? 0);

        if (!$recurso_id) {
            echo json_encode(['status' => 'error', 'message' => 'ID de recurso no válido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM saas_recursos WHERE id = ? AND negocio_id = ?");
            $stmt->execute([$recurso_id, $negocio_id]);

            logAccionesPanel("Recurso ID {$recurso_id} eliminado.");
            echo json_encode(['status' => 'success', 'message' => 'Recurso eliminado correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [eliminar_recurso]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al eliminar el recurso']);
        }

    // -------------------------------------------------------------------------
    } elseif ($accion === 'enviar_lista_precios') {
            $telefono_cliente = trim($_POST['cliente_telefono'] ?? '');
            $nombre_cliente   = trim($_POST['cliente_nombre'] ?? 'Cliente');
            $servicios_ids    = $_POST['servicios_ids'] ?? [];

            if (is_string($servicios_ids)) {
                $servicios_ids = json_decode($servicios_ids, true);
            }

            if (!$negocio_id || !$telefono_cliente || empty($servicios_ids)) {
                echo json_encode(['status' => 'error', 'message' => 'Teléfono y al menos un servicio son requeridos.']);
                exit;
            }

            try {
                // 1. Consultar datos del negocio (nombre y slug)
                $stmtNeg = $pdo->prepare("SELECT nombre, slug FROM saas_negocios WHERE id = ?");
                $stmtNeg->execute([$negocio_id]);
                $negocio = $stmtNeg->fetch(PDO::FETCH_ASSOC);

                $negocio_nombre = $negocio['nombre'] ?? 'Nuestro Negocio';
                $negocio_slug   = $negocio['slug'] ?? '';

                // 2. Consultar servicios seleccionados
                $in  = str_repeat('?,', count($servicios_ids) - 1) . '?';
                $params = array_merge([$negocio_id], $servicios_ids);

                $stmtServ = $pdo->prepare("SELECT nombre, precio FROM saas_servicios WHERE negocio_id = ? AND id IN ($in) AND estado = 'activo'");
                $stmtServ->execute($params);
                $servicios = $stmtServ->fetchAll(PDO::FETCH_ASSOC);

                if (empty($servicios)) {
                    echo json_encode(['status' => 'error', 'message' => 'No se encontraron los servicios seleccionados.']);
                    exit;
                }

                // 3. Formatear la lista separada por comas
                $lineas_precios = [];
                $monto_total = 0;
                foreach ($servicios as $s) {
                    $precio_fmt = number_format($s['precio'], 0, ',', '.');
                    $lineas_precios[] = $s['nombre'] . " ($" . $precio_fmt . ")";
                    $monto_total += (float)$s['precio'];
                }

                $detalle_precios = implode(", ", $lineas_precios);
                $total_fmt = number_format($monto_total, 0, ',', '.');

                $to = normalizarTelefonoMeta($telefono_cliente);
                $phoneNumberId = WHATSAPP_PHONE_NUMBER_ID;

                // Control de seguridad para evitar el error #131008
                if (empty($nombre_cliente) || empty($negocio_nombre) || empty($detalle_precios) || empty($total_fmt) || empty($negocio_slug)) {
                    logAccionesPanel("ERROR: Alguna variable para la plantilla de WhatsApp está vacía.");
                    echo json_encode(['status' => 'error', 'message' => 'Faltan datos obligatorios para generar la plantilla.']);
                    exit;
                }

                // 4. Enviar mediante Plantilla con Botón Dinámico de URL
                $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";

                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => 'envio_lista_precios',
                        'language' => ['code' => 'es_AR'],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    ['type' => 'text', 'text' => $negocio_nombre],   // {{1}} del cuerpo
                                    ['type' => 'text', 'text' => $nombre_cliente],  // {{2}} del cuerpo
                                    ['type' => 'text', 'text' => $detalle_precios], // {{3}} del cuerpo
                                    ['type' => 'text', 'text' => $total_fmt]        // {{4}} del cuerpo
                                ]
                            ],
                            [
                                'type' => 'button',
                                'sub_type' => 'url',
                                'index' => '0', // Representa el primer botón de la plantilla
                                'parameters' => [
                                    ['type' => 'text', 'text' => $negocio_slug]      // {{1}} de la URL del botón
                                ]
                            ]
                        ]
                    ]
                ];

                logAccionesPanel("Pauload enviado: " . $payload);

                $response = ejecutarCurlWhatsApp($url, $payload, $to);

                logAccionesPanel("Plantilla lista de precios con botón enviada a {$to} para Negocio ID: {$negocio_id}");

                echo json_encode([
                    'status' => 'success',
                    'message' => 'Lista de precios enviada correctamente por WhatsApp.'
                ]);
            } catch (Exception $e) {
                logAccionesPanel("ERROR [enviar_lista_precios]: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Error al procesar el envío.']);
            }
            exit;
    //
    } elseif ($accion === 'obtener_datos_cobro_turno') {
            $turno_id = (int)($_REQUEST['turno_id'] ?? 0);

            if (!$turno_id) {
                echo json_encode(['status' => 'error', 'message' => 'ID de turno no válido']);
                exit;
            }

            try {
                $stmt = $pdo->prepare("
                    SELECT
                        t.id AS turno_id,
                        t.monto_sena,
                        s.precio AS servicio_precio,
                        s.nombre AS servicio_nombre,
                        c.nombre AS cliente_nombre,
                        n.nombre AS negocio_nombre,
                        n.permite_mercadopago,
                        n.permite_transferencia,
                        n.banco_nombre,
                        n.banco_titular,
                        n.banco_cuit_cuil,
                        n.banco_cbu_cvu,
                        n.banco_alias,
                        n.mp_access_token
                    FROM saas_turnos t
                    JOIN saas_servicios s ON t.servicio_id = s.id
                    JOIN saas_clientes c ON t.cliente_id = c.id
                    JOIN saas_negocios n ON t.negocio_id = n.id
                    WHERE t.id = ? AND t.negocio_id = ?
                ");
                $stmt->execute([$turno_id, $negocio_id]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$data) {
                    echo json_encode(['status' => 'error', 'message' => 'Turno no encontrado']);
                    exit;
                }

                echo json_encode(['status' => 'success', 'data' => $data]);
            } catch (PDOException $e) {
                logAccionesPanel("ERROR BD [obtener_datos_cobro_turno]: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Error al consultar datos de cobro del turno']);
            }
            exit;
    //
} elseif ($accion === 'generar_link_mp') {
        $turno_id = $_POST['turno_id'] ?? 0;
        $monto_personalizado = isset($_POST['monto_personalizado']) ? floatval($_POST['monto_personalizado']) : 0;

        // Obtener datos del turno, negocio y token de MP
        $stmt = $pdo->prepare("
            SELECT t.*, s.nombre AS servicio_nombre, s.precio AS servicio_precio, n.mp_access_token
            FROM saas_turnos t
            INNER JOIN saas_servicios s ON t.servicio_id = s.id
            INNER JOIN saas_negocios n ON t.negocio_id = n.id
            WHERE t.id = ? AND t.negocio_id = ?
        ");
        $stmt->execute([$turno_id, $negocio_id]);
        $turno = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$turno || empty($turno['mp_access_token'])) {
            echo json_encode(['status' => 'error', 'message' => 'No se encontró el turno o el negocio no tiene conectado Mercado Pago.']);
            exit;
        }

        // Si se envió un monto personalizado desde el input editable, se usa ese; sino el del servicio
        $precio = ($monto_personalizado > 0) ? $monto_personalizado : floatval($turno['servicio_precio']);

        // Crear la preferencia usando cURL a la API de Mercado Pago
        $preferenceData = [
            "items" => [
                [
                    "title" => "Turno: " . $turno['servicio_nombre'],
                    "quantity" => 1,
                    "currency_id" => "ARS",
                    "unit_price" => $precio
                ]
            ],
            "external_reference" => "TURNO_" . $turno['id']
        ];

        $ch = curl_init("https://api.mercadopago.com/checkout/preferences");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preferenceData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $turno['mp_access_token'],
            "Content-Type: application/json"
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $resData = json_decode($response, true);

        if (isset($resData['init_point'])) {
            echo json_encode([
                'status' => 'success',
                'init_point' => $resData['init_point']
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al crear preferencia en Mercado Pago.']);
        }
        exit;
 //
    } elseif ($accion === 'obtener_horarios_config') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM saas_horarios_atencion WHERE negocio_id = ?");
            $stmt->execute([$negocio_id]);
            $horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

            logAccionesPanel("Horarios de configuración obtenidos correctamente.");
            echo json_encode(['status' => 'success', 'data' => $horarios]);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [obtener_horarios_config]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al consultar horarios']);
        }

    } elseif ($accion === 'guardar_horarios_config') {
        $activos         = $_POST['activo'] ?? [];
        $aperturas       = $_POST['apertura'] ?? [];
        $cierres         = $_POST['cierre'] ?? [];
        $cierres_manana  = $_POST['cierre_manana'] ?? [];
        $aperturas_tarde = $_POST['apertura_tarde'] ?? [];

        try {
            $stmtDel = $pdo->prepare("DELETE FROM saas_horarios_atencion WHERE negocio_id = ?");
            $stmtDel->execute([$negocio_id]);

            $stmtIns = $pdo->prepare("
                INSERT INTO saas_horarios_atencion 
                (negocio_id, dia_semana, hora_apertura, hora_cierre_manta, hora_apertura_tarde, hora_cierre, esta_activo) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            for ($d = 0; $d < 7; $d++) {
                $esta_activo   = isset($activos[$d]) ? 1 : 0;
                $apertura     = !empty($aperturas[$d]) ? $aperturas[$d] : '09:00';
                $cierre       = !empty($cierres[$d]) ? $cierres[$d] : '18:00';
                $cierre_man   = !empty($cierres_manana[$d]) ? $cierres_manana[$d] : null;
                $apertura_tar = !empty($aperturas_tarde[$d]) ? $aperturas_tarde[$d] : null;

                $stmtIns->execute([$negocio_id, $d, $apertura, $cierre_man, $apertura_tar, $cierre, $esta_activo]);
            }

            logAccionesPanel("Configuración de horarios actualizada para Negocio ID: {$negocio_id}");
            echo json_encode(['status' => 'success', 'message' => 'Horarios actualizados correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [guardar_horarios_config]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar configuración de horarios']);
        }

    } elseif ($accion === 'obtener_config_alerta') {
        try {
            $stmt = $pdo->prepare("SELECT alerta_resumen_activa, alerta_resumen_hora FROM saas_configuracion_agenda WHERE negocio_id = ?");
            $stmt->execute([$negocio_id]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'activa' => $config ? (int)$config['alerta_resumen_activa'] : 1,
                    'hora'   => ($config && !empty($config['alerta_resumen_hora'])) ? substr($config['alerta_resumen_hora'], 0, 5) : '08:00'
                ]
            ]);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [obtener_config_alerta]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al obtener la configuración de alertas']);
        }

    } elseif ($accion === 'guardar_config_alerta') {
        $activa = isset($_POST['alerta_activa']) ? 1 : 0;
        $hora   = $_POST['alerta_hora'] ?? '08:00';
        $hora   = date('H:i:s', strtotime($hora));

        try {
            $sql = "INSERT INTO saas_configuracion_agenda (negocio_id, alerta_resumen_activa, alerta_resumen_hora)
                    VALUES (:negocio_id, :activa, :hora)
                    ON DUPLICATE KEY UPDATE 
                        alerta_resumen_activa = VALUES(alerta_resumen_activa),
                        alerta_resumen_hora = VALUES(alerta_resumen_hora)";
                        
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':negocio_id' => $negocio_id,
                ':activa'     => $activa,
                ':hora'       => $hora
            ]);

            logAccionesPanel("Configuración de alerta actualizada para Negocio ID: {$negocio_id}");
            echo json_encode(['status' => 'success', 'message' => 'Configuración de alertas guardada correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [guardar_config_alerta]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar la configuración de alertas']);
        }

    } elseif ($accion === 'obtener_instagram') {
        try {
            $stmt = $pdo->prepare("SELECT instagram FROM saas_negocios WHERE id = ?");
            $stmt->execute([$negocio_id]);
            $negocio = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $negocio]);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [obtener_instagram]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al obtener Instagram']);
        }

    } elseif ($accion === 'guardar_instagram') {
        $instagram = trim($_POST['instagram'] ?? '');
        $instagram = ltrim($instagram, '@');

        try {
            $stmt = $pdo->prepare("UPDATE saas_negocios SET instagram = ? WHERE id = ?");
            $stmt->execute([$instagram, $negocio_id]);

            logAccionesPanel("Instagram actualizado exitosamente para Negocio ID: {$negocio_id}");
            echo json_encode(['status' => 'success', 'message' => 'Instagram actualizado correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [guardar_instagram]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar el usuario de Instagram']);
        }

    } elseif ($accion === 'obtener_domicilio') {
        try {
            $stmt = $pdo->prepare("SELECT direccion, localidad, latitud, longitud FROM saas_negocios WHERE id = ?");
            $stmt->execute([$negocio_id]);
            $domicilio = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $domicilio]);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [obtener_domicilio]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al obtener el domicilio']);
        }

    } elseif ($accion === 'guardar_domicilio') {
        $direccion = trim($_POST['direccion'] ?? '');
        $localidad = trim($_POST['localidad'] ?? '');
        $latitud   = isset($_POST['latitud']) && $_POST['latitud'] !== '' ? (float)$_POST['latitud'] : null;
        $longitud  = isset($_POST['longitud']) && $_POST['longitud'] !== '' ? (float)$_POST['longitud'] : null;

        try {
            $stmt = $pdo->prepare("UPDATE saas_negocios SET direccion = ?, localidad = ?, latitud = ?, longitud = ? WHERE id = ?");
            $stmt->execute([$direccion, $localidad, $latitud, $longitud, $negocio_id]);

            logAccionesPanel("Domicilio actualizado exitosamente para Negocio ID: {$negocio_id}");
            echo json_encode(['status' => 'success', 'message' => 'Domicilio actualizado correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [guardar_domicilio]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar el domicilio']);
        }

    } elseif ($accion === 'crear_turno' || $accion === 'crear_turno_publico') {
        require_once __DIR__ . '/turnos_helper.php';

        $nombre_cliente   = trim($_POST['cliente_nombre'] ?? '');
        $telefono_cliente = trim($_POST['cliente_telefono'] ?? '');
        $servicio_id      = isset($_POST['servicio_id']) ? (int)$_POST['servicio_id'] : null;
        $recurso_id       = (!empty($_POST['recurso_id']) && (int)$_POST['recurso_id'] > 0) ? (int)$_POST['recurso_id'] : null;
        $fecha            = trim($_POST['fecha'] ?? '');
        $hora             = trim($_POST['hora'] ?? '');

        if (!$negocio_id || !$nombre_cliente || !$servicio_id || !$fecha || !$hora) {
            logAccionesPanel("Validación fallida en [{$accion}]: Datos incompletos. Negocio: {$negocio_id}, Servicio: {$servicio_id}, Fecha: {$fecha}, Hora: {$hora}");
            echo json_encode(['status' => 'error', 'message' => 'Todos los campos son obligatorios']);
            exit;
        }

        // Si se seleccionó "Asignar automáticamente", buscar el primer recurso libre para este horario
        if (empty($recurso_id)) {
            $stmtRecs = $pdo->prepare("SELECT id FROM saas_recursos WHERE negocio_id = ? AND estado = 'activo' ORDER BY id ASC");
            $stmtRecs->execute([$negocio_id]);
            $recursosActivos = $stmtRecs->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($recursosActivos)) {
                $stmtServ = $pdo->prepare("SELECT duracion_minutos FROM saas_servicios WHERE id = ? AND negocio_id = ?");
                $stmtServ->execute([$servicio_id, $negocio_id]);
                $duracion = (int)($stmtServ->fetchColumn() ?: 30);

                $hora_limpia = (strlen($hora) === 5) ? "{$hora}:00" : $hora;
                $inicio = date('Y-m-d H:i:s', strtotime("{$fecha} {$hora_limpia}"));
                $fin    = date('Y-m-d H:i:s', strtotime("{$fecha} {$hora_limpia}") + ($duracion * 60));

                foreach ($recursosActivos as $idRec) {
                    $stmtChk = $pdo->prepare("
                        SELECT COUNT(*) FROM saas_turnos 
                        WHERE negocio_id = ? 
                          AND recurso_id = ? 
                          AND (estado IS NULL OR LOWER(estado) != 'cancelado') 
                          AND (? < fecha_hora_fin AND ? > fecha_hora_inicio)
                    ");
                    $stmtChk->execute([$negocio_id, $idRec, $inicio, $fin]);
                    if ($stmtChk->fetchColumn() == 0) {
                        $recurso_id = $idRec;
                        break;
                    }
                }
            }
        }

        $origen = ($accion === 'crear_turno_publico') ? 'publico' : 'panel';
        $res = agendarTurnoCompleto($pdo, $negocio_id, $nombre_cliente, $telefono_cliente, $servicio_id, $fecha, $hora, $origen, $recurso_id);

        if ($res['status'] === 'success') {
            if (!empty($res['requiere_sena'])) {
                echo json_encode([
                    'status'        => 'success',
                    'message'       => 'Turno reservado. Se requiere pago de seña.',
                    'requiere_sena' => true,
                    'metodo_pago'   => $res['metodo_pago'],
                    'link_pago'     => $res['link_pago']
                ]);
            } else {
                echo json_encode([
                    'status'  => 'success', 
                    'message' => 'Turno agendado correctamente'
                ]);
            }
        } else {
            echo json_encode($res);
        }
        exit;
	} elseif ($accion === 'obtener_datos_cobro') {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    permite_mercadopago, 
                    permite_transferencia, 
                    banco_nombre, 
                    banco_titular, 
                    banco_cuit_cuil, 
                    banco_cbu_cvu, 
                    banco_alias, 
                    banco_instrucciones 
                FROM saas_negocios 
                WHERE id = ?
            ");
            $stmt->execute([$negocio_id]);
            $datos = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $datos]);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [obtener_datos_cobro]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al consultar datos de cobro']);
        }

    } elseif ($accion === 'guardar_datos_cobro') {
        $metodo_activo  = $_POST['metodo_pago_activo'] ?? 'mercadopago';
        $permite_mp     = ($metodo_activo === 'mercadopago') ? 1 : 0;
        $permite_transf = ($metodo_activo === 'transferencia') ? 1 : 0;
        
        $banco_nombre   = trim($_POST['banco_nombre'] ?? '');
        $banco_titular  = trim($_POST['banco_titular'] ?? '');
        $banco_cuit     = trim($_POST['banco_cuit_cuil'] ?? '');
        $banco_cbu      = trim($_POST['banco_cbu_cvu'] ?? '');
        $banco_alias    = trim($_POST['banco_alias'] ?? '');
        $banco_instruc  = trim($_POST['banco_instrucciones'] ?? '');

        try {
            $stmt = $pdo->prepare("
                UPDATE saas_negocios 
                SET 
                    permite_mercadopago = ?,
                    permite_transferencia = ?,
                    banco_nombre = ?,
                    banco_titular = ?,
                    banco_cuit_cuil = ?,
                    banco_cbu_cvu = ?,
                    banco_alias = ?,
                    banco_instrucciones = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $permite_mp,
                $permite_transf,
                $banco_nombre,
                $banco_titular,
                $banco_cuit,
                $banco_cbu,
                $banco_alias,
                $banco_instruc,
                $negocio_id
            ]);

            logAccionesPanel("Datos de cobro guardados para Negocio ID: {$negocio_id} (Método activo: {$metodo_activo})");
            echo json_encode(['status' => 'success', 'message' => 'Datos de cobro guardados correctamente']);
        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [guardar_datos_cobro]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar datos de cobro']);
        }

    } elseif ($accion === 'obtener_pendientes_revision') {
        $stmt = $pdo->prepare("
            SELECT 
                t.id AS turno_id,
                t.fecha_hora_inicio,
                t.monto_sena,
                c.nombre AS cliente_nombre,
                c.telefono AS cliente_telefono,
                s.nombre AS servicio_nombre,
                cp.monto_detectado,
                cp.numero_operacion,
                cp.nombre_origen,
                cp.banco_origen,
                cp.url_comprobante
            FROM saas_turnos t
            JOIN saas_clientes c ON t.cliente_id = c.id
            JOIN saas_servicios s ON t.servicio_id = s.id
            LEFT JOIN saas_comprobantes_pago cp ON cp.turno_id = t.id
            WHERE t.negocio_id = ? AND t.estado = 'pendiente_revision'
            ORDER BY t.fecha_hora_inicio ASC
        ");
        $stmt->execute([$negocio_id]);
        $turnos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($turnos as $t) {
            $data[] = [
                'turno_id'         => $t['turno_id'],
                'cliente_nombre'   => $t['cliente_nombre'],
                'cliente_telefono' => $t['cliente_telefono'],
                'servicio_nombre'  => $t['servicio_nombre'],
                'monto_sena'       => number_format($t['monto_sena'], 0, ',', '.'),
                'fecha_fmt'        => date('d/m/Y', strtotime($t['fecha_hora_inicio'])),
                'hora_fmt'         => date('H:i', strtotime($t['fecha_hora_inicio'])),
                'monto_detectado'  => $t['monto_detectado'] ? number_format($t['monto_detectado'], 0, ',', '.') : null,
                'numero_operacion' => $t['numero_operacion'] ?: 'N/D',
                'nombre_origen'    => $t['nombre_origen'] ?: 'No detectado',
                'banco_origen'     => $t['banco_origen'] ?: 'No detectado',
                'url_comprobante'  => $t['url_comprobante'] ?? ''
            ];
        }

        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;

    } elseif ($accion === 'validar_comprobante') {
        $turno_id     = (int)($_POST['turno_id'] ?? 0);
        $nuevo_estado = $_POST['estado'] ?? 'confirmado';

        if ($turno_id) {
            $stmt = $pdo->prepare("UPDATE saas_turnos SET estado = ? WHERE id = ? AND negocio_id = ?");
            $stmt->execute([$nuevo_estado, $turno_id, $negocio_id]);

            $stmtInfo = $pdo->prepare("
                SELECT 
                    c.nombre AS cliente_nombre,
                    c.telefono AS cliente_telefono,
                    s.nombre AS servicio_nombre,
                    t.fecha_hora_inicio,
                    n.nombre AS negocio_nombre,
                    n.instagram AS negocio_instagram
                FROM saas_turnos t 
                JOIN saas_clientes c ON t.cliente_id = c.id 
                JOIN saas_servicios s ON t.servicio_id = s.id 
                JOIN saas_negocios n ON t.negocio_id = n.id
                WHERE t.id = ? AND t.negocio_id = ?
            ");
            $stmtInfo->execute([$turno_id, $negocio_id]);
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            if ($info && !empty($info['cliente_telefono'])) {
                $fechaHora     = strtotime($info['fecha_hora_inicio']);
                $fechaFmt      = date('d/m/Y', $fechaHora);
                $horaFmt       = date('H:i', $fechaHora);
                $phoneNumberId = WHATSAPP_PHONE_NUMBER_ID;
                $instagram_fmt = !empty($info['negocio_instagram']) ? '@' . ltrim($info['negocio_instagram'], '@') : '';

                if ($nuevo_estado === 'confirmado') {
                    logAccionesPanel("Comprobante APROBADO: Enviando plantilla de confirmación a {$info['cliente_telefono']}");
                    enviarPlantillaConfirmacion(
                        $info['cliente_telefono'],
                        $info['cliente_nombre'],
                        $info['negocio_nombre'],
                        $instagram_fmt,
                        $info['servicio_nombre'],
                        $fechaFmt,
                        $horaFmt,
                        $phoneNumberId
                    );
                } elseif ($nuevo_estado === 'cancelado') {
                    logAccionesPanel("Comprobante RECHAZADO: Enviando plantilla de cancelación a {$info['cliente_telefono']}");
                    enviarPlantillaCancelacion(
                        $info['cliente_telefono'],
                        $info['cliente_nombre'],
                        $fechaFmt,
                        $horaFmt,
                        $info['negocio_nombre'],
                        $phoneNumberId
                    );
                }
            }

            echo json_encode(['status' => 'success', 'message' => "Comprobante procesado como {$nuevo_estado}"]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ID de turno no válido']);
        }
        exit;

    } elseif ($accion === 'cambiar_estado') {
        logAccionesPanel("Entro a cambiar estado");
                    
        require_once __DIR__ . '/turnos_helper.php';
        logAccionesPanel("Cargo turnos helper");
        
        $turno_id_raw = $_POST['turno_id'] ?? '';
        $turno_id     = (int) preg_replace('/[^0-9]/', '', $turno_id_raw);
        $nuevo_estado = $_POST['estado'] ?? '';

        if (!$turno_id || !in_array($nuevo_estado, ['cancelado', 'completado', 'confirmado'])) {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros inválidos']);
            exit;
        }

        logAccionesPanel("el nuevo estado es: ".$nuevo_estado);
        
        if ($nuevo_estado === 'cancelado') {
            logAccionesPanel("llama al cancelar del helper");
        
            $res = cancelarTurnoCompleto($pdo, $turno_id, $negocio_id);
            echo json_encode($res);
        } else {
            $stmt = $pdo->prepare("UPDATE saas_turnos SET estado = ? WHERE id = ? AND negocio_id = ?");
            $stmt->execute([$nuevo_estado, $turno_id, $negocio_id]);
            echo json_encode(['status' => 'success', 'message' => 'Estado actualizado correctamente']);
        }
        exit;

    } elseif ($accion === 'obtener_horarios_disponibles') {
        $fecha       = $_GET['fecha'] ?? '';
        $servicio_id = isset($_GET['servicio_id']) ? (int)$_GET['servicio_id'] : null;
        $recurso_id  = (!empty($_GET['recurso_id']) && (int)$_GET['recurso_id'] > 0) ? (int)$_GET['recurso_id'] : null;

        if (!$negocio_id || !$fecha || !$servicio_id) {
            echo json_encode(['status' => 'error', 'message' => 'Negocio ID, fecha y servicio requeridos']);
            exit;
        }

        try {
            date_default_timezone_set('America/Argentina/Cordoba');

            // 1. Duración del servicio
            $stmtServ = $pdo->prepare("SELECT duracion_minutos FROM saas_servicios WHERE id = ? AND negocio_id = ?");
            $stmtServ->execute([$servicio_id, $negocio_id]);
            $serv = $stmtServ->fetch(PDO::FETCH_ASSOC);
            $duracion_min = (int)($serv['duracion_minutos'] ?? 30);

            // 2. Horarios de atención para el día de la semana
            $diaSemana = (int)date('w', strtotime($fecha));
            $stmtCfg = $pdo->prepare("
                SELECT hora_apertura, hora_cierre_manta, hora_apertura_tarde, hora_cierre 
                FROM saas_horarios_atencion 
                WHERE negocio_id = ? AND dia_semana = ? AND esta_activo = 1
            ");
            $stmtCfg->execute([$negocio_id, $diaSemana]);
            $horariosAtencion = $stmtCfg->fetchAll(PDO::FETCH_ASSOC);

            if (empty($horariosAtencion)) {
                echo json_encode(['status' => 'success', 'data' => []]);
                exit;
            }

            // 3. Obtener Recursos Activos a evaluar
            $recursosEvaluar = [];
            if ($recurso_id) {
                $recursosEvaluar[] = $recurso_id;
            } else {
                $stmtRec = $pdo->prepare("SELECT id FROM saas_recursos WHERE negocio_id = ? AND estado = 'activo'");
                $stmtRec->execute([$negocio_id]);
                $recursosEvaluar = $stmtRec->fetchAll(PDO::FETCH_COLUMN);
            }

            // 4. Obtener Turnos Ocupados del día
            $stmtTurnos = $pdo->prepare("
                SELECT recurso_id, fecha_hora_inicio, fecha_hora_fin 
                FROM saas_turnos 
                WHERE negocio_id = ? 
                  AND DATE(fecha_hora_inicio) = ? 
                  AND (estado IS NULL OR LOWER(estado) != 'cancelado')
            ");
            $stmtTurnos->execute([$negocio_id, $fecha]);
            $turnosOcupados = $stmtTurnos->fetchAll(PDO::FETCH_ASSOC);

            // 5. Obtener Bloqueos de Agenda del día
            $stmtBloqueos = $pdo->prepare("
                SELECT fecha_hora_inicio, fecha_hora_fin 
                FROM saas_bloqueos 
                WHERE negocio_id = ? 
                  AND DATE(fecha_hora_inicio) = ?
            ");
            $stmtBloqueos->execute([$negocio_id, $fecha]);
            $bloqueos = $stmtBloqueos->fetchAll(PDO::FETCH_ASSOC);

            $horariosDisponibles = [];
            $ahoraTs = time();
            $esHoy = ($fecha === date('Y-m-d'));

            foreach ($horariosAtencion as $rango) {
                $tramos = [];
                if (!empty($rango['hora_cierre_manta']) && !empty($rango['hora_apertura_tarde'])) {
                    $tramos[] = ['inicio' => $rango['hora_apertura'], 'fin' => $rango['hora_cierre_manta']];
                    $tramos[] = ['inicio' => $rango['hora_apertura_tarde'], 'fin' => $rango['hora_cierre']];
                } else {
                    $tramos[] = ['inicio' => $rango['hora_apertura'], 'fin' => $rango['hora_cierre']];
                }

                foreach ($tramos as $tramo) {
                    $inicioTs = strtotime("{$fecha} {$tramo['inicio']}");
                    $cierreTs = strtotime("{$fecha} {$tramo['fin']}");

                    while ($inicioTs + ($duracion_min * 60) <= $cierreTs) {
                        $finSlotTs = $inicioTs + ($duracion_min * 60);

                        // Si es hoy, descartar horarios pasados
                        if ($esHoy && $inicioTs <= $ahoraTs) {
                            $inicioTs += $duracion_min * 60;
                            continue;
                        }

                        // Verificar Bloqueos Generales
                        $hayBloqueo = false;
                        foreach ($bloqueos as $bloqueo) {
                            $bInicio = strtotime($bloqueo['fecha_hora_inicio']);
                            $bFin    = strtotime($bloqueo['fecha_hora_fin']);
                            if ($inicioTs < $bFin && $finSlotTs > $bInicio) {
                                $hayBloqueo = true;
                                break;
                            }
                        }

                        if (!$hayBloqueo) {
                            if (!empty($recursosEvaluar)) {
                                $recursoLibreEncontrado = false;

                                // Recorrer cada recurso para ver si al menos uno está disponible en este horario
                                foreach ($recursosEvaluar as $idRec) {
                                    $recOcupado = false;

                                    foreach ($turnosOcupados as $t) {
                                        $tInicio = strtotime($t['fecha_hora_inicio']);
                                        $tFin    = strtotime($t['fecha_hora_fin']);

                                        // Si el turno pertenece a este recurso o no tenía recurso asignado
                                        if (($t['recurso_id'] == $idRec || is_null($t['recurso_id'])) && ($inicioTs < $tFin && $finSlotTs > $tInicio)) {
                                            $recOcupado = true;
                                            break;
                                        }
                                    }

                                    if (!$recOcupado) {
                                        $recursoLibreEncontrado = true;
                                        break; // Encontramos al menos 1 recurso libre para esta hora
                                    }
                                }

                                if ($recursoLibreEncontrado) {
                                    $horariosDisponibles[] = date('H:i', $inicioTs);
                                }
                            } else {
                                // Si el negocio no tiene recursos creados, se evalúan turnos generales
                                $ocupado = false;
                                foreach ($turnosOcupados as $t) {
                                    $tInicio = strtotime($t['fecha_hora_inicio']);
                                    $tFin    = strtotime($t['fecha_hora_fin']);
                                    if ($inicioTs < $tFin && $finSlotTs > $tInicio) {
                                        $ocupado = true;
                                        break;
                                    }
                                }

                                if (!$ocupado) {
                                    $horariosDisponibles[] = date('H:i', $inicioTs);
                                }
                            }
                        }

                        $inicioTs += $duracion_min * 60;
                    }
                }
            }

            echo json_encode(['status' => 'success', 'data' => array_values(array_unique($horariosDisponibles))]);

        } catch (PDOException $e) {
            logAccionesPanel("ERROR BD [obtener_horarios_disponibles]: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error al calcular horarios disponibles']);
        }
    } else {
        logAccionesPanel("Acción no reconocida: '{$accion}'");
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
    }

} catch (Exception $e) {
    logAccionesPanel("EXCEPCIÓN GENERAL: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error interno en el servidor']);
}

// -----------------------------------------------------------------------------
// 3. FUNCIONES AUXILIARES
// -----------------------------------------------------------------------------

function normalizarTelefonoMeta($telefono) {
    return $telefono;
}

function enviarPlantillaConfirmacion($to, $nombreCliente, $nombreNegocio, $instagram, $nombreServicio, $fechaFmt, $horaFmt, $phoneNumberId) {
    $to  = normalizarTelefonoMeta($to);
    $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";
    
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => 'confirmacion_turno_manual',
            'language' => ['code' => 'es_AR'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreCliente],
                        ['type' => 'text', 'text' => $nombreNegocio],
                        ['type' => 'text', 'text' => $instagram],
                        ['type' => 'text', 'text' => $nombreServicio],
                        ['type' => 'text', 'text' => $fechaFmt],
                        ['type' => 'text', 'text' => $horaFmt]
                    ]
                ]
            ]
        ]
    ];

    return ejecutarCurlWhatsApp($url, $payload, $to);
}

function enviarMensajeSenaWhatsApp($to, $nombreCliente, $nombreServicio, $nombreNegocio, $fechaFmt, $horaFmt, $montoSena, $linkPago, $phoneNumberId) {
    $to  = normalizarTelefonoMeta($to);
    $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";
    
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => 'envio_link_sena',
            'language' => ['code' => 'es_AR'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreCliente],
                        ['type' => 'text', 'text' => $nombreServicio],
                        ['type' => 'text', 'text' => $nombreNegocio],
                        ['type' => 'text', 'text' => $fechaFmt],
                        ['type' => 'text', 'text' => $horaFmt],
                        ['type' => 'text', 'text' => number_format($montoSena, 2, ',', '.')],
                        ['type' => 'text', 'text' => $linkPago]
                    ]
                ]
            ]
        ]
    ];

    return ejecutarCurlWhatsApp($url, $payload, $to);
}

function enviarMensajeTransferenciaWhatsApp($to, $nombreCliente, $nombreServicio, $nombreNegocio, $fechaFmt, $horaFmt, $montoSena, $datosBanco, $phoneNumberId) {
    $to  = normalizarTelefonoMeta($to);
    $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";

    $bancoNombre  = !empty($datosBanco['banco_nombre']) ? $datosBanco['banco_nombre'] : 'Consultar';
    $bancoTitular = !empty($datosBanco['banco_titular']) ? $datosBanco['banco_titular'] : 'Consultar';
    $bancoAlias   = !empty($datosBanco['banco_alias']) ? $datosBanco['banco_alias'] : 'No especificado';
    $bancoCbu     = !empty($datosBanco['banco_cbu_cvu']) ? $datosBanco['banco_cbu_cvu'] : 'No especificado';

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => 'envio_datos_transferencia',
            'language' => ['code' => 'en'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreCliente],
                        ['type' => 'text', 'text' => $nombreServicio],
                        ['type' => 'text', 'text' => $nombreNegocio],
                        ['type' => 'text', 'text' => $fechaFmt],
                        ['type' => 'text', 'text' => $horaFmt],
                        ['type' => 'text', 'text' => number_format($montoSena, 2, ',', '.')],
                        ['type' => 'text', 'text' => $bancoNombre],
                        ['type' => 'text', 'text' => $bancoTitular],
                        ['type' => 'text', 'text' => $bancoAlias],
                        ['type' => 'text', 'text' => $bancoCbu]
                    ]
                ]
            ]
        ]
    ];

    return ejecutarCurlWhatsApp($url, $payload, $to);
}

function enviarPlantillaCancelacion($to, $nombreCliente, $fechaFmt, $horaFmt, $nombreNegocio, $phoneNumberId) {
    $to  = normalizarTelefonoMeta($to);
    $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";
    
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => 'cancelacion_turno_manual',
            'language' => ['code' => 'es_AR'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreCliente],
                        ['type' => 'text', 'text' => $fechaFmt],
                        ['type' => 'text', 'text' => $horaFmt],
                        ['type' => 'text', 'text' => $nombreNegocio]
                    ]
                ]
            ]
        ]
    ];

    return ejecutarCurlWhatsApp($url, $payload, $to);
}

function ejecutarCurlWhatsApp($url, $payload, $telefono) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . WHATSAPP_ACCESS_TOKEN,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        logAccionesPanel("ERROR cURL enviando Plantilla WhatsApp a {$telefono}: " . $err);
    } else {
        logAccionesPanel("Respuesta API Plantilla WhatsApp para {$telefono}: " . $res);
    }

    return $res;
}