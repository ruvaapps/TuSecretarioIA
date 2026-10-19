<?php
// pago_fallido.php
require_once 'conexion.php';

$turno_id = $_GET['turno_id'] ?? null;
$link_pago = '#';

if ($turno_id) {
    $stmt = $pdo->prepare("SELECT link_pago FROM saas_turnos WHERE id = ?");
    $stmt->execute([$turno_id]);
    $turno = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($turno && !empty($turno['link_pago'])) {
        $link_pago = $turno['link_pago'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago No Completado</title>
    <style>
        body { font-family: sans-serif; background: #f4f6f9; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #fff; padding: 30px; border-radius: 12px; text-align: center; max-width: 400px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .icon { font-size: 50px; color: #dc3545; }
        h2 { margin: 15px 0 10px; color: #333; }
        p { color: #666; font-size: 14px; line-height: 1.5; }
        .btn { display: inline-block; margin-top: 20px; padding: 12px 24px; background: #009ee3; color: #fff; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .btn:hover { background: #0081b8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">✕</div>
        <h2>No pudimos procesar tu pago</h2>
        <p>Ocurrió un inconveniente al intentar cobrar la seña. Tu turno sigue pendiente por unos minutos.</p>
        <?php if ($link_pago !== '#'): ?>
            <a href="<?php echo htmlspecialchars($link_pago); ?>" class="btn">Reintentar Pago</a>
        <?php endif; ?>
    </div>
</body>
</html>