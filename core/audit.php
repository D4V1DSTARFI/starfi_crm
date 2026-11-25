<?php
/**
 * core/audit.php
 * Motor Formal de Auditoría y Bitácora Local por Módulo — STARFI CRM (QA-STD-GLOBAL-2.1)
 * - Persiste eventos con estado anterior (`datos_anteriores`) y nuevo (`datos_nuevos`) en `auditoria_sistema` vía `mysqli_prepare()`.
 * - Enruta excepciones y bitácoras locales a `modules/<modulo>/logs_<modulo>.log` si existe la cápsula del módulo,
 *   reservando `/logs/logs_<modulo>.log` exclusivamente para scripts globales (`webhook`, `auth`, `api_notificaciones`).
 */

if (!function_exists('starfi_resolve_module_log_path')) {
    function starfi_resolve_module_log_path($modulo) {
        $raw = strtolower(trim((string)$modulo));
        $mod_clean = preg_replace('/[^a-z0-9_-]/i', '_', $raw) ?: 'general';
        $aliases = [
            'empresa'      => 'perfil_empresa',
            'usuarios'     => 'gestion_usuarios',
            'roles'        => 'gestion_roles',
            'bots'         => 'gestor_bots',
            'waba_billing' => 'waba_ordenes',
        ];
        $mapped = $aliases[$mod_clean] ?? $mod_clean;
        $root = dirname(__DIR__);
        $mod_dir = $root . '/modules/' . $mapped;

        if (is_dir($mod_dir)) {
            $ht = $mod_dir . '/.htaccess';
            if (!is_file($ht)) {
                $htContent = "Options -Indexes\n"
                    . "DirectoryIndex " . $mapped . ".php\n\n"
                    . "<FilesMatch \"^(modals_.*\\.php|.*\\.log)$\">\n"
                    . "    <IfModule mod_authz_core.c>\n"
                    . "        Require all denied\n"
                    . "    </IfModule>\n"
                    . "    <IfModule !mod_authz_core.c>\n"
                    . "        Order allow,deny\n"
                    . "        Deny from all\n"
                    . "    </IfModule>\n"
                    . "</FilesMatch>\n";
                @file_put_contents($ht, $htContent);
            }
            return $mod_dir . '/logs_' . $mapped . '.log';
        }

        $log_dir = $root . '/logs';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        return $log_dir . '/logs_' . $mod_clean . '.log';
    }
}

if (!function_exists('starfi_redact_audit_value')) {
    function starfi_redact_audit_value($value) {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            $sensitive = ['contrasena', 'password', 'password_hash', 'meta_token', 'api_token', 'gemini_api_key', 'smtp_pass', 'codigo_recuperacion', 'respuesta'];
            $out = [];
            foreach ($value as $k => $v) {
                $kl = strtolower((string)$k);
                if (in_array($kl, $sensitive, true)) {
                    $out[$k] = ($v !== null && $v !== '') ? '[REDACTED]' : '';
                } else {
                    $out[$k] = starfi_redact_audit_value($v);
                }
            }
            return $out;
        }
        return $value;
    }
}

