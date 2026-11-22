<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function getPdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: 'localhost';
    $db   = getenv('DB_NAME') ?: 'admlova_secretario';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';

    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function readJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Cuerpo JSON vacío']);
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
        exit;
    }

    return $data;
}

function verifyDeviceToken(PDO $pdo, string $dispositivoId): void
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Token faltante']);
        exit;
    }

    $token = trim($matches[1]);

    // Si tenés tabla saas_sms_dispositivos (id, dispositivo_id, token_hash, activo):
    $stmt = $pdo->prepare(
        'SELECT token_hash, activo FROM saas_sms_dispositivos WHERE dispositivo_id = :dev LIMIT 1'
    );
    $stmt->execute([':dev' => $dispositivoId]);
    $row = $stmt->fetch();

    if (!$row || !(bool)$row['activo'] || !hash_equals((string)$row['token_hash'], hash('sha256', $token))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Dispositivo no autorizado']);
        exit;
    }
}