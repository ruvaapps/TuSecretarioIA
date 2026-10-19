<?php
header('Content-Type: application/json');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$email          = trim($input['email'] ?? '');
$codigo         = trim($input['codigo'] ?? '');
$nueva_password = trim($input['nueva_password'] ?? '');

if (empty($email) || empty($codigo) || empty($nueva_password)) {
    echo json_encode(['status' => 'error', 'message' => 'Todos los campos son obligatorios.']);
    exit;
}

if (strlen($nueva_password) < 6) {
    echo json_encode(['status' => 'error', 'message' => 'La contraseña debe tener al menos 6 caracteres.']);
    exit;
}

try {
    // 1. Validar código y fecha de expiración
    $stmt = $pdo->prepare("SELECT * FROM saas_reset_passwords WHERE email = ? AND codigo_verificacion = ? AND expira_el >= NOW()");
    $stmt->execute([$email, $codigo]);
    $resetReq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetReq) {
        echo json_encode(['status' => 'error', 'message' => 'Código incorrecto o expirado. Solicitá uno nuevo.']);
        exit;
    }

    // 2. Hash de la nueva contraseña y actualización en saas_negocios
    $password_hash = password_hash($nueva_password, PASSWORD_BCRYPT);

    $stmtUpd = $pdo->prepare("UPDATE saas_negocios SET password_hash = ? WHERE email = ?");
    $stmtUpd->execute([$password_hash, $email]);

    // 3. Eliminar código usado
    $stmtDel = $pdo->prepare("DELETE FROM saas_reset_passwords WHERE email = ?");
    $stmtDel->execute([$email]);

    echo json_encode(['status' => 'success', 'message' => 'Contraseña actualizada correctamente.']);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error al actualizar la contraseña: ' . $e->getMessage()]);
}