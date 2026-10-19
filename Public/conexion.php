<?php
// db.php — Conexión centralizada a MySQL

$host = 'localhost';
$db   = 'admlova_secretario';
$user = 'admlova_secretario';
$pass = 'secretario2026'; // Reemplazar por tu contraseña real de DB

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Si falla la conexión, registramos el error sin exponer claves en pantalla
    error_log("Error de conexión a la Base de Datos: " . $e->getMessage());
    die(json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']));
}