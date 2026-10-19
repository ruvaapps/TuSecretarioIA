<?php
// Desactivar despliegue HTML directo de errores
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

try {
    if (!file_exists('conexion.php')) {
        throw new Exception("El archivo conexion.php no existe.");
    }
    require_once 'conexion.php';

    // Capturar destino previo si existe, por defecto va al panel
    $redirect = $_REQUEST['redirect'] ?? 'panel_negocio.php';
    
    // Sanitizar redirección para evitar Open Redirects (solo rutas locales)
    if (strpos($redirect, '/') === 0 || strpos($redirect, 'http') === 0) {
        $redirect = 'panel_negocio.php';
    }

    // Obtener datos del formulario POST
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        header('Location: index.html?error=campos_vacios&redirect=' . urlencode($redirect));
        exit;
    }

    // Buscar el negocio por Email
    $stmt = $pdo->prepare("SELECT id, nombre, password_hash, estado FROM saas_negocios WHERE email = ?");
    $stmt->execute([$email]);
    $negocio = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verificar si existe el usuario y la contraseña coincide
    if ($negocio && password_verify($password, $negocio['password_hash'])) {
        
        // Verificar si la cuenta está activa
        if (isset($negocio['estado']) && $negocio['estado'] !== 'activo') {
            header('Location: index.html?error=cuenta_inactiva&redirect=' . urlencode($redirect));
            exit;
        }

        // Iniciar sesión exitosamente
        $_SESSION['negocio_id']     = $negocio['id'];
        $_SESSION['negocio_nombre'] = $negocio['nombre'];

        // Redirigir al panel o al destino solicitado
        header('Location: ' . $redirect);
        exit;
    } else {
        // Datos incorrectos
        header('Location: index.html?error=credenciales_invalidas&redirect=' . urlencode($redirect));
        exit;
    }

} catch (Throwable $e) {
    // Si hay un error de conexión o base de datos
    header('Location: index.html?error=error_servidor');
    exit;
}