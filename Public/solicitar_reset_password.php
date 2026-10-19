<?php
header('Content-Type: application/json');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$email = trim($input['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Por favor ingresá un email válido.']);
    exit;
}

try {
    // 1. Verificar si el email pertenece a un negocio registrado
    $stmtCheck = $pdo->prepare("SELECT id, nombre FROM saas_negocios WHERE email = ?");
    $stmtCheck->execute([$email]);
    $negocio = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$negocio) {
        // Por seguridad o claridad del usuario
        echo json_encode(['status' => 'error', 'message' => 'El email ingresado no corresponde a ningún negocio registrado.']);
        exit;
    }

    // 2. Generar código de 6 dígitos con 15 minutos de validez
    $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $expira = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    // Limpiar códigos anteriores de este email
    $stmtDel = $pdo->prepare("DELETE FROM saas_reset_passwords WHERE email = ?");
    $stmtDel->execute([$email]);

    // Insertar código
    $stmtIns = $pdo->prepare("INSERT INTO saas_reset_passwords (email, codigo_verificacion, expira_el) VALUES (?, ?, ?)");
    $stmtIns->execute([$email, $codigo, $expira]);

    // 3. Enviar email con el código
    $asunto = "Código de Recuperación de Contraseña - Secretario IA";
    $mensaje = "Hola " . $negocio['nombre'] . ",\n\n" .
               "Recibimos una solicitud para restablecer tu contraseña.\n" .
               "Tu código de verificación es: " . $codigo . "\n\n" .
               "Este código expirará en 15 minutos. Si no solicitaste este cambio, ignorá este correo.\n";

    $headers = "From: no-reply@" . $_SERVER['SERVER_NAME'] . "\r\n" .
               "Reply-To: no-reply@" . $_SERVER['SERVER_NAME'] . "\r\n" .
               "X-Mailer: PHP/" . phpversion();

    @mail($email, $asunto, $mensaje, $headers);

    echo json_encode(['status' => 'success', 'message' => 'Código de recuperación enviado correctamente.']);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error en base de datos: ' . $e->getMessage()]);
}