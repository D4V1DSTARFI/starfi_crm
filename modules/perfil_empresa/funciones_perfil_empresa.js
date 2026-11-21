let currentSede = 1;
let currentPerfiles = [];
let selectedEmpresaData = null;

document.addEventListener('DOMContentLoaded', () => {
    verGestionTablas(1);
});

function loadPerfiles() {
    fetch('back_empresa.php?action=get_perfiles')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                currentPerfiles = data.data || [];
                renderTablaPrincipal();
            } else {
                currentPerfiles = [];
                renderTablaPrincipal();
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Fallo de conexión al cargar empresas registradas.', 'error');
        });
}

function renderTablaPrincipal() {
    const tbody = document.getElementById('tablaPerfilesBody');
    const search = document.getElementById('searchEmpresa').value.toLowerCase().trim();

    const filtered = currentPerfiles.filter(p => {
        const rs = (p.razon_social || '').toLowerCase();
        const rif = (p.rif || '').toLowerCase();
        return (rs.includes(search) || rif.includes(search));
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-5 text-muted">
                    <i class="fa-solid fa-building-slash fs-1 text-secondary opacity-50 d-block mb-2"></i>
                    No hay empresas registradas en esta sede. Haz clic en <strong>"Registrar Empresa"</strong>.
                </td>
            </tr>`;
        return;
    }

    let html = '';
    filtered.forEach((p, idx) => {
        const rifCompleto = `${escapeHtml(p.letra)}-${escapeHtml(p.rif)}`;
        const condicionText = (p.condicion === '[ESPECIAL]') ? 'ESPECIAL' : 'ORDINARIO';

        html += `
            <tr>
                <td class="fw-bold text-muted">${idx + 1}</td>
                <td>
                    <div class="d-flex align-items-center gap-3">
                        ${p.logo_empresa ? `<img src="../../uploads/empresa/${encodeURIComponent(p.logo_empresa)}" class="rounded border" style="width:40px; height:40px; object-fit:contain;">` : `<div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-primary" style="width:40px; height:40px;"><i class="fa-solid fa-building fs-5"></i></div>`}
                        <div>
                            <div class="fw-bold text-dark mb-0">${escapeHtml(p.razon_social)}</div>
                            <span class="text-muted small">${escapeHtml(p.email || 'Sin correo')}</span>
                        </div>
                    </div>
                </td>
                <td class="fw-bold text-primary">${rifCompleto}</td>
                <td><span class="badge-condicion">${condicionText}</span></td>
                <td class="fw-medium text-dark">${escapeHtml(p.firmante_principal || 'Sin asignar')}</td>
                <td class="text-end">
                    <div class="d-flex align-items-center justify-content-end gap-2">
                        <button class="btn btn-starfi-primary btn-sm px-3 py-1.5 fw-semibold d-flex align-items-center gap-1.5" onclick="verGestionTablas(${Number(p.id)})">
                            <i class="fa-solid fa-layer-group"></i> Gestionar Tablas
                        </button>
                        <button class="btn btn-light btn-sm text-secondary border px-2.5 py-1.5 rounded-2" onclick="editPerfilEmpresaDirecto(${Number(p.id)})" title="Editar Datos">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm px-2.5 py-1.5 rounded-2" onclick="deletePerfilEmpresa(${Number(p.id)})" title="Eliminar Empresa">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>`;
    });

    tbody.innerHTML = html;
}

function filterTablaPrincipal() {
    renderTablaPrincipal();
}

function loadPerfilDetalle(idEmpresa) {
    verGestionTablas(idEmpresa);
}

