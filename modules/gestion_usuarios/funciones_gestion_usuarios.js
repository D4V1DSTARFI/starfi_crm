// modules/gestion_usuarios/funciones_gestion_usuarios.js

let allUsers = [];
let allSedes = [];
let allRoles = [];
let currentFilter = 'TODOS';

// Guards anti-doble-clic (QA-BUG-CRM3-07)
let isSavingUser = false;
const pendingStatusToggles = new Set();

document.addEventListener('DOMContentLoaded', () => {
    loadUsers();

    const userForm = document.getElementById('userForm');
    if (userForm) {
        userForm.addEventListener('submit', (e) => {
            e.preventDefault();
            saveUser(document.getElementById('btnGuardarUser'));
        });
    }
});

function loadUsers() {
    fetch('back_gestion_usuarios.php?action=list')
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                allUsers = data.data || [];
                allSedes = data.sedes || [];
                allRoles = data.roles || [];
                populateSedesSelect();
                populateRolesSelect();
                updateStats();
                renderUsersTable();
            } else {
                Swal.fire('Error', (data && data.message) || 'No se pudieron cargar los usuarios.', 'error');
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Fallo de conexión al cargar usuarios.', 'error');
        });
}

function populateSedesSelect() {
    const sel = document.getElementById('inputSede');
    if (!sel) return;
    sel.innerHTML = '<option value="0">Sin Sede (Sin Asignar)</option>';
    allSedes.forEach(s => {
        sel.innerHTML += `<option value="${Number(s.id)}">${escapeHtml(s.nombre_sede)}</option>`;
    });
}

