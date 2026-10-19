<?php
// turnos_helper.php

if (!defined('WHATSAPP_PHONE_NUMBER_ID')) {
    define('WHATSAPP_PHONE_NUMBER_ID', '1297893416744537');
}

/**
 * Función centralizada para registrar logs específicos de turnos
 */
if (!function_exists('logTurnosHelper')) {
    function logTurnosHelper($msg) {
        $logFile = __DIR__ . '/log_turnos_helper.txt';
        $timestamp = "[" . date('Y-m-d H:i:s') . "] ";
        @file_put_contents($logFile, $timestamp . $msg . "\n", FILE_APPEND);

        // Fallbacks a loggers existentes si están definidos
        if (function_exists('logAccionesPanel')) {
            logAccionesPanel("[HELPER] " . $msg);
        } elseif (function_exists('logClientes')) {
            logClientes("[HELPER] " . $msg);
        }
    }
}

/**
 * 1. Función centralizada para AGENDAR un turno.
 */
function agendarTurnoCompleto($pdo, $negocio_id, $nombre_cliente, $telefono_cliente, $servicio_id, $fecha, $hora, $origen = 'panel', $recurso_id = null) {
    try {
        logTurnosHelper("=== INICIO AGENDAR TURNO ===");
        logTurnosHelper("Params: Negocio: {$negocio_id} | Cliente: {$nombre_cliente} ({$telefono_cliente}) | Servicio: {$servicio_id} | Recurso ID: " . ($recurso_id ?: 'Ninguno') . " | Fecha: {$fecha} | Hora: {$hora} | Origen: {$origen}");

        // 1. Obtener información del negocio
        $stmtNegocio = $pdo->prepare("
            SELECT 
                nombre, direccion, localidad, instagram, mp_access_token,
                permite_mercadopago, permite_transferencia,
                banco_nombre, banco_titular, banco_cuit_cuil, banco_cbu_cvu, banco_alias, banco_instrucciones
            FROM saas_negocios 
            WHERE id = ?
        ");
        $stmtNegocio->execute([$negocio_id]);
        $negocio = $stmtNegocio->fetch(PDO::FETCH_ASSOC);

        if (!$negocio) {
            logTurnosHelper("ERROR: Negocio ID {$negocio_id} no encontrado.");
            return ['status' => 'error', 'message' => 'Negocio no encontrado'];
        }

        $nombre_comercio = $negocio['nombre'] ?? 'Nuestro Negocio';
        $direccion_full  = trim(($negocio['direccion'] ?? '') . ' ' . ($negocio['localidad'] ?? ''));
        $direccion_fmt   = !empty($direccion_full) ? $direccion_full : '';
        $instagram_fmt   = !empty($negocio['instagram']) ? '@' . ltrim($negocio['instagram'], '@') : '';
        $mpAccessToken   = $negocio['mp_access_token'] ?? null;
        $phoneNumberId   = WHATSAPP_PHONE_NUMBER_ID;

        $permite_mp     = (int)($negocio['permite_mercadopago'] ?? 1);
        $permite_transf = (int)($negocio['permite_transferencia'] ?? 0);

        // 2. Obtener o crear cliente
        $stmtCliente = $pdo->prepare("SELECT id FROM saas_clientes WHERE negocio_id = ? AND telefono = ?");
        $stmtCliente->execute([$negocio_id, $telefono_cliente]);
        $cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);

        if ($cliente) {
            $cliente_id = $cliente['id'];
            logTurnosHelper("Cliente existente encontrado ID: {$cliente_id}");
        } else {
            $stmtNewCliente = $pdo->prepare("INSERT INTO saas_clientes (negocio_id, nombre, telefono) VALUES (?, ?, ?)");
            $stmtNewCliente->execute([$negocio_id, $nombre_cliente, $telefono_cliente]);
            $cliente_id = $pdo->lastInsertId();
            logTurnosHelper("Nuevo cliente creado ID: {$cliente_id}");
        }

        // 3. Detalle del servicio y cálculo de horarios
        $stmtServ = $pdo->prepare("SELECT nombre, duracion_minutos, sena FROM saas_servicios WHERE id = ? AND negocio_id = ?");
        $stmtServ->execute([$servicio_id, $negocio_id]);
        $serv = $stmtServ->fetch(PDO::FETCH_ASSOC);

        if (!$serv) {
            logTurnosHelper("ERROR: Servicio ID {$servicio_id} no encontrado para negocio {$negocio_id}.");
            return ['status' => 'error', 'message' => 'Servicio no encontrado'];
        }

        $nombre_servicio = $serv['nombre'] ?? 'Servicio';
        $duracion        = (int)($serv['duracion_minutos'] ?? 30);
        $monto_sena      = (float)($serv['sena'] ?? 0);

        // Asegurar formato HH:MM:SS
        $hora_limpia = (strlen($hora) === 5) ? "{$hora}:00" : $hora;
        $inicio_ts   = strtotime("{$fecha} {$hora_limpia}");
        
        if ($inicio_ts === false) {
            $inicio_ts = strtotime("{$fecha} {$hora}");
        }

        $inicio = date('Y-m-d H:i:s', $inicio_ts);
        $fin    = date('Y-m-d H:i:s', $inicio_ts + ($duracion * 60));

        logTurnosHelper("Rango calculado: Inicio [{$inicio}] -> Fin [{$fin}] (Duración: {$duracion} mins)");

        // Validar choque de turnos o bloqueos (contemplando recurso si se asignó uno)
        if ($recurso_id) {
            $stmtT = $pdo->prepare("
                SELECT COUNT(*) FROM saas_turnos 
                WHERE negocio_id = ? 
                  AND recurso_id = ? 
                  AND (estado IS NULL OR LOWER(estado) != 'cancelado') 
                  AND (? < fecha_hora_fin AND ? > fecha_hora_inicio)
            ");
            $stmtT->execute([$negocio_id, $recurso_id, $inicio, $fin]);
        } else {
            $stmtT = $pdo->prepare("
                SELECT COUNT(*) FROM saas_turnos 
                WHERE negocio_id = ? 
                  AND (estado IS NULL OR LOWER(estado) != 'cancelado') 
                  AND (? < fecha_hora_fin AND ? > fecha_hora_inicio)
            ");
            $stmtT->execute([$negocio_id, $inicio, $fin]);
        }
        $cantTurnosChoque = $stmtT->fetchColumn();

        $stmtB = $pdo->prepare("SELECT COUNT(*) FROM saas_bloqueos WHERE negocio_id = ? AND (? < fecha_hora_fin AND ? > fecha_hora_inicio)");
        $stmtB->execute([$negocio_id, $inicio, $fin]);
        $cantBloqueosChoque = $stmtB->fetchColumn();

        if ($cantTurnosChoque > 0 || $cantBloqueosChoque > 0) {
            logTurnosHelper("CHOQUE DETECTADO: Turnos solapados: {$cantTurnosChoque} | Bloqueos: {$cantBloqueosChoque}");
            return ['status' => 'error', 'message' => 'El horario seleccionado ya se encuentra ocupado o bloqueado.'];
        }

        // 4. Estado inicial y método de pago
        $estado_inicial = ($monto_sena > 0) ? 'pendiente_pago' : 'confirmado';
        $metodo_pago    = ($monto_sena > 0) ? (($permite_transf === 1) ? 'transferencia' : 'mercadopago') : null;

        logTurnosHelper("Estado inicial: '{$estado_inicial}' | Seña: ${$monto_sena} | Método Pago: '{$metodo_pago}'");

        // 5. Insertar turno (incluyendo recurso_id)
        $stmtTurno = $pdo->prepare("
            INSERT INTO saas_turnos (negocio_id, cliente_id, servicio_id, recurso_id, fecha_hora_inicio, fecha_hora_fin, estado, monto_sena, metodo_pago)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtTurno->execute([$negocio_id, $cliente_id, $servicio_id, $recurso_id, $inicio, $fin, $estado_inicial, $monto_sena, $metodo_pago]);
        $turno_id = $pdo->lastInsertId();

        logTurnosHelper("Turno insertado exitosamente con ID: {$turno_id}");

        // 6. Registro de cobro del SaaS ($300)
        $monto_cobro = 300.00;
        $stmtCobro = $pdo->prepare("
            INSERT INTO cobros_turnos (negocio_id, turno_id, cliente_telefono, monto, estado)
            VALUES (?, ?, ?, ?, 'pendiente')
        ");
        $stmtCobro->execute([$negocio_id, $turno_id, $telefono_cliente, $monto_cobro]);

        // 7. Generar link de MP si corresponde
        $link_pago = null;
        if ($monto_sena > 0 && $metodo_pago === 'mercadopago' && $mpAccessToken) {
            if (function_exists('generarLinkSenaMercadoPago')) {
                logTurnosHelper("Generando preferencia de Mercado Pago...");
                $mpRes = generarLinkSenaMercadoPago(
                    $mpAccessToken, 
                    $turno_id, 
                    $nombre_servicio, 
                    $monto_sena, 
                    '', 
                    'https://tusecretarioia.lovar.com.ar'
                );
                
                if ($mpRes && isset($mpRes['init_point'])) {
                    $link_pago = $mpRes['init_point'];
                    $stmtUpdate = $pdo->prepare("UPDATE saas_turnos SET mp_preference_id = ?, link_pago = ? WHERE id = ?");
                    $stmtUpdate->execute([$mpRes['preference_id'], $link_pago, $turno_id]);
                    logTurnosHelper("Link MP generado: {$link_pago}");
                } else {
                    logTurnosHelper("WARN: No se pudo generar el link de Mercado Pago.");
                }
            }
        }

        // 8. Notificación por WhatsApp
        $fechaFormateada = date('d/m/Y', strtotime($fecha));
        $horaFormateada  = date('H:i', strtotime($hora));

        $negocioConDetalles = $nombre_comercio;
        if (!empty($direccion_fmt)) {
            $negocioConDetalles .= " - Ubicación: {$direccion_fmt}";
        }

        if (!empty($telefono_cliente)) {
            logTurnosHelper("Enviando notificación por WhatsApp al cliente {$telefono_cliente}...");
            if ($monto_sena > 0) {
                if ($metodo_pago === 'transferencia' && function_exists('enviarMensajeTransferenciaWhatsApp')) {
                    enviarMensajeTransferenciaWhatsApp($telefono_cliente, $nombre_cliente, $nombre_servicio, $negocioConDetalles, $fechaFormateada, $horaFormateada, $monto_sena, $negocio, $phoneNumberId);
                } elseif (function_exists('enviarMensajeSenaWhatsApp')) {
                    enviarMensajeSenaWhatsApp($telefono_cliente, $nombre_cliente, $nombre_servicio, $negocioConDetalles, $fechaFormateada, $horaFormateada, $monto_sena, $link_pago, $phoneNumberId);
                }
            } else {
                if (function_exists('enviarPlantillaConfirmacion')) {
                    enviarPlantillaConfirmacion($telefono_cliente, $nombre_cliente, $negocioConDetalles, $instagram_fmt, $nombre_servicio, $fechaFormateada, $horaFormateada, $phoneNumberId);
                }
            }
        }

        // 9. WebPush al dueño
        if (function_exists('notificarNuevoTurnoWebPush')) {
            logTurnosHelper("Disparando WebPush al dueño...");
            notificarNuevoTurnoWebPush($pdo, $negocio_id, $nombre_cliente, $nombre_servicio, $fecha, $hora);
        }

        logTurnosHelper("=== FIN AGENDAR TURNO EXITOSO (ID: {$turno_id}) ===");

        return [
            'status'          => 'success',
            'turno_id'        => $turno_id,
            'requiere_sena'   => ($monto_sena > 0),
            'monto_sena'      => $monto_sena,
            'metodo_pago'     => $metodo_pago,
            'link_pago'       => $link_pago,
            'nombre_servicio' => $nombre_servicio,
            'nombre_negocio'  => $nombre_comercio,
            'direccion'       => $direccion_fmt,
            'datos_banco'     => $negocio
        ];

    } catch (PDOException $e) {
        $msgErr = "EXCEPCION BD [agendarTurnoCompleto]: " . $e->getMessage();
        logTurnosHelper($msgErr);
        return ['status' => 'error', 'message' => 'Error en base de datos al agendar el turno: ' . $e->getMessage()];
    }
}

/**
 * 2. Función centralizada para CANCELAR un turno y notificar al cliente y al negocio.
 */
function cancelarTurnoCompleto($pdo, $turno_id, $negocio_id) {
    try {
        $turno_id   = (int)$turno_id;
        $negocio_id = (int)$negocio_id;

        logTurnosHelper("=== INICIO CANCELAR TURNO ===");
        logTurnosHelper("Params: Turno ID: {$turno_id} | Negocio ID: {$negocio_id}");

        // 1. Obtener información del turno con LEFT JOINs
        $stmtInfo = $pdo->prepare("
            SELECT 
                t.fecha_hora_inicio,
                c.nombre AS cliente_nombre,
                c.telefono AS cliente_telefono,
                s.nombre AS servicio_nombre,
                n.nombre AS negocio_nombre
            FROM saas_turnos t
            LEFT JOIN saas_clientes c ON t.cliente_id = c.id
            LEFT JOIN saas_servicios s ON t.servicio_id = s.id
            LEFT JOIN saas_negocios n ON t.negocio_id = n.id
            WHERE t.id = ? AND t.negocio_id = ?
        ");
        $stmtInfo->execute([$turno_id, $negocio_id]);
        $turno = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        if (!$turno) {
            logTurnosHelper("ERROR: Turno ID {$turno_id} no encontrado en la base de datos para Negocio {$negocio_id}.");
            return ['status' => 'error', 'message' => 'Turno no encontrado'];
        }

        // 2. Cambiar estado del turno a cancelado
        $stmtUpd = $pdo->prepare("UPDATE saas_turnos SET estado = 'cancelado' WHERE id = ? AND negocio_id = ?");
        $stmtUpd->execute([$turno_id, $negocio_id]);
        logTurnosHelper("Estado del turno ID {$turno_id} actualizado a 'cancelado' en BD.");

        // 3. Cancelar recordatorios asociados
        if (!empty($turno['cliente_telefono'])) {
            $stmtCancelRec = $pdo->prepare("
                UPDATE recordatorios 
                SET estado = 'cancelado' 
                WHERE telefono = ? AND fecha_evento = ?
            ");
            $stmtCancelRec->execute([$turno['cliente_telefono'], $turno['fecha_hora_inicio']]);
            logTurnosHelper("Recordatorios asociados desactivados.");

            // 4. Enviar notificación de cancelación por WhatsApp al cliente
            if (!empty($turno['fecha_hora_inicio'])) {
                $fechaHora     = strtotime($turno['fecha_hora_inicio']);
                $fechaFmt      = date('d/m/Y', $fechaHora);
                $horaFmt       = date('H:i', $fechaHora);
                $phoneNumberId = WHATSAPP_PHONE_NUMBER_ID;

                if (function_exists('enviarPlantillaCancelacion')) {
                    logTurnosHelper("Enviando plantilla de cancelación vía WhatsApp a {$turno['cliente_telefono']}...");
                    enviarPlantillaCancelacion(
                        $turno['cliente_telefono'],
                        $turno['cliente_nombre'] ?? 'Cliente',
                        $fechaFmt,
                        $horaFmt,
                        $turno['negocio_nombre'] ?? 'Nuestro Comercio',
                        $phoneNumberId
                    );
                }
            }
        }

        // 5. Notificación Web Push al dueño del negocio
        if (function_exists('notificarNuevoTurnoWebPush') && !empty($turno['fecha_hora_inicio'])) {
            logTurnosHelper("Disparando WebPush de cancelación al dueño...");
            $fechaStr = date('Y-m-d', strtotime($turno['fecha_hora_inicio']));
            $horaStr  = date('H:i:s', strtotime($turno['fecha_hora_inicio']));
            $clienteNom = $turno['cliente_nombre'] ?? 'Un cliente';
            $servicioNom = $turno['servicio_nombre'] ?? 'Turno';

            notificarNuevoTurnoWebPush(
                $pdo,
                $negocio_id,
                $clienteNom,
                $servicioNom,
                $fechaStr,
                $horaStr,
                "❌ Turno Cancelado",
                "{$clienteNom} canceló el turno de {$servicioNom} para el " . date('d/m/Y', strtotime($fechaStr)) . " a las " . date('H:i', strtotime($horaStr)) . " hs."
            );
        }

        logTurnosHelper("=== FIN CANCELAR TURNO EXITOSO (ID: {$turno_id}) ===");

        return ['status' => 'success', 'message' => 'Turno cancelado correctamente'];

    } catch (PDOException $e) {
        $msgErr = "EXCEPCION BD [cancelarTurnoCompleto]: " . $e->getMessage();
        logTurnosHelper($msgErr);
        return ['status' => 'error', 'message' => 'Error al cancelar el turno: ' . $e->getMessage()];
    }
}

/**
 * Función centralizada para enviar notificación push al negocio
 */
if (!function_exists('notificarNuevoTurnoWebPush')) {
    function notificarNuevoTurnoWebPush($pdo, $negocio_id, $nombreCliente, $nombreServicio, $fecha, $hora, $tituloPersonalizado = null, $cuerpoPersonalizado = null) {
        try {
            if (!class_exists('Minishlink\WebPush\WebPush')) {
                $autoloadPath = __DIR__ . '/vendor/autoload.php';
                if (!file_exists($autoloadPath)) {
                    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
                }
                if (file_exists($autoloadPath)) {
                    require_once $autoloadPath;
                }
            }

            if (!defined('VAPID_PUBLIC_KEY')) {
                define('VAPID_PUBLIC_KEY', 'BB_sUL_80YWtAyK5m995PeOr_K0m_M3aYG89QOMVHXUege_PKuh4FJ7VB2-NSesPJcAfTKUm7_4MXYSFpybqJ1I');
            }
            if (!defined('VAPID_PRIVATE_KEY')) {
                define('VAPID_PRIVATE_KEY', 'tB2EnpYIX_LalPzTQCbqOnyjbVWp3Pbi8SGZ79vY8KM');
            }
            if (!defined('VAPID_SUBJECT')) {
                define('VAPID_SUBJECT', 'Notificacion de turno');
            }

            logTurnosHelper("Procesando WebPush para Negocio ID: {$negocio_id}");

            $stmtSub = $pdo->prepare("SELECT endpoint, p256dh, auth FROM saas_push_subscriptions WHERE negocio_id = ?");
            $stmtSub->execute([$negocio_id]);
            $suscripciones = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

            if (empty($suscripciones)) {
                logTurnosHelper("WebPush: No existen suscripciones activas para el negocio ID {$negocio_id}");
                return;
            }

            $fechaFmt = date('d/m/Y', strtotime($fecha));
            $horaFmt  = date('H:i', strtotime($hora));

            $titulo = $tituloPersonalizado ?: "🗓️ ¡Nuevo Turno Agendado!";
            $cuerpo  = $cuerpoPersonalizado ?: "{$nombreCliente} reservó {$nombreServicio} para el {$fechaFmt} a las {$horaFmt} hs.";

            $payload = json_encode([
                'title' => $titulo,
                'body'  => $cuerpo,
                'icon'  => "/icon.png",
                'url'   => "/panel.php"
            ]);

            if (class_exists('Minishlink\WebPush\WebPush')) {
                $auth = [
                    'VAPID' => [
                        'subject'    => VAPID_SUBJECT,
                        'publicKey'  => VAPID_PUBLIC_KEY,
                        'privateKey' => VAPID_PRIVATE_KEY,
                    ],
                ];

                $webPush = new \Minishlink\WebPush\WebPush($auth);

                foreach ($suscripciones as $sub) {
                    $subscription = \Minishlink\WebPush\Subscription::create([
                        'endpoint'        => $sub['endpoint'],
                        'publicKey'       => $sub['p256dh_key'] ?? $sub['p256dh'] ?? '',
                        'authToken'       => $sub['auth_token'] ?? $sub['auth'] ?? '',
                        'contentEncoding' => 'aes128gcm',
                    ]);
                    $webPush->sendOneNotification($subscription, $payload);
                }

                foreach ($webPush->flush() as $report) {
                    if (!$report->isSuccess()) {
                        logTurnosHelper("WebPush error en envío: " . $report->getReason());
                    }
                }
            }
        } catch (Exception $e) {
            logTurnosHelper("Excepción en notificarNuevoTurnoWebPush: " . $e->getMessage());
        }
    }
}