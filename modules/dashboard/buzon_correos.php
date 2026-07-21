<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('buzon_correos');
$agente = getAgenteInfo();

$con = getDbConnection();

// Pagination or simple list
$q = "SELECT * FROM cola_correos ORDER BY created_at DESC LIMIT 100";
$res = $con->query($q);
$correos = [];
if ($res) {
    while($row = $res->fetch_assoc()) {
        $correos[] = $row;
    }
}

// Stats
$stats_q = "SELECT estado, COUNT(*) as qty FROM cola_correos GROUP BY estado";
$res_stats = $con->query($stats_q);
$stats = ['Pendiente' => 0, 'Enviado' => 0, 'Error' => 0];
if ($res_stats) {
    while($row = $res_stats->fetch_assoc()) {
        $stats[$row['estado']] = $row['qty'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buzón de Correos | STARFI CRM</title>
    <!-- Bootstrap CSS -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                            <h2><?= $stats['Enviado'] ?? 0 ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-danger text-white">
                        <div class="card-body">
                            <h5 class="card-title">Errores</h5>
                            <h2><?= $stats['Error'] ?? 0 ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-warning text-dark">
                        <div class="card-body">
                            <h5 class="card-title">Pendientes en Cola</h5>
                            <h2><?= $stats['Pendiente'] ?? 0 ?></h2>
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
                                <?php foreach($correos as $c): ?>
                                <tr>
                                    <td>#<?= $c['id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($c['destinatario_nombre']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($c['destinatario_email']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($c['asunto']) ?></td>
                                    <td>
                                        <?php if($c['estado'] === 'Enviado'): ?>
                                            <span class="badge bg-success">Enviado</span>
                                        <?php elseif($c['estado'] === 'Error'): ?>
                                            <span class="badge bg-danger">Error</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= $c['created_at'] ?></small></td>
                                    <td><small><?= $c['sent_at'] ?? '---' ?></small></td>
                                    <td>
                                        <?php if($c['error_mensaje']): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick='verError(<?= json_encode($c['error_mensaje']) ?>)'>
                                            Ver Error (<?= $c['intentos'] ?>)
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

    <!-- Bootstrap & SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
