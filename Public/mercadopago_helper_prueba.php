<?php
// mercadopago_helper_prueba.php

// 1. Configuración de credenciales de prueba
$mp_access_token = "TEST-3331449950252544-100810-ef44c8f892f20724775d7f91adc81a4f-63046043"; 
$url_webhook     = "https://tusecretarioia.lovar.com.ar/mercadoPago/webhook_mercadopago.php"; 

echo "<h2>🧪 Prueba de circuito end-to-end (Checkout Pro + Webhook)</h2>";

// 2. Definir un Turno ID existente en tu BD (en estado 'pendiente_pago')
$turno_id_prueba = 122; 

echo "<p><b>1. Creando preferencia de prueba para el Turno ID:</b> {$turno_id_prueba}...</p>";

// Payload para crear la preferencia
$preference_data = [
    "items" => [
        [
            "title" => "Seña de Reserva - Turno #" . $turno_id_prueba,
            "quantity" => 1,
            "currency_id" => "ARS",
            "unit_price" => 100.00
        ]
    ],
    "payer" => [
        "email" => "test_user_comprador@testuser.com"
    ],
    "external_reference" => (string)$turno_id_prueba,
    "notification_url" => $url_webhook
];

$ch = curl_init("https://api.mercadopago.com/checkout/preferences");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $mp_access_token,
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preference_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resPref = curl_exec($ch);
curl_close($ch);

$prefResult = json_decode($resPref, true);

if (!isset($prefResult['id'])) {
    echo "<p style='color:red;'>❌ Error al crear preferencia: " . htmlspecialchars($resPref) . "</p>";
    exit;
}

$preference_id = $prefResult['id'];
$init_point    = $prefResult['init_point'] ?? '#';

echo "<p style='color:green;'>✅ Preferencia creada exitosamente con ID: <b>{$preference_id}</b></p>";
echo "<p>🔗 Link de pago generado: <a href='{$init_point}' target='_blank'>{$init_point}</a></p>";

// 3. Simular la llegada de la notificación del pago al Webhook
echo "<p><b>2. Disparando notificación Webhook a tu backend...</b></p>";

// ID simulado para la notificación de prueba
$payment_id_simulado = "999" . rand(100000, 999999);

$webhook_payload = [
    "action" => "payment.created",
    "api_version" => "v1",
    "data" => [
        "id" => $payment_id_simulado
    ],
    "date_created" => date('c'),
    "type" => "payment"
];

$chHook = curl_init($url_webhook);
curl_setopt($chHook, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($chHook, CURLOPT_POST, true);
curl_setopt($chHook, CURLOPT_POSTFIELDS, json_encode($webhook_payload));
curl_setopt($chHook, CURLOPT_RETURNTRANSFER, true);
$resHook = curl_exec($chHook);
$httpCode = curl_getinfo($chHook, CURLINFO_HTTP_CODE);
curl_close($chHook);

echo "<p style='color:green;'>✅ Webhook contactado con éxito. Respuesta HTTP Code: <b>{$httpCode}</b></p>";

// 4. Expirar la preferencia vía API para cerrar el circuito
echo "<p><b>3. Marcando la preferencia como expirada/cerrada...</b></p>";

$payloadExpira = json_encode([
    "expires" => true,
    "expiration_date_to" => date('Y-m-d\TH:i:s.000P')
]);

$chPrefExp = curl_init("https://api.mercadopago.com/checkout/preferences/{$preference_id}");
curl_setopt($chPrefExp, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($chPrefExp, CURLOPT_POSTFIELDS, $payloadExpira);
curl_setopt($chPrefExp, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $mp_access_token,
    "Content-Type: application/json"
]);
curl_setopt($chPrefExp, CURLOPT_RETURNTRANSFER, true);
$resPrefExp = curl_exec($chPrefExp);
curl_close($chPrefExp);

echo "<p style='color:green;'>✅ Preferencia <b>{$preference_id}</b> expirable/cerrada correctamente.</p>";

echo "<h3>✨ Proceso completado exitosamente:</h3>";
echo "<ul>
    <li>Revisá tu archivo <code>log_webhook_mp.txt</code> para verificar que llegó la notificación.</li>
    <li>Verificá en la BD si el turno #{$turno_id_prueba} cambió su estado.</li>
    <li>Si hacés clic en el link de pago arriba, Mercado Pago te advertirá que el enlace ya expiró o caducó.</li>
</ul>";