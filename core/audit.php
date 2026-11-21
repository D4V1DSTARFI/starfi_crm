<?php
/**
 * core/audit.php
 * Motor Formal de Auditoría y Bitácora por Módulo — STARFI CRM (QA-STD-GLOBAL-2.1)
 * Registra mutaciones críticas en tabla `auditoria_sistema` (vía mysqli_prepare) y en `logs/logs_<modulo>.log`.
 */

if (!function_exists('starfi_log_modulo')) {
    function starfi_log_modulo($modulo, $mensaje, $nivel = 'INFO') {
        $mod_clean = preg_replace('/[^a-z0-9_-]/i', '_', strtolower(trim((string)$modulo))) ?: 'general';
        $log_dir = dirname(__DIR__) . '/logs';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        $line = sprintf(
            "[%s] [%s] %s%s",
            date('Y-m-d H:i:s'),
            strtoupper(trim((string)$nivel)),
            (string)$mensaje,
            PHP_EOL
        );
        @file_put_contents($log_dir . '/logs_' . $mod_clean . '.log', $line, FILE_APPEND);
    }
}

if (!function_exists('starfi_ensure_audit_schema')) {
    function starfi_ensure_audit_schema($con) {
        if (!$con) {
            return;
        }
        $dbName = 'starfi_crm';
        $rDb = @mysqli_query($con, "SELECT DATABASE() AS db_name");
        if ($rDb && ($rw = mysqli_fetch_assoc($rDb)) && !empty($rw['db_name'])) {
            $dbName = (string)$rw['db_name'];
        }
        $flag = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'starfi_audit_schema_v1_' . md5($dbName) . '.flag';
        if (@file_exists($flag) && (time() - @filemtime($flag)) < 86400) {
            return;
        }

        @mysqli_query($con, "CREATE TABLE IF NOT EXISTS `auditoria_sistema` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `id_usuario` INT DEFAULT NULL,
            `usuario_nombre` VARCHAR(120) DEFAULT NULL,
            `id_sede` INT DEFAULT NULL,
            `modulo` VARCHAR(80) NOT NULL,
            `accion` VARCHAR(100) NOT NULL,
            `entidad` VARCHAR(100) DEFAULT NULL,
            `id_registro` VARCHAR(64) DEFAULT NULL,
            `detalle_json` TEXT DEFAULT NULL,
            `ip_origen` VARCHAR(64) DEFAULT NULL,
            `fecha_evento` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_modulo_fecha` (`modulo`, `fecha_evento`),
            INDEX `idx_usuario` (`id_usuario`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @file_put_contents($flag, date('Y-m-d H:i:s'));
    }
}

if (!function_exists('registrar_auditoria')) {
    /**
     * Registra una acción auditable en base de datos y bitácora del módulo.
     */
    function registrar_auditoria($con, $modulo, $accion, $entidad = '', $id_registro = null, $detalle = [], $id_usuario = null, $id_sede = null) {
        $uid = ($id_usuario !== null) ? intval($id_usuario) : intval($_SESSION['agente_id'] ?? 0);
        $unom = trim((string)($_SESSION['nombre_completo'] ?? ''));
        $sede = ($id_sede !== null) ? intval($id_sede) : null;
        $mod = substr(trim((string)$modulo), 0, 80);
        $acc = substr(trim((string)$accion), 0, 100);
        $ent = substr(trim((string)$entidad), 0, 100);
        $id_reg = ($id_registro !== null && $id_registro !== '') ? substr((string)$id_registro, 0, 64) : null;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'CLI' : '0.0.0.0')), 0, 64);
        $det_str = is_string($detalle) ? $detalle : json_encode($detalle, JSON_UNESCAPED_UNICODE);

        starfi_log_modulo($mod, sprintf("AUDIT user=%d sede=%s accion=%s entidad=%s id=%s detalle=%s", $uid, (string)($sede ?? '-'), $acc, $ent, (string)($id_reg ?? '-'), (string)$det_str), 'AUDIT');

        if (!$con) {
            return false;
        }

        starfi_ensure_audit_schema($con);
        $stmt = @$con->prepare("
            INSERT INTO `auditoria_sistema`
                (`id_usuario`, `usuario_nombre`, `id_sede`, `modulo`, `accion`, `entidad`, `id_registro`, `detalle_json`, `ip_origen`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return false;
        }
        $uid_param = ($uid > 0) ? $uid : null;
        $unom_param = ($unom !== '') ? $unom : null;
        $stmt->bind_param("isissssss", $uid_param, $unom_param, $sede, $mod, $acc, $ent, $id_reg, $det_str, $ip);
        $ok = @$stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}
