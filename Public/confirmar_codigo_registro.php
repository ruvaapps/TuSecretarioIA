<?php
session_start();
header('Content-Type: application/json');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

$email  = trim($input['email'] ?? '');
$codigo = trim($input['codigo'] ?? '');

if (empty($email) || empty($codigo)) {
    echo json_encode(['status' => 'error', 'message' => 'Por favor ingresa el código.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM saas_registros_temporales WHERE email = ? AND codigo_verificacion = ?");
    $stmt->execute([$email, $codigo]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        echo json_encode(['status' => 'error', 'message' => 'Código incorrecto o no encontrado.']);
        exit;
    }

    // Validación de seguridad adicional por si el teléfono fue registrado concurrentemente
    $stmtTel = $pdo->prepare("SELECT COUNT(*) FROM saas_negocios WHERE telefono_dueno = ?");
    $stmtTel->execute([$registro['telefono_dueno']]);
    if ($stmtTel->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'El número de teléfono asociado ya ha sido registrado por otro negocio.']);
        exit;
    }

    // Verificación final de colisión de slug por seguridad ante concurrencia
    $slugFinal = $registro['slug'];
    $contador = 1;
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM saas_negocios WHERE slug = ?");

    while (true) {
        $stmtCheck->execute([$slugFinal]);
        if ($stmtCheck->fetchColumn() == 0) {
            break;
        }
        $slugFinal = $registro['slug'] . '-' . $contador;
        $contador++;
    }

    // Insertar negocio en saas_negocios
    $sqlIns = "INSERT INTO saas_negocios 
               (nombre, slug, rubro, instagram, email, password_hash, telefono_dueno, direccion, localidad, latitud, longitud, estado, paso_onboarding) 
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', 'COMPLETADO')";
               
    $stmtIns = $pdo->prepare($sqlIns);
    $stmtIns->execute([
        $registro['nombre'],
        $slugFinal,
        $registro['rubro'],
        $registro['instagram'],
        $registro['email'],
        $registro['password_hash'],
        $registro['telefono_dueno'],
        $registro['direccion'],
        $registro['localidad'],
        $registro['latitud'],
        $registro['longitud']
    ]);

    $negocio_id = $pdo->lastInsertId();

    // Insertar configuración por defecto de agenda
    $stmtAgenda = $pdo->prepare("INSERT INTO saas_configuracion_agenda (negocio_id) VALUES (?)");
    $stmtAgenda->execute([$negocio_id]);

    // Eliminar registro de la tabla temporal
    $stmtDel = $pdo->prepare("DELETE FROM saas_registros_temporales WHERE id = ?");
    $stmtDel->execute([$registro['id']]);

    // Iniciar sesión automática para el usuario
    $_SESSION['negocio_id'] = $negocio_id;
    $_SESSION['negocio_nombre'] = $registro['nombre'];

    echo json_encode(['status' => 'success', 'redirect' => 'panel_negocio.php']);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}