function populateRolesSelect() {
    const sel = document.getElementById('inputRol');
    if (!sel) return;
    sel.innerHTML = '<option value="0">Sin Rol (Pendiente)</option>';
    allRoles.forEach(r => {
        sel.innerHTML += `<option value="${Number(r.id)}">${escapeHtml(r.nombre)}</option>`;
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
    const search = (document.getElementById('searchInput').value || '').toLowerCase().trim();

    const filtered = allUsers.filter(u => {
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
        const uid = Number(u.id) || 0;
        const isActivo = (u.estado === 'ACTIVO');
        const nextStatus = isActivo ? 'INACTIVO' : 'ACTIVO';
        const badgeClass = isActivo ? 'status-badge-activo' : 'status-badge-inactivo';
        const statusText = isActivo ? 'Activo' : 'Inactivo';
        const initial = u.nombre ? escapeHtml(String(u.nombre).charAt(0).toUpperCase()) : 'U';

        let rolBadge = '<span class="badge-rol-sin">Sin Rol</span>';
        if (u.rol === 'MASTER') rolBadge = '<span class="badge-rol-master"><i class="fa-solid fa-crown me-1"></i>MASTER</span>';
        else if (u.rol === 'MASTER CI') rolBadge = '<span class="badge-rol-master" style="background-color: rgba(234, 88, 12, 0.15); color: #EA580C;"><i class="fa-solid fa-user-shield me-1"></i>MASTER CI</span>';
        else if (u.rol === 'ADMINISTRADOR') rolBadge = '<span class="badge-rol-admin"><i class="fa-solid fa-user-gear me-1"></i>ADMINISTRADOR</span>';
        else if (u.rol === 'OPERADOR') rolBadge = '<span class="badge-rol-operador"><i class="fa-solid fa-headset me-1"></i>OPERADOR</span>';
        else if (u.rol && u.rol !== 'SIN_ASIGNAR') rolBadge = `<span class="badge-rol-admin" style="background-color: rgba(16, 185, 129, 0.15); color: #10B981;"><i class="fa-solid fa-user-check me-1"></i>${escapeHtml(u.rol)}</span>`;

        const sedeText = u.sede_nombre
            ? `<span class="fw-semibold text-dark"><i class="fa-solid fa-location-dot me-1 text-secondary"></i>${escapeHtml(u.sede_nombre)}</span>`
            : '<span class="text-muted small">Sin Sede</span>';

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
                    <button type="button" class="btn border-0 p-0 shadow-none" onclick="toggleStatus(${uid}, '${nextStatus}', this)" title="Haz clic para cambiar estatus a ${isActivo ? 'Inactivo' : 'Activo'}">
                        <span class="${badgeClass}">
                            <i class="fa-solid ${isActivo ? 'fa-toggle-on' : 'fa-toggle-off'} fs-6"></i>
                            ${statusText}
                        </span>
                    </button>
                </td>
                <td class="text-end">
                    <div class="d-flex align-items-center justify-content-end gap-2">
                        <button type="button" class="btn btn-light btn-sm text-secondary px-3 py-1.5 rounded-2 border d-flex align-items-center gap-1.5 fw-semibold" onclick="editUserById(${uid})" title="Configurar Rol y Empresa">
                            <i class="fa-solid fa-pen text-muted"></i> Configurar
                        </button>
                    </div>
                </td>
            </tr>`;
    });

    tbody.innerHTML = html;
}

function toggleStatus(id, newStatus, btnEl) {
    const uid = Number(id);
    if (!uid || pendingStatusToggles.has(uid)) return;
    pendingStatusToggles.add(uid);
    if (btnEl) btnEl.disabled = true;

    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', uid);
    formData.append('estado', newStatus);

    fetch('back_gestion_usuarios.php', {
        method: 'POST',
        body: formData
    })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                const user = allUsers.find(u => Number(u.id) === uid);
                if (user) user.estado = newStatus;
                updateStats();
                renderUsersTable();
            } else {
                Swal.fire('Error', (data && data.message) || 'Error al cambiar estatus', 'error');
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Fallo de conexión al cambiar el estatus del usuario.', 'error');
        })
        .finally(() => {
            pendingStatusToggles.delete(uid);
            if (btnEl) btnEl.disabled = false;
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

    const btn = document.getElementById('btnGuardarUser');
    if (btn) btn.disabled = false;

    const modalEl = document.getElementById('userModal');
    let modal = bootstrap.Modal.getInstance(modalEl);
    if (!modal) modal = new bootstrap.Modal(modalEl);
    modal.show();
}

function editUserById(id) {
    const user = allUsers.find(u => Number(u.id) === Number(id));
    if (user) editUser(user);
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

    const btn = document.getElementById('btnGuardarUser');
    if (btn) btn.disabled = false;

    const modalEl = document.getElementById('userModal');
    let modal = bootstrap.Modal.getInstance(modalEl);
    if (!modal) modal = new bootstrap.Modal(modalEl);
    modal.show();
}

function saveUser(btnEl) {
    if (isSavingUser) return;

    const form = document.getElementById('userForm');
    const alertEl = document.getElementById('modalAlert');
    const btn = btnEl || document.getElementById('btnGuardarUser');

    if (!form.checkValidity()) {
        const modalBody = document.querySelector('#userModal .modal-body');
        if (modalBody) modalBody.scrollTop = 0;

        const invalidField = form.querySelector(':invalid');
        if (invalidField) invalidField.focus();

        alertEl.innerText = 'Por favor completa los campos obligatorios requeridos (Nombre, Usuario y un Correo electrónico válido).';
        alertEl.classList.remove('d-none');
        return;
    }

    isSavingUser = true;
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

    fetch('back_gestion_usuarios.php', {
        method: 'POST',
        body: formData
    })
        .then(res => res.text())
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                alertEl.innerText = 'Respuesta inesperada del servidor. Por favor intenta de nuevo.';
                alertEl.classList.remove('d-none');
                return;
            }

            if (data.success) {
                const modalEl = document.getElementById('userModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: data.message || 'Usuario guardado correctamente',
                    showConfirmButton: false,
                    timer: 2000
                });
                loadUsers();
            } else {
                alertEl.innerText = data.message || 'Error al guardar';
                alertEl.classList.remove('d-none');
            }
        })
        .catch(() => {
            alertEl.innerText = 'Error al comunicarse con el servidor. Por favor intenta de nuevo.';
            alertEl.classList.remove('d-none');
        })
        .finally(() => {
            isSavingUser = false;
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
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
