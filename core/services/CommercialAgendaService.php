<?php
/**
 * core/services/CommercialAgendaService.php
 * Motor empresarial de Tareas, Recordatorios y Agenda Comercial por Cliente
 * con Alertas en Tiempo Real en la Barra Superior ([OMN-02] — OT-STARFI-2026-11 SPRINT 4).
 *
 * Cumplimiento estricto QA-STD-GLOBAL-2.1:
 * - 100% mysqli_prepare() + bind_param() (0 interpolación SQL).
 * - Aislamiento estricto Multi-Sede (id_sede) y RBAC por rol (Master / Admin / Operador).
 * - DDL omitido dentro de transacciones activas (starfi_tx_is_active).
 * - Trazabilidad forense con registrar_auditoria() en todas las mutaciones.
 */

require_once __DIR__ . '/../crm_sprint2_helper.php';
require_once __DIR__ . '/../audit.php';

class CommercialAgendaService
{
    const VALID_TYPES = ['LLAMADA', 'COTIZACION', 'SEGUIMIENTO', 'REUNION', 'COBRO', 'OTRO'];
    const VALID_PRIORITIES = ['BAJA', 'MEDIA', 'ALTA', 'URGENTE'];
    const VALID_STATES = ['PENDIENTE', 'COMPLETADA', 'POSPUESTA', 'CANCELADA'];

