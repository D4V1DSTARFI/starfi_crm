// modules/gestion_roles/funciones_gestion_roles.js

let allRolesData = [];
let activeRoleId = null;

// Guards anti-doble-clic (QA-BUG-CRM3-07)
let isCreatingRole = false;
let isDeletingRole = false;
const pendingPermissionToggles = new Set();

const modulosMeta = {
    'bandeja': { icon: 'fa-comments', color: '#0EA5E9', bg: 'rgba(14, 165, 233, 0.1)', desc: 'Centro de Mensajes: Bandeja de entrada unificada para chats de WhatsApp.' },
    'pipeline': { icon: 'fa-diagram-project', color: '#0284C7', bg: 'rgba(2, 132, 199, 0.1)', desc: 'Embudo de Ventas (Kanban): Seguimiento visual de oportunidades comerciales.' },
    'campanas': { icon: 'fa-bullhorn', color: '#D97706', bg: 'rgba(217, 119, 6, 0.1)', desc: 'Campañas de Difusión: Envíos masivos con plantillas aprobadas.' },
    'perfil_empresa': { icon: 'fa-building', color: '#EA580C', bg: 'rgba(234, 88, 12, 0.1)', desc: 'Gestión corporativa, firmantes, registro mercantil e información digital de la empresa.' },
    'directorio': { icon: 'fa-address-book', color: '#10B981', bg: 'rgba(16, 185, 129, 0.1)', desc: 'Directorio unificado de contactos y clientes del CRM.' },
    'gestion_usuarios': { icon: 'fa-users', color: '#4F46E5', bg: 'rgba(79, 70, 229, 0.1)', desc: 'Creación, activación y asignación de empresas y roles a los operadores.' },
    'gestion_roles': { icon: 'fa-user-shield', color: '#F59E0B', bg: 'rgba(245, 158, 11, 0.1)', desc: 'Matriz de permisos por módulo y niveles de acceso a operadores.' },
    'dashboard': { icon: 'fa-chart-line', color: '#8B5CF6', bg: 'rgba(139, 92, 246, 0.1)', desc: 'Visualización de reportes, KPIs, volumen de chats y tiempos de primera respuesta.' },
    'gestor_bots': { icon: 'fa-robot', color: '#6366F1', bg: 'rgba(99, 102, 241, 0.1)', desc: 'Configuración y flujos de automatización para los chatbots de soporte.' },
    'waba_ordenes': { icon: 'fa-receipt', color: '#DC3545', bg: 'rgba(220, 53, 69, 0.1)', desc: 'Panel de Órdenes WABA: Estados de cuenta, facturas y cobros.' },
    'buzon_correos': { icon: 'fa-envelope-open-text', color: '#0D6EFD', bg: 'rgba(13, 110, 253, 0.1)', desc: 'Buzón de Salida SMTP: Historial de notificaciones y logs de envíos por correo (Exclusivo MASTER).' },
    'whatsapp_analytics': { icon: 'fa-file-invoice-dollar', color: '#10B981', bg: 'rgba(16, 185, 129, 0.1)', desc: 'Facturación WhatsApp: Costo estimado WABA y analíticas financieras.' },
    'configuracion': { icon: 'fa-gear', color: '#64748B', bg: 'rgba(100, 116, 139, 0.1)', desc: 'Ajustes globales del sistema, líneas de WhatsApp, sedes y diagnósticos.' }
};

document.addEventListener('DOMContentLoaded', () => {
    loadRolesAndPermissions();
    const form = document.getElementById('createRoleForm');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            submitRoleModal(document.getElementById('btnSubmitRoleModal'));
        });
    }
});

function loadRolesAndPermissions() {
    fetch('back_gestion_roles.php?action=list')
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                allRolesData = data.roles || [];
                if (allRolesData.length > 0) {
                    if (!activeRoleId || !allRolesData.some(r => r.id === activeRoleId)) {
                        activeRoleId = allRolesData[0].id;
                    }
                    renderTabs();
                    renderModulesPermissions();
                }
            } else {
                Swal.fire('Error', (data && data.message) || 'No se pudieron cargar los roles.', 'error');
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Fallo de conexión al cargar la matriz de permisos.', 'error');
        });
}

