<?php
/**
 * Módulo de Gestión de Usuarios - STARFI CRM
 * Permite administrar usuarios del sistema, habilitar/inhabilitar accesos,
 * asignar Roles (relacional de la tabla roles) y vincular Empresa corporativa.
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

    <!-- Encabezado de la app -->
    <?php renderHeader('Gestión de Usuarios'); ?>

    <div class="app-container">
        <!-- Main Content -->
        <main class="main-content w-100">
            <div class="config-container container-fluid">

                <!-- Header de Sección -->
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

                <!-- Tarjetas Resumen -->
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

                <!-- Tabla de Usuarios -->
                <div class="user-card">
                    <!-- Filtros y Búsqueda -->
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
                        <div class="btn-group gap-2" role="group">
                            <button type="button" class="btn btn-outline-secondary filter-btn active" onclick="filterStatus('TODOS', this)">Todos</button>
                            <button type="button" class="btn btn-outline-secondary filter-btn" onclick="filterStatus('ACTIVO', this)">Activos</button>
                            <button type="button" class="btn btn-outline-secondary filter-btn" onclick="filterStatus('INACTIVO', this)">Inactivos</button>
                        </div>
                        <div class="search-bar-modern">
                            <i class="fa-solid fa-search text-muted"></i>
                            <input type="text" id="searchInput" placeholder="Buscar por nombre, usuario..." onkeyup="renderUsersTable()">
                        </div>
                    </div>

                    <!-- Tabla Responsive -->
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

    <!-- Modal Crear / Editar Usuario -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="userModalTitle">Configurar Cuenta de Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <form id="userForm">
                        <input type="hidden" id="userId" name="id" value="0">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Nombre Completo</label>
                            <input type="text" class="form-control py-2" id="inputNombre" name="nombre" placeholder="Ej: Juan Pérez" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Nombre de Usuario</label>
                                <input type="text" class="form-control py-2" id="inputUsuario" name="usuario" placeholder="Ej: jperez" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Cédula / Documento</label>
                                <input type="text" class="form-control py-2" id="inputCedula" name="cedula" placeholder="Ej: 12345678">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Correo Electrónico</label>
                                <input type="email" class="form-control py-2" id="inputCorreo" name="correo" placeholder="correo@ejemplo.com" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Teléfono</label>
                                <input type="text" class="form-control py-2" id="inputTelefono" name="telefono" placeholder="+58 412 0000000">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Contraseña <span id="passHint" class="text-muted fw-normal">(Requerida para nuevos)</span></label>
                            <input type="password" class="form-control py-2" id="inputContrasena" name="contrasena" placeholder="******">
                        </div>

                        <hr class="my-3 text-muted">
                        <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-user-shield text-primary me-2"></i>Asignación de Rol y Sede</h6>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Rol de Usuario</label>
                                <select class="form-select py-2" id="inputRol" name="role_id_select">
                                    <option value="0">Sin Rol (Pendiente)</option>
                                    <!-- Opciones cargadas dinámicamente -->
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Sede Asignada</label>
                                <select class="form-select py-2" id="inputSede" name="id_sede">
                                    <option value="0">Sin Sede (Sin Asignar)</option>
                                    <!-- Opciones cargadas dinámicamente -->
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Estatus del Usuario</label>
                            <select class="form-select py-2" id="inputEstado" name="estado">
                                <option value="INACTIVO">Inactivo (Acceso bloqueado)</option>
                                <option value="ACTIVO">Activo (Permitir inicio de sesión)</option>
                            </select>
                        </div>
                        <div id="modalAlert" class="alert alert-danger d-none py-2 small mb-0"></div>
                    </form>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" id="btnGuardarUser" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="saveUser()">Guardar Configuración</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle JS -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>

    <script>
        let allUsers = [];
        let allSedes = [];
        let allRoles = [];
        let currentFilter = 'TODOS';

        document.addEventListener('DOMContentLoaded', () => {
            loadUsers();
        });

        function loadUsers() {
            fetch('back_usuarios.php?action=list')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        allUsers = data.data;
                        allSedes = data.sedes || [];
                        allRoles = data.roles || [];
                        populateSedesSelect();
                        populateRolesSelect();
                        updateStats();
                        renderUsersTable();
                    } else {
                        console.error('Error:', data.message);
                    }
                })
                .catch(err => console.error('Fetch error:', err));
        }

        function populateSedesSelect() {
            const sel = document.getElementById('inputSede');
            sel.innerHTML = '<option value="0">Sin Sede (Sin Asignar)</option>';
            allSedes.forEach(s => {
                sel.innerHTML += `<option value="${s.id}">${escapeHtml(s.nombre_sede)}</option>`;
            });
        }

        function populateRolesSelect() {
            const sel = document.getElementById('inputRol');
            sel.innerHTML = '<option value="0">Sin Rol (Pendiente)</option>';
            allRoles.forEach(r => {
                sel.innerHTML += `<option value="${r.id}">${escapeHtml(r.nombre)}</option>`;
            });
        }

        function updateStats() {
            document.getElementById('stat-total').innerText = allUsers.length;
            const activos = allUsers.filter(u => u.estado === 'ACTIVO').length;
            const inactivos = allUsers.filter(u => u.estado === 'INACTIVO').length;
            document.getElementById('stat-activos').innerText = activos;
            document.getElementById('stat-inactivos').innerText = inactivos;
        }

        function filterStatus(status, btn) {
            currentFilter = status;
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderUsersTable();
        }

        function renderUsersTable() {
            const tbody = document.getElementById('usersTableBody');
            const search = document.getElementById('searchInput').value.toLowerCase().trim();

            let filtered = allUsers.filter(u => {
                const matchStatus = (currentFilter === 'TODOS') || (u.estado === currentFilter);
                const matchSearch = ((u.nombre || '').toLowerCase().includes(search) || (u.usuario || '').toLowerCase().includes(search) || (u.correo || '').toLowerCase().includes(search));
                return matchStatus && matchSearch;
            });

            if (filtered.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-user-slash fs-1 d-block mb-2 text-secondary opacity-50"></i>
                            No se encontraron usuarios con el criterio especificado.
                        </td>
                    </tr>`;
                return;
            }

            let html = '';
            filtered.forEach(u => {
                const isActivo = (u.estado === 'ACTIVO');
                const badgeClass = isActivo ? 'status-badge-activo' : 'status-badge-inactivo';
                const statusText = isActivo ? 'Activo' : 'Inactivo';
                const initial = u.nombre ? u.nombre.charAt(0).toUpperCase() : 'U';

                let rolBadge = '<span class="badge-rol-sin">Sin Rol</span>';
                if (u.rol === 'MASTER') rolBadge = '<span class="badge-rol-master"><i class="fa-solid fa-crown me-1"></i>MASTER</span>';
                else if (u.rol === 'MASTER CI') rolBadge = '<span class="badge-rol-master" style="background-color: rgba(234, 88, 12, 0.15); color: #EA580C;"><i class="fa-solid fa-user-shield me-1"></i>MASTER CI</span>';
                else if (u.rol === 'ADMINISTRADOR') rolBadge = '<span class="badge-rol-admin"><i class="fa-solid fa-user-gear me-1"></i>ADMINISTRADOR</span>';
                else if (u.rol === 'OPERADOR') rolBadge = '<span class="badge-rol-operador"><i class="fa-solid fa-headset me-1"></i>OPERADOR</span>';
                else if (u.rol) rolBadge = `<span class="badge-rol-admin" style="background-color: rgba(16, 185, 129, 0.15); color: #10B981;"><i class="fa-solid fa-user-check me-1"></i>${escapeHtml(u.rol)}</span>`;

                const sedeText = u.sede_nombre ? `<span class="fw-semibold text-dark"><i class="fa-solid fa-location-dot me-1 text-secondary"></i>${escapeHtml(u.sede_nombre)}</span>` : '<span class="text-muted small">Sin Sede</span>';

                html += `
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="avatar-initials">${initial}</div>
                                <div>
                                    <div class="fw-bold mb-0 text-dark">${escapeHtml(u.nombre)}</div>
                                    <span class="text-muted small">@${escapeHtml(u.usuario)}</span>
                                </div>
                            </div>
                        </td>
                        <td>${rolBadge}</td>
                        <td>${sedeText}</td>
                        <td>
                            <div class="small fw-medium">${escapeHtml(u.correo)}</div>
                            <span class="text-muted small">${escapeHtml(u.telefono)}</span>
                        </td>
                        <td class="text-center">
                            <button class="btn border-0 p-0 shadow-none" onclick="toggleStatus(${u.id}, '${isActivo ? 'INACTIVO' : 'ACTIVO'}')" title="Haz clic para cambiar estatus a ${isActivo ? 'Inactivo' : 'Activo'}">
                                <span class="${badgeClass}">
                                    <i class="fa-solid ${isActivo ? 'fa-toggle-on' : 'fa-toggle-off'} fs-6"></i>
                                    ${statusText}
                                </span>
                            </button>
                        </td>
                        <td class="text-end">
                            <div class="d-flex align-items-center justify-content-end gap-2">
                                <button class="btn btn-light btn-sm text-secondary px-3 py-1.5 rounded-2 border d-flex align-items-center gap-1.5 fw-semibold" onclick='editUser(${JSON.stringify(u)})' title="Configurar Rol y Empresa">
                                    <i class="fa-solid fa-pen text-muted"></i> Configurar
                                </button>
                            </div>
                        </td>
                    </tr>`;
            });

            tbody.innerHTML = html;
        }

        function toggleStatus(id, newStatus) {
            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('id', id);
            formData.append('estado', newStatus);

            fetch('back_usuarios.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const user = allUsers.find(u => u.id === id);
                    if (user) user.estado = newStatus;
                    updateStats();
                    renderUsersTable();
                } else {
                    alert(data.message || 'Error al cambiar estatus');
                }
            });
        }

        function openUserModal() {
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '0';
            document.getElementById('userModalTitle').innerText = 'Crear Nuevo Usuario';
            document.getElementById('inputContrasena').required = true;
            document.getElementById('passHint').innerText = '(Requerida para nuevos)';
            document.getElementById('inputRol').value = '0';
            document.getElementById('inputSede').value = '0';
            document.getElementById('inputEstado').value = 'INACTIVO';
            document.getElementById('modalAlert').classList.add('d-none');
            
            const modalEl = document.getElementById('userModal');
            let modal = bootstrap.Modal.getInstance(modalEl);
            if (!modal) modal = new bootstrap.Modal(modalEl);
            modal.show();
        }

        function editUser(user) {
            document.getElementById('userId').value = user.id;
            document.getElementById('inputNombre').value = user.nombre || '';
            document.getElementById('inputUsuario').value = user.usuario || '';
            document.getElementById('inputCedula').value = (user.cedula && user.cedula !== '-') ? user.cedula : '';
            document.getElementById('inputCorreo').value = (user.correo && user.correo !== '-') ? user.correo : '';
            document.getElementById('inputTelefono').value = (user.telefono && user.telefono !== '-') ? user.telefono : '';
            document.getElementById('inputContrasena').value = '';
            document.getElementById('inputContrasena').required = false;
            document.getElementById('passHint').innerText = '(Dejar en blanco para mantener actual)';
            document.getElementById('inputRol').value = user.id_rol ? user.id_rol : '0';
            document.getElementById('inputSede').value = user.id_sede ? user.id_sede : '0';
            document.getElementById('inputEstado').value = user.estado || 'INACTIVO';
            document.getElementById('userModalTitle').innerText = 'Configurar Usuario, Rol y Sede';
            document.getElementById('modalAlert').classList.add('d-none');

            const modalEl = document.getElementById('userModal');
            let modal = bootstrap.Modal.getInstance(modalEl);
            if (!modal) modal = new bootstrap.Modal(modalEl);
            modal.show();
        }

        function saveUser() {
            const form = document.getElementById('userForm');
            const alertEl = document.getElementById('modalAlert');
            const btn = document.getElementById('btnGuardarUser');

            if (!form.checkValidity()) {
                const modalBody = document.querySelector('#userModal .modal-body');
                if (modalBody) modalBody.scrollTop = 0;
                
                const invalidField = form.querySelector(':invalid');
                if (invalidField) invalidField.focus();

                alertEl.innerText = 'Por favor completa los campos obligatorios requeridos (Nombre, Usuario y un Correo electrónico válido).';
                alertEl.classList.remove('d-none');
                return;
            }

            const originalHtml = btn ? btn.innerHTML : 'Guardar Configuración';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...';
            }
            alertEl.classList.add('d-none');

            const formData = new FormData(form);
            formData.append('action', 'save');
            const rolVal = document.getElementById('inputRol').value;
            formData.append('rol', rolVal);

            fetch('back_usuarios.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(text => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Server non-JSON response:', text);
                    alertEl.innerText = 'Respuesta inesperada del servidor. Por favor intenta de nuevo.';
                    alertEl.classList.remove('d-none');
                    return;
                }

                if (data.success) {
                    const modalEl = document.getElementById('userModal');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                    loadUsers();
                } else {
                    alertEl.innerText = data.message || 'Error al guardar';
                    alertEl.classList.remove('d-none');
                }
            })
            .catch(err => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
                console.error(err);
                alertEl.innerText = 'Error al comunicarse con el servidor. Por favor intenta de nuevo.';
                alertEl.classList.remove('d-none');
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