if (!function_exists('starfi_encode_audit_json')) {
    function starfi_encode_audit_json($data) {
        if ($data === null) {
            return null;
        }
        if (is_string($data)) {
            $trimmed = trim($data);
            if ($trimmed === '') {
                return null;
            }
            json_decode($trimmed);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $trimmed;
            }
            return json_encode(['valor' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $clean = starfi_redact_audit_value($data);
        return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}

if (!function_exists('starfi_log_modulo')) {
    function starfi_log_modulo($modulo, $mensaje, $nivel = 'INFO') {
        $path = starfi_resolve_module_log_path($modulo);
        $line = sprintf(
            "[%s] [%s] %s%s",
            date('Y-m-d H:i:s'),
            strtoupper(trim((string)$nivel)),
            (string)$mensaje,
            PHP_EOL
        );
        @file_put_contents($path, $line, FILE_APPEND);
    }
}

if (!function_exists('starfi_log_exception')) {
    /**
     * Registra errores técnicos SQL/excepciones internamente en `modules/<modulo>/logs_<modulo>.log`
     * (o `/logs/logs_<modulo>.log` para scripts globales) sin exponerlos jamás al navegador.
     */
    function starfi_log_exception($modulo, $mensaje, $contexto = []) {
        $ctx_str = '';
        if (!empty($contexto)) {
            $ctx_json = starfi_encode_audit_json($contexto);
            if ($ctx_json !== null) {
                $ctx_str = ' | ctx=' . $ctx_json;
            }
        }
        starfi_log_modulo($modulo, (string)$mensaje . $ctx_str, 'ERROR');
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
        $flag = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'starfi_audit_schema_v2_' . md5($dbName) . '.flag';
        if (@file_exists($flag) && (time() - @filemtime($flag)) < 86400) {
            $chkT = @mysqli_query($con, "SHOW COLUMNS FROM `auditoria_sistema` LIKE 'datos_nuevos'");
            if ($chkT && mysqli_num_rows($chkT) > 0) {
                return;
            }
        }

        // No disparar DDL implícito si hay una transacción activa (p.ej. suite de pruebas con ROLLBACK)
        $inTx = function_exists('starfi_tx_is_active') && starfi_tx_is_active($con);
        if ($inTx) {
            return;
        }

        @mysqli_query($con, "CREATE TABLE IF NOT EXISTS `auditoria_sistema` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `id_usuario` INT DEFAULT NULL,
            `usuario_nombre` VARCHAR(120) DEFAULT NULL,
            `id_sede` INT DEFAULT NULL,
            `modulo` VARCHAR(80) NOT NULL,
            `accion` VARCHAR(100) NOT NULL,
            `descripcion` TEXT DEFAULT NULL,
            `entidad` VARCHAR(100) DEFAULT NULL,
            `id_registro` VARCHAR(64) DEFAULT NULL,
            `datos_anteriores` LONGTEXT DEFAULT NULL,
            `datos_nuevos` LONGTEXT DEFAULT NULL,
            `detalle_json` LONGTEXT DEFAULT NULL,
            `ip_origen` VARCHAR(64) DEFAULT NULL,
            `fecha_evento` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_modulo_fecha` (`modulo`, `fecha_evento`),
            INDEX `idx_usuario` (`id_usuario`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cols = [];
        $rCols = @mysqli_query($con, "SHOW COLUMNS FROM `auditoria_sistema`");
        if ($rCols) {
            while ($c = mysqli_fetch_assoc($rCols)) {
                $cols[$c['Field']] = true;
            }
        }
        if (!isset($cols['descripcion'])) {
            @mysqli_query($con, "ALTER TABLE `auditoria_sistema` ADD COLUMN `descripcion` TEXT DEFAULT NULL AFTER `accion`");
        }
        if (!isset($cols['datos_anteriores'])) {
            @mysqli_query($con, "ALTER TABLE `auditoria_sistema` ADD COLUMN `datos_anteriores` LONGTEXT DEFAULT NULL AFTER `id_registro`");
        }
        if (!isset($cols['datos_nuevos'])) {
            @mysqli_query($con, "ALTER TABLE `auditoria_sistema` ADD COLUMN `datos_nuevos` LONGTEXT DEFAULT NULL AFTER `datos_anteriores`");
        }

        @file_put_contents($flag, date('Y-m-d H:i:s'));
    }
}

if (!function_exists('registrar_auditoria')) {
    /**
     * Registra una mutación o evento de seguridad en `auditoria_sistema` y en la bitácora del módulo.
     *
     * Firma canónica (QA-STD-GLOBAL-2.1 CHK-SEC-04):
     *   registrar_auditoria($modulo, $accion, $descripcion, $datos_anteriores = null, $datos_nuevos = null, $con = null, $id_usuario = null, $id_sede = null)
     */
    function registrar_auditoria($modulo, $accion, $descripcion = '', $datos_anteriores = null, $datos_nuevos = null, $con = null, $id_usuario = null, $id_sede = null) {
        // Compatibilidad polimórfica si se invoca con firma heredada ($con, $modulo, $accion, $entidad, $id_registro, $detalle, $id_usuario, $id_sede)
        $entidad = null;
        $id_reg = null;
        if ($modulo instanceof mysqli) {
            $legacy_con = $modulo;
            $modulo = (string)$accion;
            $accion = (string)$descripcion;
            $entidad = is_string($datos_anteriores) ? $datos_anteriores : null;
            $id_reg = ($datos_nuevos !== null && !is_array($datos_nuevos)) ? (string)$datos_nuevos : null;
            $legacy_det = $con;
            $descripcion = trim(($entidad ? $entidad : '') . ($id_reg ? ' #' . $id_reg : ''));
            $datos_anteriores = (is_array($legacy_det) && array_key_exists('antes', $legacy_det)) ? $legacy_det['antes'] : null;
            $datos_nuevos = (is_array($legacy_det) && array_key_exists('despues', $legacy_det)) ? $legacy_det['despues'] : $legacy_det;
            $con = $legacy_con;
        }

        $own_con = false;
        if (!($con instanceof mysqli) && function_exists('getDbConnection')) {
            $con = @getDbConnection('core');
            $own_con = ($con instanceof mysqli);
        }

        $uid = ($id_usuario !== null) ? intval($id_usuario) : intval($_SESSION['agente_id'] ?? 0);
        $unom = trim((string)($_SESSION['nombre_completo'] ?? ''));
        $sede = ($id_sede !== null) ? intval($id_sede) : (isset($_SESSION['id_sede']) ? intval($_SESSION['id_sede']) : null);
        $mod = substr(trim((string)$modulo), 0, 80) ?: 'general';
        $acc = substr(trim((string)$accion), 0, 100) ?: 'EVENTO';
        $desc = trim((string)$descripcion);
        $ent_param = ($entidad !== null && $entidad !== '') ? substr($entidad, 0, 100) : null;
        $id_reg_param = ($id_reg !== null && $id_reg !== '') ? substr($id_reg, 0, 64) : null;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'CLI' : '0.0.0.0')), 0, 64);

        $ant_json = starfi_encode_audit_json($datos_anteriores);
        $nue_json = starfi_encode_audit_json($datos_nuevos);
        $det_json = json_encode([
            'descripcion'      => $desc,
            'datos_anteriores' => ($ant_json !== null) ? json_decode($ant_json, true) : null,
            'datos_nuevos'     => ($nue_json !== null) ? json_decode($nue_json, true) : null,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        starfi_log_modulo(
            $mod,
            sprintf(
                "AUDIT user=%d sede=%s accion=%s desc=%s antes=%s despues=%s",
                $uid,
                (string)($sede ?? '-'),
                $acc,
                $desc,
                (string)($ant_json ?? 'null'),
                (string)($nue_json ?? 'null')
            ),
            'AUDIT'
        );

        if (!($con instanceof mysqli)) {
            return false;
        }

        starfi_ensure_audit_schema($con);
        $stmt = @$con->prepare("
            INSERT INTO `auditoria_sistema`
                (`id_usuario`, `usuario_nombre`, `id_sede`, `modulo`, `accion`, `descripcion`, `entidad`, `id_registro`, `datos_anteriores`, `datos_nuevos`, `detalle_json`, `ip_origen`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            if ($own_con) {
                @$con->close();
            }
            return false;
        }
        $uid_param = ($uid > 0) ? $uid : null;
        $unom_param = ($unom !== '') ? $unom : null;
        $desc_param = ($desc !== '') ? $desc : null;
        $stmt->bind_param(
            "isisssssssss",
            $uid_param,
            $unom_param,
            $sede,
            $mod,
            $acc,
            $desc_param,
            $ent_param,
            $id_reg_param,
            $ant_json,
            $nue_json,
            $det_json,
            $ip
        );
        $ok = @$stmt->execute();
        $stmt->close();
        if ($own_con) {
            @$con->close();
        }
        return (bool)$ok;
    }
}