    /**
     * Garantiza el esquema físico e índices compuestos de `tareas_agenda_comercial`.
     * Nunca ejecuta DDL si existe una transacción activa en curso (CHK-SEC-05).
     */
    public static function ensureSchema($con): void
    {
        if (!$con) {
            return;
        }
        if (function_exists('starfi_tx_is_active') && starfi_tx_is_active($con)) {
            return;
        }

        $dbHash = function_exists('starfi_db_schema_hash') ? starfi_db_schema_hash($con) : 'default';
        $flag = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'starfi_sprint4_agenda_v1_' . $dbHash . '.flag';
        if (@file_exists($flag) && (time() - @filemtime($flag)) < 86400) {
            $chk = @mysqli_query($con, "SHOW TABLES LIKE 'tareas_agenda_comercial'");
            if ($chk && mysqli_num_rows($chk) > 0) {
                return;
            }
        }

        @mysqli_query($con, "CREATE TABLE IF NOT EXISTS `tareas_agenda_comercial` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `id_empresa` INT NOT NULL DEFAULT 1,
            `id_sede` INT NOT NULL DEFAULT 0,
            `id_cliente` INT NOT NULL DEFAULT 0,
            `id_conversacion` INT DEFAULT NULL,
            `id_oportunidad` INT DEFAULT NULL,
            `id_agente_asignado` INT NOT NULL DEFAULT 0,
            `creado_por` INT NOT NULL DEFAULT 0,
            `titulo` VARCHAR(200) NOT NULL,
            `descripcion` TEXT DEFAULT NULL,
            `tipo` VARCHAR(30) NOT NULL DEFAULT 'SEGUIMIENTO',
            `prioridad` VARCHAR(20) NOT NULL DEFAULT 'MEDIA',
            `fecha_vencimiento` DATETIME NOT NULL,
            `recordatorio_min_antes` INT NOT NULL DEFAULT 15,
            `alerta_disparada` TINYINT(1) NOT NULL DEFAULT 0,
            `estado` VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
            `fecha_completada` DATETIME DEFAULT NULL,
            `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `fecha_actualizacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_agente_estado_venc` (`id_agente_asignado`, `estado`, `fecha_vencimiento`),
            INDEX `idx_sede_estado_venc` (`id_sede`, `estado`, `fecha_vencimiento`),
            INDEX `idx_cliente_estado` (`id_cliente`, `estado`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @file_put_contents($flag, date('Y-m-d H:i:s'));
    }

    /**
     * Normaliza el contexto RBAC y Sede del agente autenticado.
     */
    public static function parseAgentScope(array $agenteInfo): array
    {
        $agenteId = intval($agenteInfo['id'] ?? 0);
        $rol = strtoupper(trim((string)($agenteInfo['rol'] ?? 'AGENTE')));
        $idSede = intval($agenteInfo['id_sede'] ?? 0);
        $isMaster = ($agenteId === 1 || $rol === 'MASTER' || $rol === 'MASTER CI' || ($rol === 'ADMIN' && $idSede === 0));
        $isAdmin = (!$isMaster && in_array($rol, ['ADMINISTRADOR', 'ADMIN', 'GERENTE'], true));
        return [
            'agente_id'   => $agenteId,
            'rol'         => $rol,
            'id_sede'     => $idSede,
            'is_master'   => $isMaster,
            'is_admin'    => $isAdmin,
            'is_operador' => (!$isMaster && !$isAdmin),
        ];
    }

    /**
     * Convierte entradas de fecha/hora ('YYYY-MM-DDTHH:MM' o 'YYYY-MM-DD HH:MM:SS') a formato MySQL DATETIME.
     */
    public static function normalizeDateTimeInput(string $raw): ?string
    {
        $clean = trim(str_replace('T', ' ', $raw));
        if ($clean === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $clean)) {
            $clean .= ':00';
        }
        $ts = strtotime($clean);
        if ($ts === false || $ts < 1700000000) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Verifica pertenencia Multi-Sede de un cliente y/o conversación antes de operar sobre su agenda.
     */
    public static function resolveAndAuthorizeTarget($con, array $scope, int $clienteId, int $conversacionId = 0, int $requestedSede = 0): array
    {
        $resolvedSede = $scope['is_master'] ? max(0, $requestedSede) : $scope['id_sede'];
        $resolvedCliente = max(0, $clienteId);
        $resolvedConv = max(0, $conversacionId);
        $idEmpresa = 1;

        if (!$scope['is_master'] && $scope['id_sede'] <= 0) {
            return ['ok' => false, 'http_code' => 403, 'message' => 'Tu usuario no tiene sede asignada.'];
        }

        if ($resolvedConv > 0) {
            $stmtC = $con->prepare("
                SELECT c.id, c.id_cliente, c.id_agente, c.estado, l.id_sede, cl.id_empresa
                FROM conversaciones c
                JOIN lineas_whatsapp l ON c.id_linea = l.id
                LEFT JOIN clientes_contactos cl ON c.id_cliente = cl.id
                WHERE c.id = ?
                LIMIT 1
            ");
            if (!$stmtC) {
                return ['ok' => false, 'http_code' => 500, 'message' => 'Error interno al verificar la conversación.'];
            }
            $stmtC->bind_param("i", $resolvedConv);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            $rowC = $resC ? $resC->fetch_assoc() : null;
            $stmtC->close();

            if (!$rowC) {
                return ['ok' => false, 'http_code' => 404, 'message' => 'Conversación no encontrada.'];
            }
            $convSede = intval($rowC['id_sede'] ?? 0);
            if (!$scope['is_master'] && $convSede !== $scope['id_sede']) {
                return ['ok' => false, 'http_code' => 403, 'message' => 'Acceso denegado: la conversación pertenece a otra sede.'];
            }
            $resolvedSede = $convSede;
            if ($resolvedCliente <= 0) {
                $resolvedCliente = intval($rowC['id_cliente'] ?? 0);
            }
            if (!empty($rowC['id_empresa'])) {
                $idEmpresa = intval($rowC['id_empresa']);
            }
        } elseif ($resolvedCliente > 0) {
            $stmtCl = $con->prepare("SELECT id, id_empresa, id_sede FROM clientes_contactos WHERE id = ? LIMIT 1");
            if (!$stmtCl) {
                return ['ok' => false, 'http_code' => 500, 'message' => 'Error interno al verificar el cliente.'];
            }
            $stmtCl->bind_param("i", $resolvedCliente);
            $stmtCl->execute();
            $resCl = $stmtCl->get_result();
            $rowCl = $resCl ? $resCl->fetch_assoc() : null;
            $stmtCl->close();

            if (!$rowCl) {
                return ['ok' => false, 'http_code' => 404, 'message' => 'Cliente no encontrado.'];
            }
            $cliSede = intval($rowCl['id_sede'] ?? 0);
            if ($cliSede <= 0) {
                $stmtCvS = $con->prepare("
                    SELECT l.id_sede
                    FROM conversaciones c
                    JOIN lineas_whatsapp l ON c.id_linea = l.id
                    WHERE c.id_cliente = ?
                    ORDER BY c.id DESC
                    LIMIT 1
                ");
                if ($stmtCvS) {
                    $stmtCvS->bind_param("i", $resolvedCliente);
                    $stmtCvS->execute();
                    $rCvS = $stmtCvS->get_result();
                    if ($rCvS && ($rwS = $rCvS->fetch_assoc())) {
                        $cliSede = intval($rwS['id_sede'] ?? 0);
                    }
                    $stmtCvS->close();
                }
            }
            if (!$scope['is_master'] && $cliSede > 0 && $cliSede !== $scope['id_sede']) {
                return ['ok' => false, 'http_code' => 403, 'message' => 'Acceso denegado: el cliente pertenece a otra sede.'];
            }
            if ($cliSede > 0) {
                $resolvedSede = $cliSede;
            }
            if (!empty($rowCl['id_empresa'])) {
                $idEmpresa = intval($rowCl['id_empresa']);
            }
        } else {
            if (!$scope['is_master'] && $requestedSede > 0 && $requestedSede !== $scope['id_sede']) {
                return ['ok' => false, 'http_code' => 403, 'message' => 'No puedes agendar tareas en otra sede.'];
            }
        }

        return [
            'ok'              => true,
            'id_empresa'      => max(1, $idEmpresa),
            'id_sede'         => $resolvedSede,
            'id_cliente'      => $resolvedCliente,
            'id_conversacion' => $resolvedConv > 0 ? $resolvedConv : null,
        ];
    }

    /**
     * Crea una tarea o recordatorio comercial asociado a un cliente/conversación o general del asesor.
     */
    public static function createTask($con, array $agenteInfo, array $payload, string $auditModule = 'bandeja'): array
    {
        if (!$con) {
            return ['ok' => false, 'http_code' => 500, 'message' => 'Sin conexión a base de datos.'];
        }
        self::ensureSchema($con);

        $scope = self::parseAgentScope($agenteInfo);
        if ($scope['agente_id'] <= 0) {
            return ['ok' => false, 'http_code' => 401, 'message' => 'Sesión no válida.'];
        }

        $titulo = trim((string)($payload['titulo'] ?? ''));
        if (mb_strlen($titulo, 'UTF-8') < 3) {
            return ['ok' => false, 'http_code' => 400, 'message' => 'El título de la tarea debe tener al menos 3 caracteres.'];
        }
        $titulo = mb_substr($titulo, 0, 200, 'UTF-8');
        $descripcion = trim((string)($payload['descripcion'] ?? ''));
        if ($descripcion !== '') {
            $descripcion = mb_substr($descripcion, 0, 1500, 'UTF-8');
        } else {
            $descripcion = null;
        }

        $tipo = strtoupper(trim((string)($payload['tipo'] ?? 'SEGUIMIENTO')));
        if (!in_array($tipo, self::VALID_TYPES, true)) {
            $tipo = 'SEGUIMIENTO';
        }
        $prioridad = strtoupper(trim((string)($payload['prioridad'] ?? 'MEDIA')));
        if (!in_array($prioridad, self::VALID_PRIORITIES, true)) {
            $prioridad = 'MEDIA';
        }

        $fechaVenc = self::normalizeDateTimeInput((string)($payload['fecha_vencimiento'] ?? ''));
        if ($fechaVenc === null) {
            return ['ok' => false, 'http_code' => 400, 'message' => 'Debes indicar una fecha y hora válida para el recordatorio.'];
        }

        $recordatorioMin = max(0, min(1440, intval($payload['recordatorio_min_antes'] ?? 15)));
        $clienteId = intval($payload['cliente_id'] ?? ($payload['id_cliente'] ?? 0));
        $convId = intval($payload['conversacion_id'] ?? ($payload['id_conversacion'] ?? 0));
        $reqSede = intval($payload['id_sede'] ?? 0);

        $target = self::resolveAndAuthorizeTarget($con, $scope, $clienteId, $convId, $reqSede);
        if (empty($target['ok'])) {
            return $target;
        }

        $idSede = intval($target['id_sede']);
        $idEmpresa = intval($target['id_empresa']);
        $idCliente = intval($target['id_cliente']);
        $idConv = $target['id_conversacion'];

        $asignadoA = intval($payload['id_agente_asignado'] ?? 0);
        if ($asignadoA <= 0 || $scope['is_operador']) {
            $asignadoA = $scope['agente_id'];
        } elseif (!$scope['is_master']) {
            $stmtAg = $con->prepare("SELECT id, id_sede FROM usuario WHERE id = ? LIMIT 1");
            if ($stmtAg) {
                $stmtAg->bind_param("i", $asignadoA);
                $stmtAg->execute();
                $rAg = $stmtAg->get_result();
                $rowAg = $rAg ? $rAg->fetch_assoc() : null;
                $stmtAg->close();
                if (!$rowAg || (intval($rowAg['id']) !== 1 && intval($rowAg['id_sede'] ?? 0) !== $scope['id_sede'])) {
                    return ['ok' => false, 'http_code' => 403, 'message' => 'No puedes asignar tareas a un usuario de otra sede.'];
                }
            }
        }

        $idOportunidad = null;
        if ($idCliente > 0 || !empty($idConv)) {
            $convVal = intval($idConv ?? 0);
            $stmtOp = $con->prepare("
                SELECT id FROM pipeline_oportunidades
                WHERE id_sede = ? AND ((? > 0 AND id_conversacion = ?) OR (? > 0 AND id_cliente = ?))
                ORDER BY id DESC LIMIT 1
            ");
            if ($stmtOp) {
                $stmtOp->bind_param("iiiii", $idSede, $convVal, $convVal, $idCliente, $idCliente);
                $stmtOp->execute();
                $rOp = $stmtOp->get_result();
                if ($rOp && ($rwOp = $rOp->fetch_assoc())) {
                    $idOportunidad = intval($rwOp['id']);
                }
                $stmtOp->close();
            }
        }

        $stmtIns = $con->prepare("
            INSERT INTO tareas_agenda_comercial (
                id_empresa, id_sede, id_cliente, id_conversacion, id_oportunidad,
                id_agente_asignado, creado_por, titulo, descripcion, tipo, prioridad,
                fecha_vencimiento, recordatorio_min_antes, alerta_disparada, estado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'PENDIENTE')
        ");
        if (!$stmtIns) {
            starfi_log_exception($auditModule, 'Error preparando INSERT tareas_agenda_comercial', ['sql_error' => $con->error]);
            return ['ok' => false, 'http_code' => 500, 'message' => 'Error interno al registrar la tarea.'];
        }
        $creadoPor = $scope['agente_id'];
        $stmtIns->bind_param(
            "iiiiiiisssssi",
            $idEmpresa,
            $idSede,
            $idCliente,
            $idConv,
            $idOportunidad,
            $asignadoA,
            $creadoPor,
            $titulo,
            $descripcion,
            $tipo,
            $prioridad,
            $fechaVenc,
            $recordatorioMin
        );
        if (!$stmtIns->execute()) {
            starfi_log_exception($auditModule, 'Error ejecutando INSERT tareas_agenda_comercial', ['sql_error' => $stmtIns->error]);
            $stmtIns->close();
            return ['ok' => false, 'http_code' => 500, 'message' => 'No se pudo guardar la tarea en la agenda comercial.'];
        }
        $newTaskId = intval($stmtIns->insert_id);
        $stmtIns->close();

        if (!empty($idConv) && intval($idConv) > 0) {
            $convInt = intval($idConv);
            $fmtDt = date('d/m/Y H:i', strtotime($fechaVenc));
            $evText = "📅 Tarea agendada [$tipo · $prioridad]: $titulo (Vence: $fmtDt)";
            $stmtEv = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES (?, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', ?)");
            if ($stmtEv) {
                $stmtEv->bind_param("is", $convInt, $evText);
                if ($stmtEv->execute()) {
                    $evMsgId = intval($stmtEv->insert_id);
                    $stmtUpC = $con->prepare("UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), ?) WHERE id = ?");
                    if ($stmtUpC) {
                        $stmtUpC->bind_param("ii", $evMsgId, $convInt);
                        $stmtUpC->execute();
                        $stmtUpC->close();
                    }
                }
                $stmtEv->close();
            }
        }

        $taskData = [
            'id'                     => $newTaskId,
            'id_sede'                => $idSede,
            'id_cliente'             => $idCliente,
            'id_conversacion'        => $idConv,
            'id_agente_asignado'     => $asignadoA,
            'titulo'                 => $titulo,
            'tipo'                   => $tipo,
            'prioridad'              => $prioridad,
            'fecha_vencimiento'      => $fechaVenc,
            'recordatorio_min_antes' => $recordatorioMin,
            'estado'                 => 'PENDIENTE',
        ];

        registrar_auditoria(
            $auditModule,
            'CREAR_TAREA_AGENDA',
            "Tarea comercial #$newTaskId agendada ($tipo · $titulo)",
            null,
            $taskData,
            $con,
            $scope['agente_id'],
            $idSede ?: null
        );

        return [
            'ok'      => true,
            'task_id' => $newTaskId,
            'task'    => $taskData,
            'message' => 'Tarea agendada correctamente con recordatorio activo.',
        ];
    }

    /**
     * Actualiza el estado de una tarea (COMPLETAR, POSPONER, REABRIR, CANCELAR) con bloqueo y auditoría.
     */
    public static function updateTaskStatus($con, array $agenteInfo, int $taskId, string $operation, array $extra = [], string $auditModule = 'bandeja'): array
    {
        if (!$con || $taskId <= 0) {
            return ['ok' => false, 'http_code' => 400, 'message' => 'Identificador de tarea inválido.'];
        }
        self::ensureSchema($con);
        $scope = self::parseAgentScope($agenteInfo);
        if ($scope['agente_id'] <= 0) {
            return ['ok' => false, 'http_code' => 401, 'message' => 'Sesión no válida.'];
        }

        $stmtGet = $con->prepare("SELECT * FROM tareas_agenda_comercial WHERE id = ? LIMIT 1");
        if (!$stmtGet) {
            return ['ok' => false, 'http_code' => 500, 'message' => 'Error interno al consultar la tarea.'];
        }
        $stmtGet->bind_param("i", $taskId);
        $stmtGet->execute();
        $resGet = $stmtGet->get_result();
        $taskBefore = $resGet ? $resGet->fetch_assoc() : null;
        $stmtGet->close();

        if (!$taskBefore) {
            return ['ok' => false, 'http_code' => 404, 'message' => 'La tarea solicitada no existe.'];
        }

        $taskSede = intval($taskBefore['id_sede'] ?? 0);
        if (!$scope['is_master']) {
            if ($scope['id_sede'] <= 0 || ($taskSede > 0 && $taskSede !== $scope['id_sede'])) {
                return ['ok' => false, 'http_code' => 403, 'message' => 'No tienes autorización para modificar tareas de otra sede.'];
            }
            if ($scope['is_operador']) {
                $asig = intval($taskBefore['id_agente_asignado'] ?? 0);
                $crea = intval($taskBefore['creado_por'] ?? 0);
                if ($asig !== $scope['agente_id'] && $crea !== $scope['agente_id']) {
                    return ['ok' => false, 'http_code' => 403, 'message' => 'Solo puedes modificar tus propias tareas asignadas.'];
                }
            }
        }

        $op = strtoupper(trim($operation));
        $newState = $taskBefore['estado'];
        $newVenc = $taskBefore['fecha_vencimiento'];
        $fechaComp = $taskBefore['fecha_completada'];
        $alertaDisp = intval($taskBefore['alerta_disparada'] ?? 0);

        if ($op === 'COMPLETAR') {
            $newState = 'COMPLETADA';
            $fechaComp = date('Y-m-d H:i:s');
            $alertaDisp = 1;
        } elseif ($op === 'POSPONER') {
            $snoozeMin = max(5, min(10080, intval($extra['snooze_min'] ?? 30)));
            if (!empty($extra['nueva_fecha'])) {
                $parsedDt = self::normalizeDateTimeInput((string)$extra['nueva_fecha']);
                if ($parsedDt !== null) {
                    $newVenc = $parsedDt;
                } else {
                    $baseTs = max(time(), strtotime((string)$taskBefore['fecha_vencimiento']) ?: time());
                    $newVenc = date('Y-m-d H:i:s', $baseTs + ($snoozeMin * 60));
                }
            } else {
                $baseTs = max(time(), strtotime((string)$taskBefore['fecha_vencimiento']) ?: time());
                $newVenc = date('Y-m-d H:i:s', $baseTs + ($snoozeMin * 60));
            }
            $newState = 'PENDIENTE';
            $fechaComp = null;
            $alertaDisp = 0;
        } elseif ($op === 'REABRIR') {
            $newState = 'PENDIENTE';
            $fechaComp = null;
            $alertaDisp = 0;
        } elseif ($op === 'CANCELAR' || $op === 'ELIMINAR') {
            $newState = 'CANCELADA';
            $alertaDisp = 1;
        } else {
            return ['ok' => false, 'http_code' => 400, 'message' => 'Operación de agenda no reconocida.'];
        }

        $stmtUp = $con->prepare("
            UPDATE tareas_agenda_comercial
            SET estado = ?, fecha_vencimiento = ?, fecha_completada = ?, alerta_disparada = ?
            WHERE id = ?
        ");
        if (!$stmtUp) {
            return ['ok' => false, 'http_code' => 500, 'message' => 'Error interno al actualizar la tarea.'];
        }
        $stmtUp->bind_param("sssii", $newState, $newVenc, $fechaComp, $alertaDisp, $taskId);
        $okUp = $stmtUp->execute();
        $stmtUp->close();

        if (!$okUp) {
            return ['ok' => false, 'http_code' => 500, 'message' => 'No se pudo actualizar la tarea.'];
        }

        $convRef = intval($taskBefore['id_conversacion'] ?? 0);
        if ($op === 'COMPLETAR' && $convRef > 0) {
            $evDone = "✅ Tarea completada: " . (string)$taskBefore['titulo'];
            $stmtEv = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES (?, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', ?)");
            if ($stmtEv) {
                $stmtEv->bind_param("is", $convRef, $evDone);
                if ($stmtEv->execute()) {
                    $midEv = intval($stmtEv->insert_id);
                    $stmtUpC = $con->prepare("UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), ?) WHERE id = ?");
                    if ($stmtUpC) {
                        $stmtUpC->bind_param("ii", $midEv, $convRef);
                        $stmtUpC->execute();
                        $stmtUpC->close();
                    }
                }
                $stmtEv->close();
            }
        }

        $afterSnapshot = [
            'id'                => $taskId,
            'estado'            => $newState,
            'fecha_vencimiento' => $newVenc,
            'fecha_completada'  => $fechaComp,
            'operacion'         => $op,
        ];

        registrar_auditoria(
            $auditModule,
            'ACTUALIZAR_TAREA_AGENDA',
            "Tarea comercial #$taskId actualizada ($op -> $newState)",
            $taskBefore,
            $afterSnapshot,
            $con,
            $scope['agente_id'],
            $taskSede ?: null
        );

        return [
            'ok'      => true,
            'task_id' => $taskId,
            'estado'  => $newState,
            'fecha_vencimiento' => $newVenc,
            'message' => ($op === 'COMPLETAR') ? 'Tarea marcada como completada.' : (($op === 'POSPONER') ? 'Recordatorio pospuesto correctamente.' : 'Tarea actualizada.'),
        ];
    }

    /**
     * Lista la agenda histórica y pendiente de un cliente específico con control Multi-Sede.
     */
    public static function listClientTasks($con, array $agenteInfo, int $clienteId, int $conversacionId = 0): array
    {
        if (!$con) {
            return ['ok' => false, 'http_code' => 500, 'message' => 'Sin conexión a base de datos.'];
        }
        self::ensureSchema($con);
        $scope = self::parseAgentScope($agenteInfo);
        $target = self::resolveAndAuthorizeTarget($con, $scope, $clienteId, $conversacionId, 0);
        if (empty($target['ok'])) {
            return $target;
        }

        $resCli = intval($target['id_cliente']);
        $resConv = intval($target['id_conversacion'] ?? 0);
        $resSede = intval($target['id_sede']);

        if ($resCli <= 0 && $resConv <= 0) {
            return ['ok' => true, 'tasks' => [], 'pending_count' => 0];
        }

        $sql = "
            SELECT t.*,
                   COALESCE(up.nombre, u.usuario, 'Asesor') AS agente_nombre,
                   COALESCE(cl.nombre, cl.numero_whatsapp, 'Cliente') AS cliente_nombre,
                   cl.numero_whatsapp,
                   TIMESTAMPDIFF(MINUTE, NOW(), t.fecha_vencimiento) AS min_para_vencer
            FROM tareas_agenda_comercial t
            LEFT JOIN usuario u ON t.id_agente_asignado = u.id
            LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
            LEFT JOIN clientes_contactos cl ON t.id_cliente = cl.id
            WHERE t.estado != 'CANCELADA'
              AND ((? > 0 AND t.id_cliente = ?) OR (? > 0 AND t.id_conversacion = ?))
        ";
        $types = "iiii";
        $params = [$resCli, $resCli, $resConv, $resConv];

        if (!$scope['is_master'] && $resSede > 0) {
            $sql .= " AND t.id_sede = ?";
            $types .= "i";
            $params[] = $resSede;
        }
        $sql .= " ORDER BY (t.estado = 'PENDIENTE') DESC, t.fecha_vencimiento ASC, t.id DESC LIMIT 50";

        $stmt = $con->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'http_code' => 500, 'message' => 'Error al consultar tareas del cliente.'];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $tasks = [];
        $pendingCount = 0;
        $overdueCount = 0;
        while ($res && ($row = $res->fetch_assoc())) {
            $formatted = self::enrichTaskRow($row);
            if ($formatted['estado'] === 'PENDIENTE') {
                $pendingCount++;
                if ($formatted['urgencia_tiempo'] === 'VENCIDA') {
                    $overdueCount++;
                }
            }
            $tasks[] = $formatted;
        }
        $stmt->close();

        return [
            'ok'            => true,
            'tasks'         => $tasks,
            'pending_count' => $pendingCount,
            'overdue_count' => $overdueCount,
        ];
    }

    /**
     * Alimenta en tiempo real la campana y panel de Agenda Comercial en la barra superior de todo el CRM.
     */
    public static function getTopbarAlertsAndAgenda($con, array $agenteInfo, bool $markDispatched = false): array
    {
        if (!$con) {
            return ['ok' => false, 'http_code' => 500, 'message' => 'Sin conexión a base de datos.'];
        }
        self::ensureSchema($con);
        $scope = self::parseAgentScope($agenteInfo);
        if ($scope['agente_id'] <= 0) {
            return ['ok' => false, 'http_code' => 401, 'message' => 'No autenticado.'];
        }
        if (!$scope['is_master'] && $scope['id_sede'] <= 0) {
            return [
                'ok'                  => true,
                'overdue_count'       => 0,
                'due_soon_count'      => 0,
                'today_pending_count' => 0,
                'badge_count'         => 0,
                'tasks'               => [],
                'newly_triggered'     => [],
                'server_now'          => date('Y-m-d H:i:s'),
            ];
        }

        $canViewCommercialTeam = array_key_exists('__can_view_commercial_team', $agenteInfo)
            ? (intval($agenteInfo['__can_view_commercial_team']) === 1)
            : (
                !function_exists('hasPermission')
                || hasPermission('bandeja')
                || hasPermission('pipeline')
                || hasPermission('directorio')
            );

        $where = "t.estado = 'PENDIENTE'";
        $types = "";
        $params = [];

        if ($scope['is_master']) {
            // Master ve sus propias tareas y las tareas pendientes/vencidas de todas las sedes
            $where .= " AND 1=1";
        } elseif ($scope['is_admin'] && $canViewCommercialTeam) {
            $where .= " AND t.id_sede = ?";
            $types .= "i";
            $params[] = $scope['id_sede'];
        } else {
            $where .= " AND t.id_sede = ? AND t.id_agente_asignado = ?";
            $types .= "ii";
            $params[] = $scope['id_sede'];
            $params[] = $scope['agente_id'];
        }

        // 1. Contadores globales exactos para el badge en tiempo real
        $sqlCounts = "
            SELECT
                COUNT(*) AS total_pending,
                SUM(CASE WHEN t.fecha_vencimiento <= NOW() THEN 1 ELSE 0 END) AS overdue_cnt,
                SUM(CASE WHEN t.fecha_vencimiento > NOW() AND TIMESTAMPDIFF(MINUTE, NOW(), t.fecha_vencimiento) <= t.recordatorio_min_antes THEN 1 ELSE 0 END) AS due_soon_cnt,
                SUM(CASE WHEN DATE(t.fecha_vencimiento) = CURDATE() THEN 1 ELSE 0 END) AS today_cnt
            FROM tareas_agenda_comercial t
            WHERE $where
        ";
        $stmtCnt = $con->prepare($sqlCounts);
        $overdueCnt = 0;
        $dueSoonCnt = 0;
        $todayCnt = 0;
        $totalPending = 0;

        if ($stmtCnt) {
            if ($types !== '') {
                $stmtCnt->bind_param($types, ...$params);
            }
            if ($stmtCnt->execute()) {
                $rCnt = $stmtCnt->get_result();
                if ($rCnt && ($rwC = $rCnt->fetch_assoc())) {
                    $totalPending = intval($rwC['total_pending'] ?? 0);
                    $overdueCnt = intval($rwC['overdue_cnt'] ?? 0);
                    $dueSoonCnt = intval($rwC['due_soon_cnt'] ?? 0);
                    $todayCnt = intval($rwC['today_cnt'] ?? 0);
                }
            }
            $stmtCnt->close();
        }

        // 2. Lista priorizada (vencidas primero, luego más próximas)
        $sqlList = "
            SELECT t.*,
                   COALESCE(up.nombre, u.usuario, 'Asesor') AS agente_nombre,
                   COALESCE(cl.nombre, cl.numero_whatsapp, '') AS cliente_nombre,
                   IFNULL(cl.numero_whatsapp, '') AS numero_whatsapp,
                   IFNULL(s.nombre_sede, '') AS nombre_sede,
                   TIMESTAMPDIFF(MINUTE, NOW(), t.fecha_vencimiento) AS min_para_vencer
            FROM tareas_agenda_comercial t
            LEFT JOIN usuario u ON t.id_agente_asignado = u.id
            LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
            LEFT JOIN clientes_contactos cl ON t.id_cliente = cl.id
            LEFT JOIN sedes s ON t.id_sede = s.id
            WHERE $where
            ORDER BY
                (t.id_agente_asignado = ?) DESC,
                t.fecha_vencimiento ASC,
                t.id DESC
            LIMIT 25
        ";
        $listTypes = $types . "i";
        $listParams = array_merge($params, [$scope['agente_id']]);

        $tasks = [];
        $newlyTriggered = [];
        $idsToMark = [];

        $stmtList = $con->prepare($sqlList);
        if ($stmtList) {
            $stmtList->bind_param($listTypes, ...$listParams);
            if ($stmtList->execute()) {
                $rList = $stmtList->get_result();
                while ($rList && ($row = $rList->fetch_assoc())) {
                    $item = self::enrichTaskRow($row);
                    $tasks[] = $item;
                    $minRem = intval($row['min_para_vencer'] ?? 999999);
                    $recBefore = intval($row['recordatorio_min_antes'] ?? 15);
                    $alreadyAlerted = intval($row['alerta_disparada'] ?? 0) === 1;
                    $isMineOrManager = (
                        intval($row['id_agente_asignado'] ?? 0) === $scope['agente_id']
                        || $scope['is_master']
                        || ($scope['is_admin'] && $canViewCommercialTeam)
                    );

                    if (!$alreadyAlerted && $minRem <= $recBefore && $isMineOrManager) {
                        $newlyTriggered[] = $item;
                        if (intval($row['id_agente_asignado'] ?? 0) === $scope['agente_id'] || $scope['is_operador']) {
                            $idsToMark[] = intval($row['id']);
                        }
                    }
                }
            }
            $stmtList->close();
        }

        if ($markDispatched && !empty($idsToMark)) {
            $stmtMark = $con->prepare("UPDATE tareas_agenda_comercial SET alerta_disparada = 1 WHERE id = ?");
            if ($stmtMark) {
                $tidParam = 0;
                $stmtMark->bind_param("i", $tidParam);
                foreach ($idsToMark as $tidVal) {
                    $tidParam = intval($tidVal);
                    $stmtMark->execute();
                }
                $stmtMark->close();
            }
        }

        $badgeCount = $overdueCnt + $dueSoonCnt;

        return [
            'ok'                  => true,
            'total_pending'       => $totalPending,
            'overdue_count'       => $overdueCnt,
            'due_soon_count'      => $dueSoonCnt,
            'today_pending_count' => $todayCnt,
            'badge_count'         => $badgeCount,
            'tasks'               => $tasks,
            'newly_triggered'     => $newlyTriggered,
            'server_now'          => date('Y-m-d H:i:s'),
        ];
    }

    private static function enrichTaskRow(array $row): array
    {
        $minRem = intval($row['min_para_vencer'] ?? 0);
        $recMin = intval($row['recordatorio_min_antes'] ?? 15);
        $estado = strtoupper(trim((string)($row['estado'] ?? 'PENDIENTE')));

        $urgencia = 'EN_TIEMPO';
        if ($estado === 'COMPLETADA') {
            $urgencia = 'COMPLETADA';
        } elseif ($minRem <= 0) {
            $urgencia = 'VENCIDA';
        } elseif ($minRem <= max(1, $recMin)) {
            $urgencia = 'PROXIMA';
        }

        return [
            'id'                     => intval($row['id']),
            'id_sede'                => intval($row['id_sede'] ?? 0),
            'nombre_sede'            => (string)($row['nombre_sede'] ?? ''),
            'id_cliente'             => intval($row['id_cliente'] ?? 0),
            'cliente_nombre'         => (string)($row['cliente_nombre'] ?? ''),
            'numero_whatsapp'        => (string)($row['numero_whatsapp'] ?? ''),
            'id_conversacion'        => !empty($row['id_conversacion']) ? intval($row['id_conversacion']) : null,
            'id_agente_asignado'     => intval($row['id_agente_asignado'] ?? 0),
            'agente_nombre'          => (string)($row['agente_nombre'] ?? 'Asesor'),
            'titulo'                 => (string)($row['titulo'] ?? ''),
            'descripcion'            => (string)($row['descripcion'] ?? ''),
            'tipo'                   => strtoupper((string)($row['tipo'] ?? 'SEGUIMIENTO')),
            'prioridad'              => strtoupper((string)($row['prioridad'] ?? 'MEDIA')),
            'fecha_vencimiento'      => (string)($row['fecha_vencimiento'] ?? ''),
            'fecha_vencimiento_fmt'  => !empty($row['fecha_vencimiento']) ? date('d/m/Y h:i A', strtotime((string)$row['fecha_vencimiento'])) : '',
            'recordatorio_min_antes' => $recMin,
            'estado'                 => $estado,
            'min_para_vencer'        => $minRem,
            'urgencia_tiempo'        => $urgencia,
        ];
    }
}
