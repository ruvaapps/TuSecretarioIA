<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ OPcache limpiado exitosamente.";
} else {
    echo "OPcache no está activo.";
}