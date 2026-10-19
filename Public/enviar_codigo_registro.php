<?php
header('Content-Type: application/json');
require_once 'conexion.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Datos de entrada no válidos.']);
    exit;
}

$nombre         = trim($input['nombre'] ?? '');
$rubro          = trim($input['rubro'] ?? '');
$instagram      = trim($input['instagram'] ?? '');
$telefono_dueno = trim($input['telefono_dueno'] ?? '');
$direccion      = trim($input['direccion'] ?? '');
$localidad      = trim($input['localidad'] ?? '');
$latitud        = !empty($input['latitud']) ? $input['latitud'] : null;
$longitud       = !empty($input['longitud']) ? $input['longitud'] : null;
$email          = trim($input['email'] ?? '');
$password       = trim($input['password'] ?? '');

// Limpieza de Instagram y teléfono
$instagram = ltrim(trim($instagram), '@');
$telefono_dueno = preg_replace('/[^0-9]/', '', $telefono_dueno);

if (empty($nombre) || empty($email) || empty($password) || empty($telefono_dueno)) {
    echo json_encode(['status' => 'error', 'message' => 'Completa los campos obligatorios.']);
    exit;
}

if (strlen($telefono_dueno) < 8) {
    echo json_encode(['status' => 'error', 'message' => 'El número de celular ingresado no es válido.']);
    exit;
}

// Función para sanitizar y construir la base del slug
function generarSlugBase($texto) {
    $texto = mb_strtolower($texto, 'UTF-8');
    // Transliterar caracteres acentuados y especiales a ASCII
    if (function_exists('iconv')) {
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    }
    $texto = preg_replace('/[^a-z0-9\s-]/', '', $texto);
    $texto = preg_replace('/[\s-]+/', '-', $texto);
    $texto = trim($texto, '-');

    return !empty($texto) ? $texto : 'negocio';
}

try {
    // Validar si el email o el teléfono ya se encuentran registrados en saas_negocios
    $stmtCheck = $pdo->prepare("SELECT email, telefono_dueno FROM saas_negocios WHERE email = ? OR telefono_dueno = ?");
    $stmtCheck->execute([$email, $telefono_dueno]);
    $existente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        if ($existente['email'] === $email) {
            echo json_encode(['status' => 'error', 'message' => 'El Email ya se encuentra registrado.']);
            exit;
        }
        if ($existente['telefono_dueno'] === $telefono_dueno) {
            echo json_encode(['status' => 'error', 'message' => 'El número de teléfono ya se encuentra registrado.']);
            exit;
        }
    }

    // Generar un slug único
    $slugBase = generarSlugBase($nombre);
    $slugFinal = $slugBase;
    $contador = 1;

    $stmtSlugNegocios = $pdo->prepare("SELECT COUNT(*) FROM saas_negocios WHERE slug = ?");
    $stmtSlugTemp = $pdo->prepare("SELECT COUNT(*) FROM saas_registros_temporales WHERE slug = ? AND email != ?");

    while (true) {
        $stmtSlugNegocios->execute([$slugFinal]);
        $existeNegocios = $stmtSlugNegocios->fetchColumn();

        $stmtSlugTemp->execute([$slugFinal, $email]);
        $existeTemp = $stmtSlugTemp->fetchColumn();

        if ($existeNegocios == 0 && $existeTemp == 0) {
            break; // Slug libre
        }

        $slugFinal = $slugBase . '-' . $contador;
        $contador++;
    }

    // Generar código de 6 dígitos
    $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    // Borrar solicitudes temporales anteriores para este email
    $stmtDel = $pdo->prepare("DELETE FROM saas_registros_temporales WHERE email = ?");
    $stmtDel->execute([$email]);

    // Insertar pre-registro temporal
    $sql = "INSERT INTO saas_registros_temporales 
            (nombre, slug, rubro, instagram, telefono_dueno, direccion, localidad, latitud, longitud, email, password_hash, codigo_verificacion) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmtIns = $pdo->prepare($sql);
    $stmtIns->execute([
        $nombre, $slugFinal, $rubro, $instagram, $telefono_dueno, 
        $direccion, $localidad, $latitud, $longitud, $email, 
        $password_hash, $codigo
    ]);

    // Envío del email de verificación
    $asunto = "Código de Verificación - Registro de Negocio";
    $mensaje = "Tu código de verificación para completar el alta de tu negocio es: $codigo";
    $headers = "From: no-reply@" . $_SERVER['SERVER_NAME'] . "\r\n" .
               "Reply-To: no-reply@" . $_SERVER['SERVER_NAME'] . "\r\n" .
               "X-Mailer: PHP/" . phpversion();

    @mail($email, $asunto, $mensaje, $headers);

    echo json_encode(['status' => 'success', 'message' => 'Código de verificación enviado con éxito.']);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error en base de datos: ' . $e->getMessage()]);
}