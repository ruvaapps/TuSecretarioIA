<?php
// enviar_soporte.php

// Configurar encabezados para responder en JSON
header('Content-Type: application/json; charset=utf-8');

// Permitir solo peticiones POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Método no permitido.'
    ]);
    exit;
}

// Obtener los datos del cuerpo de la petición (JSON)
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

// Verificar si se pudo decodificar el JSON o si vino por $_POST tradicional
if (!$input) {
    $input = $_POST;
}

// Extraer y limpiar los campos
$nombre  = isset($input['nombre']) ? trim(strip_tags($input['nombre'])) : '';
$email   = isset($input['email']) ? trim(strip_tags($input['email'])) : '';
$motivo  = isset($input['motivo']) ? trim(strip_tags($input['motivo'])) : '';
$mensaje = isset($input['mensaje']) ? trim(strip_tags($input['mensaje'])) : '';

// Validar campos obligatorios
if (empty($nombre) || empty($email) || empty($mensaje)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Por favor completa todos los campos requeridos.'
    ]);
    exit;
}

// Validar formato de email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Ingresa un correo electrónico válido.'
    ]);
    exit;
}

// Mapear el motivo a una etiqueta legible
$motivosTexto = [
    'consulta_general' => 'Consulta General',
    'soporte_tecnico'  => 'Soporte Técnico / Error',
    'reclamo'          => 'Reclamo',
    'facturacion'      => 'Facturación / Pagos',
    'otro'             => 'Otro'
];

$motivoLabel = isset($motivosTexto[$motivo]) ? $motivosTexto[$motivo] : $motivo;

// Configuración del correo destino
$to = 'sporte_secretarioia@lovar.com.ar'; // Correo donde recibirás las consultas
$subject = "Soporte Secretario IA: {$motivoLabel} - {$nombre}";

// Cuerpo del mensaje (Formato HTML)
$bodyHtml = "
<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; background-color: #0f172a; color: #f8fafc; padding: 20px; }
        .card { background-color: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 24px; max-width: 600px; margin: 0 auto; }
        h2 { color: #10b981; margin-top: 0; }
        .field { margin-bottom: 12px; }
        .label { font-weight: bold; color: #94a3b8; font-size: 12px; text-transform: uppercase; }
        .value { font-size: 14px; color: #f1f5f9; margin-top: 4px; }
        .message-box { background-color: #0f172a; border: 1px solid #334155; padding: 16px; border-radius: 8px; margin-top: 16px; white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class='card'>
        <h2>Nueva Consulta de Soporte</h2>
        
        <div class='field'>
            <div class='label'>Nombre / Negocio:</div>
            <div class='value'>".htmlspecialchars($nombre)."</div>
        </div>
        
        <div class='field'>
            <div class='label'>Email de contacto:</div>
            <div class='value'>".htmlspecialchars($email)."</div>
        </div>
        
        <div class='field'>
            <div class='label'>Motivo:</div>
            <div class='value'>".htmlspecialchars($motivoLabel)."</div>
        </div>
        
        <div class='field'>
            <div class='label'>Mensaje:</div>
            <div class='message-box'>".nl2br(htmlspecialchars($mensaje))."</div>
        </div>
    </div>
</body>
</html>
";

// Encabezados del correo
$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: Secretario IA <no-reply@lovar.com.ar>\r\n";
$headers .= "Reply-To: {$nombre} <{$email}>\r\n";

// Enviar el correo
if (@mail($to, $subject, $bodyHtml, $headers)) {
    echo json_encode([
        'status' => 'success',
        'message' => '¡Consulta enviada con éxito!'
    ]);
} else {
    // Si la función mail() del servidor falla
    echo json_encode([
        'status' => 'error',
        'message' => 'Ocurrió un error al enviar el correo. Por favor intenta más tarde.'
    ]);
}