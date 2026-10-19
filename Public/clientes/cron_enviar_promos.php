<?php
// clientes/cron_enviar_promos.php
// Este script debe ser ejecutado por la CLI de PHP o vía Cron Job

if (php_sapi_name() !== 'cli' && (!isset($_GET['token']) || $_GET['token'] !== 'TU_TOKEN_SECRETO_CRON')) {
    http_response_code(403);
    die('Acceso denegado.');
}

require_once __DIR__ . '/../conexion.php'; // Ajusta la ruta a tu conexión PDO

if (!defined('WHATSAPP_PHONE_NUMBER_ID')) {
    define('WHATSAPP_PHONE_NUMBER_ID', '1297893416744537');
}

if (!defined('WHATSAPP_ACCESS_TOKEN')) {
    define('WHATSAPP_ACCESS_TOKEN', 'EAAUJIfJBzp0BSdlljUGVo0bDbADhLC6I6ilZCVZBBtBqXmWMZBckitoaIfdlQKqXEZCIM7hRFESbifOTgFkF9CmiIwFB4rYxrTYhP2HTGpRZAuHbX8594DCgnE64PHjVxTEMYBGvsi8UvUtROzB1VioWurXTctZAeVg7xAxbDYLgdcoBSzFUCn5G4IZABNvtZAUZApQZDZD');
}

$limitePorEjecucion = 5;
$logFile = __DIR__ . '/cron_promos.log';

/**
 * Función auxiliar para registrar logs localmente y en pantalla
 */
function escribirLog($mensaje, $archivo) {
    $linea = "[" . date('Y-m-d H:i:s') . "] " . $mensaje . "\n";
    file_put_contents($archivo, $linea, FILE_APPEND);
    echo $linea;
}

try {
    // 1. Obtener los envíos pendientes uniendo con el nombre y slug del negocio
    $sql = "
        SELECT 
            e.id AS envio_id,
            e.campana_id,
            e.cliente_id,
            p.negocio_id,
            p.mensaje AS mensaje_plantilla,
            c.nombre AS cliente_nombre,
            c.telefono AS cliente_telefono,
            n.nombre AS negocio_nombre,
            n.slug AS negocio_slug
        FROM saas_campanas_envios e
        INNER JOIN saas_campanas_promos p ON e.campana_id = p.id
        INNER JOIN saas_clientes c ON e.cliente_id = c.id
        INNER JOIN saas_negocios n ON p.negocio_id = n.id
        WHERE e.estado = 'pendiente' AND p.estado = 'en_proceso'
        ORDER BY e.id ASC
        LIMIT :limite
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limite', $limitePorEjecucion, PDO::PARAM_INT);
    $stmt->execute();
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendientes)) {
        // Marcar campañas finalizadas si no quedan envíos pendientes
        $pdo->exec("
            UPDATE saas_campanas_promos p
            SET p.estado = 'completada'
            WHERE p.estado = 'en_proceso'
              AND NOT EXISTS (
                  SELECT 1 FROM saas_campanas_envios e 
                  WHERE e.campana_id = p.id AND e.estado = 'pendiente'
              )
        ");
        escribirLog("Sin envíos pendientes.", $logFile);
        exit;
    }

    escribirLog("Procesando " . count($pendientes) . " mensajes mediante Template...", $logFile);

    foreach ($pendientes as $item) {
        $envioId = $item['envio_id'];
        $telefono = preg_replace('/[^0-9]/', '', $item['cliente_telefono']); // Limpiar caracteres no numéricos
        $nombreCliente = !empty($item['cliente_nombre']) ? $item['cliente_nombre'] : 'Estimado/a';
        $negocioNombre = !empty($item['negocio_nombre']) ? $item['negocio_nombre'] : 'Nuestro negocio';
        $negocioSlug   = !empty($item['negocio_slug']) ? $item['negocio_slug'] : '';
        $textoPromo    = $item['mensaje_plantilla'];

        // Generación dinámica del link para reservar el turno
        $linkReserva = "https://tusecretarioia.lovar.com.ar/reserva.php?slug=" . $negocioSlug;

        // 2. Enviar mensaje usando el Template homologado por Meta
        $resultadoEnvio = enviarWhatsAppTemplateApi($telefono, $nombreCliente, $negocioNombre, $textoPromo, $linkReserva, $logFile);

        if ($resultadoEnvio['exito']) {
            $stmtUpd = $pdo->prepare("
                UPDATE saas_campanas_envios 
                SET estado = 'enviado', enviado_el = NOW() 
                WHERE id = :id
            ");
            $stmtUpd->execute([':id' => $envioId]);
            escribirLog(" -> Template enviado OK a {$telefono} (WAMID: " . ($resultadoEnvio['wamid'] ?? 'N/A') . ")", $logFile);
        } else {
            $stmtUpd = $pdo->prepare("
                UPDATE saas_campanas_envios 
                SET estado = 'fallido', error_mensaje = :error 
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':error' => substr($resultadoEnvio['error'], 0, 250),
                ':id' => $envioId
            ]);
            escribirLog(" -> Fallo al enviar Template a {$telefono}: {$resultadoEnvio['error']}", $logFile);
        }

        // Pausa aleatoria entre 2 y 4 segundos entre cada envío
        sleep(rand(2, 4));
    }

} catch (Exception $e) {
    escribirLog("ERROR CRON: " . $e->getMessage(), $logFile);
}

/**
 * Función para realizar el envío de la Plantilla (Template) a WhatsApp Cloud API
 */
function enviarWhatsAppTemplateApi($telefono, $nombreCliente, $negocioNombre, $textoPromo, $linkReserva, $logFile) {
    $phoneAccountId = WHATSAPP_PHONE_NUMBER_ID; 
    $accessToken = WHATSAPP_ACCESS_TOKEN; 
	
    $url = "https://graph.facebook.com/v18.0/{$phoneAccountId}/messages";

    // Estructura oficial del payload para templates en WhatsApp Cloud API con 4 parámetros
    $payload = [
        "messaging_product" => "whatsapp",
        "to" => $telefono,
        "type" => "template",
        "template" => [
            "name" => "promo_fidelizacion", // Nombre de la plantilla aprobada en Meta
            "language" => [
                "code" => "es_AR" // Idioma configurado en Meta (es_AR o es)
            ],
            "components" => [
                [
                    "type" => "body",
                    "parameters" => [
                        ["type" => "text", "text" => $nombreCliente],  // {{1}} Nombre Cliente
                        ["type" => "text", "text" => $negocioNombre],  // {{2}} Nombre Negocio
                        ["type" => "text", "text" => $textoPromo],     // {{3}} Detalle Promo
                        ["type" => "text", "text" => $linkReserva]     // {{4}} Link de Reserva
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $accessToken,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Registro del Log RAW devuelto por Meta
    escribirLog("RESPONSE TEMPLATE HTTP {$httpCode}: {$response}", $logFile);

    if ($curlError) {
        return ['exito' => false, 'error' => $curlError];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $json = json_decode($response, true);
        $wamid = $json['messages'][0]['id'] ?? null;
        return ['exito' => true, 'wamid' => $wamid];
    } else {
        return ['exito' => false, 'error' => "HTTP {$httpCode}: " . $response];
    }
}