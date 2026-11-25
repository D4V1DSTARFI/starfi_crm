<?php
/**
 * Módulo de Gestión de Usuarios - STARFI CRM
 * Vista principal MVC desacoplada en cápsula de 4 archivos.
 */
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('gestion_usuarios');
$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios | STARFI CRM</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/vendor/fontawesome/css/all.min.css">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/styles.css">

    <style>
        .config-container {
            flex: 1;
            padding: 30px;
            background-color: var(--bg-main);
            overflow-y: auto;
            min-height: calc(100vh - 60px);
        }
        .user-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            padding: 24px;
            margin-bottom: 24px;
        }
        .status-badge-activo {
            background-color: rgba(16, 185, 129, 0.12);
            color: #10B981;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            border: 1px solid rgba(16, 185, 129, 0.3);
            transition: all 0.2s ease;
        }
        .status-badge-activo:hover {
            background-color: #10B981;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.25);
        }
        .status-badge-inactivo {
            background-color: rgba(239, 68, 68, 0.12);
            color: #EF4444;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            border: 1px solid rgba(239, 68, 68, 0.3);
            transition: all 0.2s ease;
        }
        .status-badge-inactivo:hover {
            background-color: #EF4444;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(239, 68, 68, 0.25);
        }
        .badge-rol-master {
            background-color: rgba(234, 88, 12, 0.12);
            color: #EA580C;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.78rem;
        }
        .badge-rol-admin {
            background-color: rgba(79, 70, 229, 0.12);
            color: #4F46E5;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.78rem;
        }
        .badge-rol-operador {
            background-color: rgba(14, 165, 233, 0.12);
            color: #0EA5E9;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.78rem;
        }
        .badge-rol-sin {
            background-color: rgba(148, 163, 184, 0.12);
            color: #64748B;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.78rem;
        }
        .avatar-initials {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #FF8A4D);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.05rem;
            box-shadow: 0 3px 8px rgba(232, 91, 20, 0.2);
        }
        .table > :not(caption) > * > * {
            padding: 1rem 0.85rem;
            vertical-align: middle;
        }
        .filter-btn {
            border-radius: 20px !important;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 6px 18px;
        }
        .filter-btn.active {
            background-color: var(--primary) !important;
            color: #ffffff !important;
            border-color: var(--primary) !important;
            box-shadow: 0 4px 10px rgba(232, 91, 20, 0.25);
        }
        .btn-starfi-primary {
            background-color: var(--primary);
            color: white;
            border-radius: 30px;
            font-weight: 600;
            padding: 10px 22px;
            box-shadow: 0 4px 12px rgba(232, 91, 20, 0.25);
            transition: all 0.2s;
            border: none;
        }
        .btn-starfi-primary:hover {
            background-color: var(--primary-hover);
            color: white;
            transform: translateY(-1px);
        }
        .search-bar-modern {
            display: flex;
            align-items: center;
            background-color: #ffffff;
            border-radius: 30px;
            padding: 8px 20px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
            max-width: 320px;
            width: 100%;
        }
        .search-bar-modern:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.1);
        }
        .search-bar-modern input {
            width: 100%;
            border: none;
            background: transparent;
            padding: 4px 10px;
            font-size: 0.9rem;
            outline: none;
        }
    </style>
</head>
<body>

    <?php renderHeader('Gestión de Usuarios'); ?>

    <div class="app-container" id="usuariosAppRoot" data-agente="<?= htmlspecialchars($nombre_agente, ENT_QUOTES, 'UTF-8') ?>">
        <main class="main-content w-100">
            <div class="config-container container-fluid">

                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
                    <div>
                        <h2 class="brand-font mb-1" style="font-weight: 700; color: var(--starfi-dark);">Gestión de Usuarios</h2>
                        <p class="text-muted mb-0" style="font-size: 0.95rem;">Controla accesos, activa usuarios, asigna roles (Master, Administrador, Operador) y vincula sedes corporativas.</p>
                    </div>
                    <div>
                        <button class="btn btn-starfi-primary d-flex align-items-center gap-2" onclick="openUserModal()">
                            <i class="fa-solid fa-user-plus"></i> Crear Usuario
                        </button>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12 col-sm-4">
                        <div class="user-card d-flex align-items-center gap-3 py-3 mb-0">
                            <div class="rounded-circle p-3 d-flex align-items-center justify-content-center" style="background-color: rgba(59, 130, 246, 0.1); color: #3b82f6; width: 50px; height: 50px;">
                                <i class="fa-solid fa-users fs-4"></i>
                            </div>
                            <div>
                                <span class="text-muted small fw-medium">Total Registrados</span>
                                <h3 class="fw-bold mb-0" id="stat-total">0</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <div class="user-card d-flex align-items-center gap-3 py-3 mb-0">
                            <div class="rounded-circle p-3 d-flex align-items-center justify-content-center" style="background-color: rgba(16, 185, 129, 0.1); color: #10b981; width: 50px; height: 50px;">
                                <i class="fa-solid fa-circle-check fs-4"></i>
                            </div>
                            <div>
                                <span class="text-muted small fw-medium">Usuarios Activos</span>
                                <h3 class="fw-bold mb-0 text-success" id="stat-activos">0</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <div class="user-card d-flex align-items-center gap-3 py-3 mb-0">
                            <div class="rounded-circle p-3 d-flex align-items-center justify-content-center" style="background-color: rgba(239, 68, 68, 0.1); color: #ef4444; width: 50px; height: 50px;">
                                <i class="fa-solid fa-user-slash fs-4"></i>
                            </div>
                            <div>
                                <span class="text-muted small fw-medium">Inactivos / Pendientes</span>
                                <h3 class="fw-bold mb-0 text-danger" id="stat-inactivos">0</h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="user-card">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
                        <div class="btn-group gap-2" role="group">
                            <button type="button" class="btn btn-outline-secondary filter-btn active" onclick="filterStatus('TODOS', this)">Todos</button>
                            <button type="button" class="btn btn-outline-secondary filter-btn" onclick="filterStatus('ACTIVO', this)">Activos</button>
                            <button type="button" class="btn btn-outline-secondary filter-btn" onclick="filterStatus('INACTIVO', this)">Inactivos</button>
                        </div>
                        <div class="search-bar-modern">
                            <i class="fa-solid fa-search text-muted"></i>
                            <input type="text" id="searchInput" autocomplete="off" placeholder="Buscar por nombre, usuario..." onkeyup="renderUsersTable()">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle table-hover mb-0" id="usersTable">
                            <thead class="table-light">
                                <tr style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748B;">
                                    <th>Usuario</th>
                                    <th>Rol Asignado</th>
                                    <th>Sede Asignada</th>
                                    <th>Contacto</th>
                                    <th class="text-center">Estatus</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="usersTableBody">
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                        Cargando usuarios...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <?php
    define('STARFI_MODALS_ALLOWED', true);
    require __DIR__ . '/modals_gestion_usuarios.php';
    ?>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="funciones_gestion_usuarios.js"></script>
</body>
</html>
