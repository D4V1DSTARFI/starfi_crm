<?php
/**
 * Módulo de Gestión de Roles y Permisos - STARFI CRM
 * Permite configurar de forma gráfica y dinámica los accesos a los distintos módulos del sistema
 * para cada rol predefinido (ADMINISTRADOR, OPERADOR).
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
    <!-- CSS Local de Bootstrap -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- Iconos de Bootstrap (Local) -->
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Tema Global STARFI & Styles -->
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
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
        /* Custom iOS-style Switch */
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
            background-color: #10B981; /* green */
        }
        input:checked + .slider-ios:before {
            transform: translateX(24px);
        }
    </style>
</head>
<body>

    <!-- Encabezado de la app -->
    <?php renderHeader('Roles y Permisos'); ?>

    <div class="app-container">
        <!-- Main Content -->
        <main class="main-content w-100">
            <div class="config-container container-fluid">

                <!-- Header de Sección -->
                <div class="mb-4">
                    <h2 class="brand-font mb-1" style="font-weight: 700; color: var(--starfi-dark);">Roles y Permisos</h2>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">Configure la visibilidad y accesibilidad a los distintos módulos del CRM según el Rol de Usuario.</p>
                </div>

                <div class="roles-card">
                    <!-- Selector de Roles (Pestañas) -->
                    <div class="d-flex border-bottom mb-4" id="roleTabs">
                        <!-- Cargados dinámicamente -->
                    </div>

                    <!-- Matriz de Módulos y Permisos -->
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

    <!-- Bootstrap Bundle JS -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>

    <script>
        let allRolesData = [];
        let activeRoleId = null;

        const modulosMeta = {
            'bandeja': { icon: 'fa-inbox', color: '#0EA5E9', bg: 'rgba(14, 165, 233, 0.1)', desc: 'Bandeja de chats multicanal de WhatsApp y eventos en tiempo real.' },
            'perfil_empresa': { icon: 'fa-building', color: '#EA580C', bg: 'rgba(234, 88, 12, 0.1)', desc: 'Gestión corporativa, firmantes, registro mercantil e información digital de la empresa.' },
            'directorio': { icon: 'fa-address-book', color: '#10B981', bg: 'rgba(16, 185, 129, 0.1)', desc: 'Directorio unificado de contactos y clientes del CRM.' },
            'gestion_usuarios': { icon: 'fa-users', color: '#4F46E5', bg: 'rgba(79, 70, 229, 0.1)', desc: 'Creación, activación y asignación de empresas y roles a los operadores.' },
            'gestion_roles': { icon: 'fa-user-shield', color: '#F59E0B', bg: 'rgba(245, 158, 11, 0.1)', desc: 'Matriz de permisos por módulo y niveles de acceso a operadores.' },
            'dashboard': { icon: 'fa-chart-line', color: '#8B5CF6', bg: 'rgba(139, 92, 246, 0.1)', desc: 'Visualización de reportes, KPIs, volumen de chats y tiempos de primera respuesta.' },
            'gestor_bots': { icon: 'fa-robot', color: '#6366F1', bg: 'rgba(99, 102, 241, 0.1)', desc: 'Configuración y flujos de automatización para los chatbots de soporte.' },
            'configuracion': { icon: 'fa-gear', color: '#64748B', bg: 'rgba(100, 116, 139, 0.1)', desc: 'Ajustes globales del sistema, líneas de WhatsApp, sedes y diagnósticos.' }
        };

        document.addEventListener('DOMContentLoaded', () => {
            loadRolesAndPermissions();
        });

        function loadRolesAndPermissions() {
            fetch('back_roles.php?action=list')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        allRolesData = data.roles;
                        if (allRolesData.length > 0) {
                            if (!activeRoleId) activeRoleId = allRolesData[0].id;
                            renderTabs();
                            renderModulesPermissions();
                        }
                    } else {
                        console.error('Error al cargar roles:', data.message);
                    }
                })
                .catch(err => console.error('Fetch error:', err));
        }

        function renderTabs() {
            const tabsContainer = document.getElementById('roleTabs');
            let html = '';
            allRolesData.forEach(r => {
                const isActive = (r.id === activeRoleId);
                html += `<button class="role-tab-btn${isActive ? ' active' : ''}" onclick="selectRole(${r.id})">
                    <i class="fa-solid fa-user-shield me-2"></i>${escapeHtml(r.nombre)}
                </button>`;
            });
            tabsContainer.innerHTML = html;
        }

        function selectRole(roleId) {
            activeRoleId = roleId;
            renderTabs();
            renderModulesPermissions();
        }

        function renderModulesPermissions() {
            const listContainer = document.getElementById('modulesList');
            const activeRole = allRolesData.find(r => r.id === activeRoleId);
            
            if (!activeRole) {
                listContainer.innerHTML = '<div class="text-center py-5 text-danger">Error al cargar datos del rol seleccionado.</div>';
                return;
            }

            let html = '';
            activeRole.permisos.forEach(p => {
                const meta = modulosMeta[p.modulo] || { icon: 'fa-folder', color: '#64748B', bg: 'rgba(100, 116, 139, 0.1)', desc: 'Acceso a funciones y herramientas generales.' };
                const isChecked = (p.permitido === 1);
                
                html += `
                    <div class="module-row">
                        <div class="module-info">
                            <div class="module-icon-box" style="background-color: ${meta.bg}; color: ${meta.color};">
                                <i class="fa-solid ${meta.icon}"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-1 text-dark">${escapeHtml(p.nombre)}</h6>
                                <p class="text-muted mb-0 small" style="max-width: 580px;">${escapeHtml(meta.desc)}</p>
                            </div>
                        </div>
                        <div>
                            <label class="form-switch-ios">
                                <input type="checkbox" ${isChecked ? 'checked' : ''} onchange="togglePermission(${activeRoleId}, '${p.modulo}')">
                                <span class="slider-ios"></span>
                            </label>
                        </div>
                    </div>`;
            });
            
            listContainer.innerHTML = html;
        }

        function togglePermission(roleId, modulo) {
            const formData = new FormData();
            formData.append('action', 'toggle_permission');
            formData.append('role_id', roleId);
            formData.append('modulo', modulo);

            fetch('back_roles.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Actualizar estado localmente
                    const role = allRolesData.find(r => r.id === roleId);
                    if (role) {
                        const perm = role.permisos.find(p => p.modulo === modulo);
                        if (perm) perm.permitido = data.nuevo_estado;
                    }
                } else {
                    alert(data.message || 'Error al actualizar permisos.');
                    renderModulesPermissions(); // restaurar vista
                }
            })
            .catch(err => {
                console.error(err);
                renderModulesPermissions();
            });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>"']/g, function(m) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[m];
            });
        }
    </script>
</body>
</html>
