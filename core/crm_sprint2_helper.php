<?php
/**
 * core/crm_sprint2_helper.php
 * Motor central para OT-STARFI-2026-11 (SPRINT 2):
 * - Auto-migración ligera e indexada (Presencia en línea, Round-Robin, Pipeline Kanban USD, SLA)
 * - Heartbeat de presencia de asesores en línea por sede
 * - Enrutamiento Automático Round-Robin ([COM-03])
 * - Sincronización de Oportunidades del Embudo de Ventas ([COM-02])
 */

if (!function_exists('starfi_ensure_sprint2_schema')) {
    function starfi_ensure_sprint2_schema($con) {
        if (!$con) {
            return;
        }
        $flag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'starfi_sprint2_schema_v2.flag';
        if (@file_exists($flag) && (time() - @filemtime($flag)) < 86400) {
            return;
        }

        // 1. Columnas de presencia y Round-Robin en tabla `usuario`
        $u_cols = [];
        $res_u = @mysqli_query($con, "SHOW COLUMNS FROM `usuario`");
        if ($res_u) {
            while ($r = mysqli_fetch_assoc($res_u)) {
                $u_cols[$r['Field']] = true;
            }
        }
        if (!isset($u_cols['ultima_actividad'])) {
            @mysqli_query($con, "ALTER TABLE `usuario` ADD COLUMN `ultima_actividad` DATETIME DEFAULT NULL");
        }
        if (!isset($u_cols['disponible_chat'])) {
            @mysqli_query($con, "ALTER TABLE `usuario` ADD COLUMN `disponible_chat` TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!isset($u_cols['ultima_asignacion_rr'])) {
            @mysqli_query($con, "ALTER TABLE `usuario` ADD COLUMN `ultima_asignacion_rr` DATETIME DEFAULT NULL");
        }

        $r_idx_u = @mysqli_query($con, "SHOW INDEX FROM `usuario` WHERE Key_name = 'idx_sede_online_rr'");
        if ($r_idx_u && mysqli_num_rows($r_idx_u) === 0) {
            @mysqli_query($con, "ALTER TABLE `usuario` ADD INDEX `idx_sede_online_rr` (`id_sede`, `estado`, `disponible_chat`, `ultima_actividad`)");
        }

        // 2. Índice compuesto para Ventana Meta 24h ([COM-01]) y Semáforo SLA ([COM-04])
        $r_idx_m = @mysqli_query($con, "SHOW INDEX FROM `mensajes_y_eventos` WHERE Key_name = 'idx_conv_origen_ts'");
        if ($r_idx_m && mysqli_num_rows($r_idx_m) === 0) {
            @mysqli_query($con, "ALTER TABLE `mensajes_y_eventos` ADD INDEX `idx_conv_origen_ts` (`id_conversacion`, `origen`, `timestamp`)");
        }

        // 3. Tabla Kanban de Embudo de Ventas ([COM-02])
        @mysqli_query($con, "CREATE TABLE IF NOT EXISTS `pipeline_oportunidades` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `id_sede` INT NOT NULL DEFAULT 0,
            `id_cliente` INT NOT NULL DEFAULT 0,
            `id_conversacion` INT DEFAULT NULL,
            `id_agente` INT DEFAULT NULL,
            `titulo` VARCHAR(200) NOT NULL DEFAULT 'Oportunidad Comercial',
            `etapa` ENUM('NUEVO_LEAD','COTIZADO','EN_NEGOCIACION','PAGO_PENDIENTE','GANADO','PERDIDO') NOT NULL DEFAULT 'NUEVO_LEAD',
            `monto_estimado_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `probabilidad` INT NOT NULL DEFAULT 20,
            `notas` TEXT DEFAULT NULL,
            `motivo_perdida` VARCHAR(200) DEFAULT NULL,
            `orden_kanban` INT NOT NULL DEFAULT 0,
            `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `fecha_actualizacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `fecha_cierre` DATETIME DEFAULT NULL,
            INDEX `idx_sede_etapa` (`id_sede`, `etapa`, `orden_kanban`),
            INDEX `idx_conversacion` (`id_conversacion`),
            INDEX `idx_cliente` (`id_cliente`),
            INDEX `idx_agente` (`id_agente`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @file_put_contents($flag, date('Y-m-d H:i:s'));
    }
}

if (!function_exists('starfi_touch_agent_presence')) {
    /**
     * Registra actividad reciente del usuario en BD para detección en tiempo real de asesores en línea (Round-Robin).
     */
    function starfi_touch_agent_presence($con, $agente_id, $force = false) {
        $uid = intval($agente_id);
        if (!$con || $uid <= 1) {
            return;
        }
        if (!$force && session_status() === PHP_SESSION_ACTIVE) {
            $last_hb = intval($_SESSION['last_db_heartbeat'] ?? 0);
            if ((time() - $last_hb) < 25) {
                return;
            }
            $_SESSION['last_db_heartbeat'] = time();
        }
        starfi_ensure_sprint2_schema($con);
        @mysqli_query($con, "UPDATE `usuario` SET `ultima_actividad` = NOW() WHERE `id` = $uid");
    }
}

if (!function_exists('starfi_ensure_pipeline_lead')) {
    /**
     * Garantiza que exista una tarjeta activa en el Embudo de Ventas para la conversación/cliente indicados.
     */
    function starfi_ensure_pipeline_lead($con, $id_sede, $id_cliente, $id_conversacion = null, $id_agente = null, $titulo_default = '') {
        if (!$con || intval($id_cliente) <= 0) {
            return 0;
        }
        starfi_ensure_sprint2_schema($con);
        $sede = intval($id_sede);
        $cli = intval($id_cliente);
        $conv = ($id_conversacion !== null && intval($id_conversacion) > 0) ? intval($id_conversacion) : null;
        $ag = ($id_agente !== null && intval($id_agente) > 0) ? intval($id_agente) : null;

        // Buscar oportunidad abierta existente para esta conversación o cliente en la sede
        $where_match = $conv ? "(`id_conversacion` = $conv OR (`id_cliente` = $cli AND `etapa` NOT IN ('GANADO','PERDIDO')))" : "(`id_cliente` = $cli AND `etapa` NOT IN ('GANADO','PERDIDO'))";
        if ($sede > 0) {
            $where_match .= " AND (`id_sede` = $sede OR `id_sede` = 0)";
        }
        $res = @mysqli_query($con, "SELECT `id`, `id_agente`, `id_conversacion`, `id_sede` FROM `pipeline_oportunidades` WHERE $where_match ORDER BY `id` DESC LIMIT 1");
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            $deal_id = intval($row['id']);
            $updates = [];
            if ($ag && intval($row['id_agente'] ?? 0) === 0) {
                $updates[] = "`id_agente` = $ag";
            }
            if ($conv && intval($row['id_conversacion'] ?? 0) === 0) {
                $updates[] = "`id_conversacion` = $conv";
            }
            if ($sede > 0 && intval($row['id_sede'] ?? 0) === 0) {
                $updates[] = "`id_sede` = $sede";
            }
            if (!empty($updates)) {
                @mysqli_query($con, "UPDATE `pipeline_oportunidades` SET " . implode(', ', $updates) . " WHERE `id` = $deal_id");
            }
            return $deal_id;
        }

        // Obtener nombre del cliente si no se pasó título
        $titulo = trim((string)$titulo_default);
        if ($titulo === '') {
            $rc = @mysqli_query($con, "SELECT `nombre`, `numero_whatsapp` FROM `clientes_contactos` WHERE `id` = $cli LIMIT 1");
            if ($rc && ($cl = mysqli_fetch_assoc($rc))) {
                $nom = trim((string)($cl['nombre'] ?? ''));
                $tel = trim((string)($cl['numero_whatsapp'] ?? ''));
                $titulo = ($nom !== '') ? "Lead WhatsApp - $nom" : "Lead WhatsApp +$tel";
            } else {
                $titulo = "Lead WhatsApp #$cli";
            }
        }

        $conv_sql = $conv ? $conv : 'NULL';
        $ag_sql = $ag ? $ag : 'NULL';
        $tit_esc = mysqli_real_escape_string($con, mb_substr($titulo, 0, 195));
        $ins = @mysqli_query($con, "INSERT INTO `pipeline_oportunidades` (`id_sede`, `id_cliente`, `id_conversacion`, `id_agente`, `titulo`, `etapa`, `monto_estimado_usd`, `probabilidad`)
                                    VALUES ($sede, $cli, $conv_sql, $ag_sql, '$tit_esc', 'NUEVO_LEAD', 0.00, 20)");
        return $ins ? intval(mysqli_insert_id($con)) : 0;
    }
}

if (!function_exists('starfi_assign_round_robin_advisor')) {
    /**
     * [COM-03] Enrutamiento Automático Round-Robin entre asesores EN LÍNEA de la sede.
     * Ventana de presencia en línea: actividad en los últimos 5 minutos y disponible_chat = 1.
     * Respeta limite_chats_simultaneos (con fallback a todos los conectados si todos están al tope).
     *
     * @return array|null Datos del asesor asignado ['id', 'nombre', 'telefono'] o null si no hay asesores en línea.
     */
    function starfi_assign_round_robin_advisor($con, $id_sede, $id_conversacion, $id_cliente = 0, $motivo_origen = 'IA', $online_window_minutes = 5) {
        if (!$con || intval($id_sede) <= 0 || intval($id_conversacion) <= 0) {
            return null;
        }
        starfi_ensure_sprint2_schema($con);

        $sede = intval($id_sede);
        $conv_id = intval($id_conversacion);
        $win_min = max(1, intval($online_window_minutes));

        // Buscar asesores/operadores activos y EN LÍNEA de la sede ordenados por Round-Robin estricto (el que lleva más tiempo sin recibir lead va primero)
        $sql_rr = "
            SELECT 
                u.id,
                u.usuario,
                up.nombre AS up_nombre,
                up.telefono AS up_telefono,
                IFNULL(u.limite_chats_simultaneos, 0) AS limite_chats,
                IFNULL(carga.chats_activos, 0) AS chats_activos,
                u.ultima_asignacion_rr
            FROM usuario u
            LEFT JOIN usuario_perfil up ON up.id_usuario = u.id
            LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
            LEFT JOIN (
                SELECT id_agente, COUNT(*) AS chats_activos
                FROM conversaciones
                WHERE estado IN ('ESPERA_ASIGNACION', 'ATENDIENDO')
                  AND id_agente IS NOT NULL AND id_agente > 0
                GROUP BY id_agente
            ) carga ON carga.id_agente = u.id
            WHERE u.id_sede = $sede
              AND (u.estado = 'ACTIVO' OR CAST(u.estado AS CHAR) = '1')
              AND IFNULL(u.disponible_chat, 1) = 1
              AND u.id != 1
              AND LOWER(u.usuario) != 'master'
              AND (
                  UPPER(TRIM(IFNULL(r.nombre, ''))) IN ('OPERADOR', 'ASESOR', 'AGENTE', 'VENDEDOR')
                  OR UPPER(TRIM(IFNULL(CAST(u.rol AS CHAR), ''))) IN ('3', 'OPERADOR', 'ASESOR', 'AGENTE', 'VENDEDOR')
              )
              AND u.ultima_actividad IS NOT NULL
              AND u.ultima_actividad >= (NOW() - INTERVAL $win_min MINUTE)
            ORDER BY 
              (CASE WHEN IFNULL(u.limite_chats_simultaneos, 0) > 0 AND IFNULL(carga.chats_activos, 0) >= u.limite_chats_simultaneos THEN 1 ELSE 0 END) ASC,
              (u.ultima_asignacion_rr IS NULL) DESC,
              u.ultima_asignacion_rr ASC,
              chats_activos ASC,
              u.id ASC
            LIMIT 1
        ";

        $res_rr = @mysqli_query($con, $sql_rr);
        $elegido = ($res_rr && mysqli_num_rows($res_rr) > 0) ? mysqli_fetch_assoc($res_rr) : null;

        if (!$elegido) {
            // Ningún asesor en línea en la sede: dejar en ESPERA_ASIGNACION para cola general de la sede
            @mysqli_query($con, "UPDATE `conversaciones` SET `estado` = 'ESPERA_ASIGNACION' WHERE `id` = $conv_id");
            $msg_cola = mysqli_real_escape_string($con, "⏳ Transferencia ($motivo_origen) en cola de espera: no hay asesores en línea en esta sede en este momento.");
            @mysqli_query($con, "INSERT INTO `mensajes_y_eventos` (`id_conversacion`, `origen`, `tipo`, `contenido`) VALUES ($conv_id, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', '$msg_cola')");
            $ev_id = intval(mysqli_insert_id($con));
            if ($ev_id > 0) {
                @mysqli_query($con, "UPDATE `conversaciones` SET `ultimo_msg_id` = GREATEST(IFNULL(`ultimo_msg_id`, 0), $ev_id) WHERE `id` = $conv_id");
            }
            if (intval($id_cliente) > 0) {
                starfi_ensure_pipeline_lead($con, $sede, intval($id_cliente), $conv_id, null);
            }
            return null;
        }

        $asesor_id = intval($elegido['id']);
        $up_nom = trim((string)($elegido['up_nombre'] ?? ''));
        $asesor_nombre = ($up_nom !== '') ? $up_nom : trim((string)($elegido['usuario'] ?? 'Asesor'));
        $asesor_tel = trim((string)($elegido['up_telefono'] ?? ''));

        // Actualizar puntero Round-Robin del asesor y asignar conversación
        @mysqli_query($con, "UPDATE `usuario` SET `ultima_asignacion_rr` = NOW() WHERE `id` = $asesor_id");
        @mysqli_query($con, "UPDATE `conversaciones` SET `id_agente` = $asesor_id, `estado` = 'ESPERA_ASIGNACION' WHERE `id` = $conv_id");

        // Registrar trazabilidad en el historial del chat
        $ev_txt = mysqli_real_escape_string($con, "🔀 Enrutamiento Round-Robin ($motivo_origen): conversación asignada automáticamente al asesor en línea $asesor_nombre.");
        @mysqli_query($con, "INSERT INTO `mensajes_y_eventos` (`id_conversacion`, `origen`, `tipo`, `contenido`) VALUES ($conv_id, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', '$ev_txt')");
        $ev_id = intval(mysqli_insert_id($con));
        if ($ev_id > 0) {
            @mysqli_query($con, "UPDATE `conversaciones` SET `ultimo_msg_id` = GREATEST(IFNULL(`ultimo_msg_id`, 0), $ev_id) WHERE `id` = $conv_id");
        }

        // Sincronizar tarjeta en el Embudo de Ventas (Nuevo Lead)
        if (intval($id_cliente) > 0) {
            starfi_ensure_pipeline_lead($con, $sede, intval($id_cliente), $conv_id, $asesor_id);
        }

        return [
            'id' => $asesor_id,
            'nombre' => $asesor_nombre,
            'telefono' => $asesor_tel
        ];
    }
}