function verGestionTablas(idEmpresa) {
    fetch(`back_empresa.php?action=get_detalle_empresa&id_empresa=${encodeURIComponent(idEmpresa)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                selectedEmpresaData = data;
                const p = data.perfil;
                currentSede = p.id_sede || currentSede;

                document.getElementById('headerRazonSocial').innerText = p.razon_social;
                document.getElementById('headerRif').innerText = `RIF: ${p.letra}-${p.rif}`;
                document.getElementById('headerCondicion').innerText = (p.condicion === '[ESPECIAL]') ? 'CONTRIBUYENTE ESPECIAL' : 'ORDINARIO';
                document.getElementById('headerCorreo').innerText = p.email || 'Sin Correo';
                document.getElementById('headerTelefono').innerText = p.telefono || 'Sin Teléfono';

                const logoBox = document.getElementById('headerLogoBox');
                if (p.logo_empresa) {
                    logoBox.innerHTML = `<img src="../../uploads/empresa/${encodeURIComponent(p.logo_empresa)}" alt="Logo">`;
                } else {
                    logoBox.innerHTML = `<i class="fa-solid fa-building fs-2 text-secondary"></i>`;
                }

                document.getElementById('tdRazonSocial').innerText = p.razon_social;
                document.getElementById('tdRif').innerText = `${p.letra}-${p.rif}`;
                document.getElementById('tdCondicion').innerText = (p.condicion === '[ESPECIAL]') ? 'CONTRIBUYENTE ESPECIAL' : 'ORDINARIO';
                document.getElementById('tdCorreo').innerText = p.email || '-';
                document.getElementById('tdTelefono').innerText = p.telefono || '-';
                document.getElementById('tdWeb').innerText = p.web || '-';
                document.getElementById('tdInstagram').innerText = p.instagram || '-';
                document.getElementById('tdDireccion').innerText = p.direccion || '-';
                document.getElementById('tdLogoContainer').innerHTML = p.logo_empresa ? `<img src="../../uploads/empresa/${encodeURIComponent(p.logo_empresa)}" style="max-height: 50px;" class="rounded border p-1">` : 'Sin Logo';

                renderTablaFirmantes(data.firmantes);
                renderTablaRegistro(data.registros);
                renderTablaExpediente(data.expedientes);

                document.getElementById('vistaPrincipalListado').style.display = 'none';
                document.getElementById('vistaGestionTablas').style.display = 'block';
            }
        });
}

function volverAEstaLista() {
    document.getElementById('vistaGestionTablas').style.display = 'none';
    document.getElementById('vistaPrincipalListado').style.display = 'block';
    loadPerfiles();
}

function renderTablaFirmantes(firmantes) {
    const tbody = document.getElementById('tablaFirmantesBody');
    if (!firmantes || firmantes.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted"><i class="fa-solid fa-user-slash me-2 opacity-50"></i>No hay firmantes o representantes registrados.</td></tr>`;
        return;
    }
    let html = '';
    firmantes.forEach(f => {
        html += `
            <tr>
                <td class="fw-bold text-dark">${escapeHtml(f.nombre)}</td>
                <td class="fw-semibold text-secondary">${escapeHtml(f.cedula)}</td>
                <td><span class="badge bg-light text-dark border">${escapeHtml(f.cargo || 'Representante')}</span></td>
                <td>
                    <div class="small fw-medium">${escapeHtml(f.email || '-')}</div>
                    <div class="small text-muted">${escapeHtml(f.telefono || '-')}</div>
                </td>
                <td class="small text-muted">${escapeHtml(f.direccion || '-')}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteFirmante(${Number(f.id)})" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>`;
    });
    tbody.innerHTML = html;
}

function renderTablaRegistro(registros) {
    const tbody = document.getElementById('tablaRegistroBody');
    if (!registros || registros.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fa-solid fa-file-excel me-2 opacity-50"></i>No se han registrado inscripciones de registro mercantil.</td></tr>`;
        return;
    }
    let html = '';
    registros.forEach(r => {
        html += `
            <tr>
                <td class="fw-semibold text-dark">${escapeHtml(r.fecha)}</td>
                <td class="fw-bold text-primary">${escapeHtml(r.n_registro)}</td>
                <td class="fw-semibold text-secondary">Tomo ${escapeHtml(r.n_tomo)}</td>
                <td class="small">${escapeHtml(r.descripcion || '-')}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteRegistro(${Number(r.id)})" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>`;
    });
    tbody.innerHTML = html;
}

