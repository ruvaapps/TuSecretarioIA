<?php
// pago_exitoso.php
$turno_id = $_GET['turno_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>¡Seña Abonada!</title>
    <style>
        body { font-family: sans-serif; background: #f4f6f9; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #fff; padding: 30px; border-radius: 12px; text-align: center; max-width: 400px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .icon { font-size: 50px; color: #28a745; }
        h2 { margin: 15px 0 10px; color: #333; }
        p { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">✓</div>
        <h2>¡Seña recibida con éxito!</h2>
        <p>Tu turno fue registrado y confirmado correctamente. Te enviamos los detalles de la reserva por WhatsApp.</p>
    </div>
</body>
</html>