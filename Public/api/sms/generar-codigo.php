<?php
// api/sms/generar-codigo.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../conexion.php';

$negocioId = isset($_SESSION['negocio_id']) ? (int)$_SESSION['negocio_id'] : 0;

if ($negocioId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok'    => false,
        'error' => 'Sesión no iniciada o negocio_id inválido'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$nombreSugerido = trim((string)($data['nombre'] ?? 'Celular SMS'));
if ($nombreSugerido === '') {
    $nombreSugerido = 'Celular SMS';
}

try {
    // Código legible de 6 dígitos (ej. 482915)
    $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $venceAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    $stmt = $pdo->prepare("
        INSERT INTO saas_sms_codigos_vinculacion (negocio_id, codigo, nombre_sugerido, vence_at)
        VALUES (:negocio_id, :codigo, :nombre, :vence_at)
    ");
    $stmt->execute([
        ':negocio_id' => $negocioId,
        ':codigo'     => $codigo,
        ':nombre'     => $nombreSugerido,
        ':vence_at'   => $venceAt,
    ]);

    echo json_encode([
        'ok'       => true,
        'codigo'   => $codigo,
        'vence_at' => $venceAt,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Error al generar el código de vinculación'
    ]);
}