function renderTablaExpediente(expedientes) {
    const tbody = document.getElementById('tablaExpedienteBody');
    if (!expedientes || expedientes.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-muted"><i class="fa-solid fa-folder-open me-2 opacity-50"></i>No se han adjuntado documentos al expediente digital.</td></tr>`;
        return;
    }
    let html = '';
    expedientes.forEach(e => {
        html += `
            <tr>
                <td class="fw-semibold text-dark">${escapeHtml(e.fecha)}</td>
                <td class="fw-bold text-dark">${escapeHtml(e.descripcion)}</td>
                <td>
                    <a href="back_empresa.php?action=download_expediente&id=${encodeURIComponent(e.id)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary px-3 rounded-2 fw-semibold">
                        <i class="fa-solid fa-download me-1"></i> Ver / Descargar
                    </a>
                </td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteExpediente(${Number(e.id)})" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>`;
    });
    tbody.innerHTML = html;
}

function openManualModal() {
    const modal = new bootstrap.Modal(document.getElementById('modalManual'));
    modal.show();
}

function openPerfilModal() {
    document.getElementById('formPerfilEmpresa').reset();
    document.getElementById('perfilId').value = '0';
    document.getElementById('perfilSede').value = currentSede;
    document.getElementById('modalPerfilTitle').innerText = 'Registrar Perfil Corporativo';
    const modal = new bootstrap.Modal(document.getElementById('modalPerfilEmpresa'));
    modal.show();
}

function editPerfilEmpresaDirecto(idEmpresa) {
    verGestionTablas(idEmpresa);
    setTimeout(() => { editPerfilActual(); }, 300);
}

function editPerfilActual() {
    if (!selectedEmpresaData || !selectedEmpresaData.perfil) return;
    const p = selectedEmpresaData.perfil;
    document.getElementById('perfilId').value = p.id;
    document.getElementById('perfilSede').value = p.id_sede || currentSede;
    document.getElementById('inputRazonSocial').value = p.razon_social;
    document.getElementById('inputLetra').value = p.letra || 'J';
    document.getElementById('inputRif').value = p.rif;
    document.getElementById('inputCondicion').value = p.condicion || '[ORDINARIO]';
    document.getElementById('inputEmail').value = p.email || '';
    document.getElementById('inputTelefonoPerfil').value = p.telefono || '';
    document.getElementById('inputWeb').value = p.web || '';
    document.getElementById('inputInstagram').value = p.instagram || '';
    document.getElementById('inputDireccionPerfil').value = p.direccion || '';
    document.getElementById('modalPerfilTitle').innerText = 'Editar Perfil Corporativo';
    const modal = new bootstrap.Modal(document.getElementById('modalPerfilEmpresa'));
    modal.show();
}

function savePerfilEmpresa() {
    const form = document.getElementById('formPerfilEmpresa');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const formData = new FormData(form);
    formData.append('action', 'save_perfil');

    fetch('back_empresa.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const inst = bootstrap.Modal.getInstance(document.getElementById('modalPerfilEmpresa'));
                if (inst) inst.hide();
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: data.message || 'Perfil guardado', showConfirmButton: false, timer: 2000 });
                loadPerfiles();
                const pId = Number(document.getElementById('perfilId').value) || Number(data.id_empresa);
                if (pId > 0 && document.getElementById('vistaGestionTablas').style.display === 'block') {
                    verGestionTablas(pId);
                }
            } else {
                Swal.fire('Error', data.message || 'Error al guardar perfil', 'error');
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Fallo de conexión al guardar el perfil corporativo.', 'error');
        });
}

function deletePerfilEmpresa(id) {
    Swal.fire({
        title: '¿Eliminar empresa?',
        text: 'Se eliminará permanentemente esta empresa y todas sus tablas asociadas.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Sí, eliminar'
    }).then(result => {
        if (!result.isConfirmed) return;
        const formData = new FormData();
        formData.append('action', 'delete_perfil');
        formData.append('id', id);
        fetch('back_empresa.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    volverAEstaLista();
                } else {
                    Swal.fire('Error', data.message || 'No se pudo eliminar el perfil.', 'error');
                }
            });
    });
}

function openFirmanteModal() {
    if (!selectedEmpresaData || !selectedEmpresaData.perfil) return;
    document.getElementById('formFirmante').reset();
    document.getElementById('firmanteId').value = '0';
    document.getElementById('firmanteEmpresaId').value = selectedEmpresaData.perfil.id;
    document.getElementById('firmanteSedeId').value = currentSede;
    const modal = new bootstrap.Modal(document.getElementById('modalFirmante'));
    modal.show();
}

