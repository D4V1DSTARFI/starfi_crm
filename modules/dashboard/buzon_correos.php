<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('buzon_correos');
$agente = getAgenteInfo();

$con = getDbConnection();

$correos = [];
$limit = 100;
$stmt = $con ? $con->prepare("SELECT * FROM cola_correos ORDER BY created_at DESC LIMIT ?") : false;
if ($stmt) {
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $correos[] = $row;
    }
    $stmt->close();
}

$stats = ['Pendiente' => 0, 'Enviado' => 0, 'Error' => 0];
$stmtStats = $con ? $con->prepare("SELECT estado, COUNT(*) AS qty FROM cola_correos GROUP BY estado") : false;
if ($stmtStats) {
    $stmtStats->execute();
    $res_stats = $stmtStats->get_result();
    while ($res_stats && ($row = $res_stats->fetch_assoc())) {
        $stats[$row['estado']] = intval($row['qty']);
    }
    $stmtStats->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buzón de Correos | STARFI CRM</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <!-- Bootstrap CSS Local -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/vendor/fontawesome/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; margin: 0; padding: 0; overflow-x: hidden; }
        .app-container { min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; }
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; padding: 20px; }
    </style>
</head>
<body>
    <?php renderHeader("Buzón de Correos del Sistema"); ?>
    
    <div class="app-container">
    <main class="main-content">
        <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-0 text-dark"><i class="fas fa-envelope text-primary me-2"></i>Buzón de Salida y Logs</h2>
                    <p class="text-muted mb-0">Historial de correos enviados a través de SMTP</p>
                </div>
                <div>
                    <a href="../../index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Volver al Inicio</a>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h5 class="card-title">Enviados</h5>
                            <h2><?= intval($stats['Enviado'] ?? 0) ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-danger text-white">
                        <div class="card-body">
                            <h5 class="card-title">Errores</h5>
                            <h2><?= intval($stats['Error'] ?? 0) ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-warning text-dark">
                        <div class="card-body">
                            <h5 class="card-title">Pendientes en Cola</h5>
                            <h2><?= intval($stats['Pendiente'] ?? 0) ?></h2>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Destinatario</th>
                                    <th>Asunto</th>
                                    <th>Estado</th>
                                    <th>Fecha Registro</th>
                                    <th>Fecha Envío</th>
                                    <th>Errores / Intentos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($correos) === 0): ?>
                                <tr><td colspan="7" class="text-center py-4">No hay correos registrados en el sistema.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($correos as $c): ?>
                                <tr>
                                    <td>#<?= intval($c['id']) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string)($c['destinatario_nombre'] ?? '')) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars((string)($c['destinatario_email'] ?? '')) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars((string)($c['asunto'] ?? '')) ?></td>
                                    <td>
                                        <?php if (($c['estado'] ?? '') === 'Enviado'): ?>
                                            <span class="badge bg-success">Enviado</span>
                                        <?php elseif (($c['estado'] ?? '') === 'Error'): ?>
                                            <span class="badge bg-danger">Error</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= htmlspecialchars((string)($c['created_at'] ?? '')) ?></small></td>
                                    <td><small><?= htmlspecialchars((string)($c['sent_at'] ?? '---')) ?></small></td>
                                    <td>
                                        <?php if (!empty($c['error_mensaje'])): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick='verError(<?= json_encode((string)$c['error_mensaje'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                            Ver Error (<?= intval($c['intentos'] ?? 0) ?>)
                                        </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
    </div>

    <!-- Bootstrap & SweetAlert2 (Local) -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script>
    function verError(msg) {
        Swal.fire({
            title: 'Detalle del Error SMTP',
            text: msg,
            icon: 'error'
        });
    }
    </script>
</body>
</html>
