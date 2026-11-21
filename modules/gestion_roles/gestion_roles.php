<?php
/**
 * Módulo de Gestión de Roles y Permisos - STARFI CRM
 * Vista principal MVC desacoplada en cápsula de 4 archivos.
 */
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('gestion_roles');

$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles y Permisos | STARFI CRM</title>
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
        .roles-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
            padding: 28px;
            margin-bottom: 24px;
        }
        .role-tab-btn {
            border: none;
            background: transparent;
            padding: 12px 24px;
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-muted);
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }
        .role-tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .module-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 12px;
            border-bottom: 1px solid #F1F5F9;
            transition: all 0.2s ease;
        }
        .module-row:hover {
            background-color: #F8FAFC;
            border-radius: 8px;
        }
        .module-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .module-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .form-switch-ios {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        .form-switch-ios input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider-ios {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #CBD5E1;
            transition: .4s;
            border-radius: 34px;
        }
        .slider-ios:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }
        input:checked + .slider-ios {
            background-color: #10B981;
        }
        input:checked + .slider-ios:before {
            transform: translateX(24px);
        }
    </style>
</head>
<body>

    <?php renderHeader('Roles y Permisos'); ?>

    <div class="app-container" id="rolesAppRoot" data-agente="<?= htmlspecialchars($nombre_agente, ENT_QUOTES, 'UTF-8') ?>">
        <main class="main-content w-100">
            <div class="config-container container-fluid">

                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
                    <div>
                        <h2 class="brand-font mb-1" style="font-weight: 700; color: var(--starfi-dark);">Roles y Permisos</h2>
                        <p class="text-muted mb-0" style="font-size: 0.95rem;">Configure la visibilidad y accesibilidad a los distintos módulos del CRM según el Rol de Usuario.</p>
                    </div>
                    <div>
                        <button class="btn btn-primary d-flex align-items-center gap-2 fw-semibold px-4 py-2" style="border-radius: 30px; background-color: var(--primary); border-color: var(--primary);" onclick="createNewRole()">
                            <i class="fa-solid fa-plus"></i> Crear Nuevo Rol
                        </button>
                    </div>
                </div>

                <div class="roles-card">
                    <div class="d-flex border-bottom mb-4 overflow-auto" id="roleTabs"></div>

                    <div class="mt-2" id="modulesList">
                        <div class="text-center py-5 text-muted">
                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                            Cargando matriz de permisos...
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <?php require __DIR__ . '/modals_gestion_roles.php'; ?>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="funciones_gestion_roles.js"></script>
</body>
</html>