function renderTabs() {
    const tabsContainer = document.getElementById('roleTabs');
    if (!tabsContainer) return;
    let html = '';
    allRolesData.forEach(r => {
        const rid = Number(r.id) || 0;
        const isActive = (rid === activeRoleId);
        html += `<button class="role-tab-btn${isActive ? ' active' : ''}" onclick="selectRole(${rid})">
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
    if (!listContainer) return;
    const activeRole = allRolesData.find(r => r.id === activeRoleId);

    if (!activeRole) {
        listContainer.innerHTML = '<div class="text-center py-5 text-danger">Error al cargar datos del rol seleccionado.</div>';
        return;
    }

    const isRoleMaster = (Number(activeRole.id) === 1 || String(activeRole.nombre || '').trim().toUpperCase() === 'MASTER');

    let html = '';
    (activeRole.permisos || []).forEach(p => {
        const meta = modulosMeta[p.modulo] || { icon: 'fa-folder', color: '#64748B', bg: 'rgba(100, 116, 139, 0.1)', desc: 'Acceso a funciones y herramientas generales.' };
        const isBuzonLocked = (!isRoleMaster && p.modulo === 'buzon_correos');
        const isChecked = isBuzonLocked ? false : (Number(p.permitido) === 1);
        const safeMod = escapeHtml(p.modulo);

        html += `
            <div class="module-row">
                <div class="module-info">
                    <div class="module-icon-box" style="background-color: ${escapeHtml(meta.bg)}; color: ${escapeHtml(meta.color)};">
                        <i class="fa-solid ${escapeHtml(meta.icon)}"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1 text-dark">${escapeHtml(p.nombre)}${isBuzonLocked ? ' <span class="badge bg-secondary ms-1" style="font-size:0.68rem;">Solo MASTER</span>' : ''}</h6>
                        <p class="text-muted mb-0 small" style="max-width: 580px;">${escapeHtml(meta.desc)}</p>
                    </div>
                </div>
                <div>
                    <label class="form-switch-ios">
                        <input type="checkbox" data-role-id="${Number(activeRoleId)}" data-modulo="${safeMod}" ${isChecked ? 'checked' : ''} ${isBuzonLocked ? 'disabled' : ''} onchange="togglePermission(${Number(activeRoleId)}, this.getAttribute('data-modulo'), this)">
                        <span class="slider-ios"></span>
                    </label>
                </div>
            </div>`;
    });

    listContainer.innerHTML = html;
}

function togglePermission(roleId, modulo, inputEl) {
    const lockKey = `${roleId}_${modulo}`;
    if (pendingPermissionToggles.has(lockKey)) return;
    pendingPermissionToggles.add(lockKey);
    if (inputEl) inputEl.disabled = true;

    const formData = new FormData();
    formData.append('action', 'toggle_permission');
    formData.append('role_id', roleId);
    formData.append('modulo', modulo);

    fetch('back_gestion_roles.php', {
        method: 'POST',
        body: formData
    })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                const role = allRolesData.find(r => r.id === roleId);
                if (role && Array.isArray(role.permisos)) {
                    const perm = role.permisos.find(p => p.modulo === modulo);
                    if (perm) perm.permitido = data.nuevo_estado;
                }
            } else {
                Swal.fire('Operación no permitida', (data && data.message) || 'Error al actualizar permisos.', 'error');
                renderModulesPermissions();
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Fallo de conexión con el servidor.', 'error');
            renderModulesPermissions();
        })
        .finally(() => {
            pendingPermissionToggles.delete(lockKey);
            if (inputEl) inputEl.disabled = false;
        });
}

function createNewRole(btnEl) {
    if (isCreatingRole) return;
    const triggerBtn = btnEl || document.getElementById('btnCreateNewRole');

    Swal.fire({
        title: 'Crear Nuevo Rol',
        input: 'text',
        inputLabel: 'Nombre del Rol',
        inputPlaceholder: 'Ej: JEFE DE VENTAS, SUPERVISOR...',
        showCancelButton: true,
        confirmButtonText: 'Crear Rol',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#E85B14',
        inputValidator: (value) => {
            if (!value || !value.trim()) {
                return 'Por favor ingresa un nombre para el rol';
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            sendCreateRole(result.value.trim().toUpperCase(), triggerBtn);
        }
    });
}

function submitRoleModal(btnEl) {
    if (isCreatingRole) return;
    const input = document.getElementById('inputRoleNombre');
    if (!input || !input.value.trim()) {
        Swal.fire('Atención', 'Por favor ingresa un nombre para el rol.', 'warning');
        return;
    }
    const submitBtn = btnEl || document.getElementById('btnSubmitRoleModal');
    if (submitBtn) submitBtn.disabled = true;

    const modalEl = document.getElementById('modalRoleInfo');
    const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
    if (modal) modal.hide();
    sendCreateRole(input.value.trim().toUpperCase(), submitBtn);
}

function sendCreateRole(nombreUpper, btnEl) {
    if (isCreatingRole) return;
    isCreatingRole = true;

    const modalBtn = document.getElementById('btnSubmitRoleModal');
    const headerBtn = document.getElementById('btnCreateNewRole');
    if (btnEl) btnEl.disabled = true;
    if (modalBtn) modalBtn.disabled = true;
    if (headerBtn) headerBtn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'create_role');
    formData.append('nombre', nombreUpper);

    fetch('back_gestion_roles.php', {
        method: 'POST',
        body: formData
    })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Rol Creado',
                    text: 'El nuevo rol ha sido registrado correctamente.',
                    timer: 2000,
                    showConfirmButton: false
                });
                activeRoleId = data.id;
                loadRolesAndPermissions();
            } else {
                Swal.fire('Error', (data && data.message) || 'No se pudo crear el rol', 'error');
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Fallo de conexión con el servidor', 'error');
        })
        .finally(() => {
            isCreatingRole = false;
            if (btnEl) btnEl.disabled = false;
            if (modalBtn) modalBtn.disabled = false;
            if (headerBtn) headerBtn.disabled = false;
        });
}

function deleteRole(roleId, btnEl) {
    if (isDeletingRole) return;
    Swal.fire({
        title: '¿Eliminar Rol?',
        text: 'Esta acción eliminará el rol seleccionado si no tiene usuarios asignados.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Sí, eliminar'
    }).then((result) => {
        if (!result.isConfirmed || isDeletingRole) return;
        isDeletingRole = true;
        if (btnEl) btnEl.disabled = true;

        const formData = new FormData();
        formData.append('action', 'delete_role');
        formData.append('role_id', roleId);

        fetch('back_gestion_roles.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    if (activeRoleId === roleId) activeRoleId = null;
                    loadRolesAndPermissions();
                    Swal.fire('Eliminado', data.message || 'Rol eliminado correctamente.', 'success');
                } else {
                    Swal.fire('Error', (data && data.message) || 'No se pudo eliminar el rol.', 'error');
                }
            })
            .catch(() => {
                Swal.fire('Error', 'Fallo de conexión al eliminar el rol.', 'error');
            })
            .finally(() => {
                isDeletingRole = false;
                if (btnEl) btnEl.disabled = false;
            });
    });
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[m];
    });
}