function saveFirmante() {
    const form = document.getElementById('formFirmante');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const formData = new FormData(form);
    formData.append('action', 'save_firmante');

    fetch('back_empresa.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const inst = bootstrap.Modal.getInstance(document.getElementById('modalFirmante'));
                if (inst) inst.hide();
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: data.message || 'Firmante guardado', showConfirmButton: false, timer: 2000 });
                loadPerfilDetalle(selectedEmpresaData.perfil.id);
            } else {
                Swal.fire('Error', data.message || 'Error al guardar firmante', 'error');
            }
        });
}

function deleteFirmante(id) {
    Swal.fire({
        title: '¿Eliminar firmante?',
        text: 'Se eliminará este representante legal de la empresa.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Sí, eliminar'
    }).then(result => {
        if (!result.isConfirmed) return;
        const formData = new FormData();
        formData.append('action', 'delete_firmante');
        formData.append('id', id);
        fetch('back_empresa.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    loadPerfilDetalle(selectedEmpresaData.perfil.id);
                } else {
                    Swal.fire('Error', data.message || 'No se pudo eliminar el firmante.', 'error');
                }
            });
    });
}

function openRegistroModal() {
    if (!selectedEmpresaData || !selectedEmpresaData.perfil) return;
    document.getElementById('formRegistro').reset();
    document.getElementById('registroId').value = '0';
    document.getElementById('registroEmpresaId').value = selectedEmpresaData.perfil.id;
    document.getElementById('registroSedeId').value = currentSede;
    const modal = new bootstrap.Modal(document.getElementById('modalRegistro'));
    modal.show();
}

function saveRegistro() {
    const form = document.getElementById('formRegistro');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const formData = new FormData(form);
    formData.append('action', 'save_registro');

    fetch('back_empresa.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const inst = bootstrap.Modal.getInstance(document.getElementById('modalRegistro'));
                if (inst) inst.hide();
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: data.message || 'Registro guardado', showConfirmButton: false, timer: 2000 });
                loadPerfilDetalle(selectedEmpresaData.perfil.id);
            } else {
                Swal.fire('Error', data.message || 'Error al guardar el registro mercantil', 'error');
            }
        });
}

function deleteRegistro(id) {
    Swal.fire({
        title: '¿Eliminar registro mercantil?',
        text: 'Esta inscripción mercantil será eliminada.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Sí, eliminar'
    }).then(result => {
        if (!result.isConfirmed) return;
        const formData = new FormData();
        formData.append('action', 'delete_registro');
        formData.append('id', id);
        fetch('back_empresa.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    loadPerfilDetalle(selectedEmpresaData.perfil.id);
                } else {
                    Swal.fire('Error', data.message || 'No se pudo eliminar el registro.', 'error');
                }
            });
    });
}

function openExpedienteModal() {
    if (!selectedEmpresaData || !selectedEmpresaData.perfil) return;
    document.getElementById('formExpediente').reset();
    document.getElementById('expedienteEmpresaId').value = selectedEmpresaData.perfil.id;
    document.getElementById('expedienteSedeId').value = currentSede;
    const modal = new bootstrap.Modal(document.getElementById('modalExpediente'));
    modal.show();
}

function saveExpediente() {
    const form = document.getElementById('formExpediente');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const formData = new FormData(form);
    formData.append('action', 'save_expediente');

    fetch('back_empresa.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const inst = bootstrap.Modal.getInstance(document.getElementById('modalExpediente'));
                if (inst) inst.hide();
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: data.message || 'Documento adjuntado', showConfirmButton: false, timer: 2000 });
                loadPerfilDetalle(selectedEmpresaData.perfil.id);
            } else {
                Swal.fire('Error', data.message || 'Error al adjuntar documento', 'error');
            }
        });
}

function deleteExpediente(id) {
    Swal.fire({
        title: '¿Eliminar documento?',
        text: 'Se eliminará este documento del expediente digital.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Sí, eliminar'
    }).then(result => {
        if (!result.isConfirmed) return;
        const formData = new FormData();
        formData.append('action', 'delete_expediente');
        formData.append('id', id);
        fetch('back_empresa.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    loadPerfilDetalle(selectedEmpresaData.perfil.id);
                } else {
                    Swal.fire('Error', data.message || 'No se pudo eliminar el documento.', 'error');
                }
            });
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}
