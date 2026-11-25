<?php
// generar-codigo.php
header('Content-Type: application/json; charset=utf-8');

// Acá obtenés el $negocioId del usuario/panel autenticado en Secretario IA
$negocioId = (int)($_SESSION['negocio_id'] ?? ($_POST['negocio_id'] ?? 0));
$nombreSugerido = trim($_POST['nombre'] ?? 'Celular SMS');

if ($negocioId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'negocio_id inválido']);
    exit;
}

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