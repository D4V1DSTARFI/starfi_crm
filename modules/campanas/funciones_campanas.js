// modules/campanas/funciones_campanas.js
// Controlador Frontend del Ecosistema de Campañas de Difusión Masiva | STARFI CRM

let ECO_STATE = {
    sedeSeleccionada: 0,
    sedes: [],
    etiquetas: [],
    contactos: [],
    contactosVisibles: [],
    seleccionadosIds: new Set(),
    campanas: [],
    templatesSede: [],
    enviandoCola: false,
    detenerSolicitado: false,
    destinatariosModalCache: []
};

document.addEventListener('DOMContentLoaded', () => {
    // Verificar si vienen contactos preseleccionados desde Directorio 360 via sessionStorage
    try {
        const pre = sessionStorage.getItem('starfi_campana_preseleccion');
        if (pre) {
            const arr = JSON.parse(pre);
            if (Array.isArray(arr)) {
                arr.forEach(id => ECO_STATE.seleccionadosIds.add(parseInt(id, 10)));
            }
            sessionStorage.removeItem('starfi_campana_preseleccion');
        }
    } catch (e) {}

    actualizarVistaPreviaCampana();
    recargarEcosistema();
});

function obtenerSedeActualObj() {
    return ECO_STATE.sedes.find(s => parseInt(s.id, 10) === parseInt(ECO_STATE.sedeSeleccionada, 10)) || null;
}

async function recargarEcosistema(forzarSedeId = null) {
    const sedeParam = forzarSedeId !== null ? forzarSedeId : ECO_STATE.sedeSeleccionada;
    try {
        const resp = await fetch(`back_campanas.php?action=get_ecosystem_data&id_sede=${sedeParam}`);
        const data = await resp.json();
        if (data.status !== 'success') {
            alert(data.message || 'Error al cargar el ecosistema de campañas.');
            return;
        }

        ECO_STATE.sedes = data.sedes || [];
        ECO_STATE.sedeSeleccionada = parseInt(data.sede_seleccionada || 0, 10);
        ECO_STATE.etiquetas = data.etiquetas || [];
        ECO_STATE.contactos = data.contactos || [];
        ECO_STATE.campanas = data.campanas || [];

        renderizarSelectorSedeYBarraFlujo();
        renderizarKpisGlobales(data.kpis || {});
        renderizarFiltroEtiquetas();
        filtrarAudiencia();
        renderizarTablaCampanas();
        cargarPlantillasDeSede(ECO_STATE.sedeSeleccionada);
    } catch (err) {
        console.error('Error en recargarEcosistema:', err);
    }
}

function renderizarSelectorSedeYBarraFlujo() {
    const sel = document.getElementById('selectSedeActiva');
    sel.innerHTML = '';

    if (ECO_STATE.sedes.length === 0) {
        sel.innerHTML = '<option value="0">Sin sedes registradas</option>';
        return;
    }

    ECO_STATE.sedes.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = `${s.nombre_sede} (${s.numero_telefono})`;
        if (parseInt(s.id, 10) === ECO_STATE.sedeSeleccionada) {
            opt.selected = true;
        }
        sel.appendChild(opt);
    });

    const sedeObj = obtenerSedeActualObj();
    const badgeLinea = document.getElementById('badgeLineaSede');
    const txtNum = document.getElementById('txtNumeroSede');
    const lblCuota = document.getElementById('lblCuotaDiariaResumen');
    const barCuota = document.getElementById('barCuotaDiariaSede');
    const lblDisp = document.getElementById('lblDisponiblesHoy');
    const lblCad = document.getElementById('lblCadenciaSede');
    const kpiTarifa = document.getElementById('kpiTarifaUnitariaSede');
    const badgeCostoUnit = document.getElementById('badgeCostoUnitario');

    if (!sedeObj) return;

    if (sedeObj.tiene_linea_activa) {
        badgeLinea.classList.remove('offline');
        txtNum.innerHTML = `Línea Oficial: <strong>${escapeHtml(sedeObj.numero_telefono)}</strong>`;
    } else {
        badgeLinea.classList.add('offline');
        txtNum.innerHTML = `Sin línea WABA activa (${escapeHtml(sedeObj.numero_telefono)})`;
    }

    const hoy = sedeObj.enviados_hoy || 0;
    const lim = sedeObj.limite_diario_mensajes || 250;
    const disp = sedeObj.disponibles_hoy || 0;
    const pct = sedeObj.porcentaje_cuota_hoy || 0;
    const cadSeg = ((sedeObj.cadencia_ms || 1000) / 1000).toFixed(1);
    const costoMkt = parseFloat(sedeObj.costo_marketing_usd || 0.07).toFixed(4);

    lblCuota.textContent = `${hoy} / ${lim} enviados hoy`;
    barCuota.style.width = `${pct}%`;
    barCuota.className = 'progress-bar ' + (pct >= 90 ? 'bg-danger' : (pct >= 70 ? 'bg-warning' : 'bg-success'));
    lblDisp.textContent = `Disponibles hoy sin riesgo: ${disp} mensajes`;
    lblCad.textContent = `Cadencia segura: ${cadSeg}s / msg`;
    kpiTarifa.textContent = `$${costoMkt}`;
    badgeCostoUnit.textContent = `Marketing: $${costoMkt} / msg`;

    recalcularPresupuestoCampana();
}

function cambiarSedeActiva() {
    const sel = document.getElementById('selectSedeActiva');
    const nuevaSede = parseInt(sel.value, 10) || 0;
    ECO_STATE.sedeSeleccionada = nuevaSede;
    recargarEcosistema(nuevaSede);
}

async function cargarPlantillasDeSede(idSede) {
    if (!idSede) return;
    try {
        const resp = await fetch(`back_campanas.php?action=get_sede_templates&id_sede=${idSede}`);
        const data = await resp.json();
        if (data.status === 'success' && Array.isArray(data.templates)) {
            ECO_STATE.templatesSede = data.templates;
            const sel = document.getElementById('campTemplate');
            sel.innerHTML = '';
            data.templates.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.name;
                opt.dataset.lang = t.language || 'es';
                opt.textContent = `${t.name} (${(t.language || 'es').toUpperCase()})`;
                if (t.name === 'starfi_difusion_directa') {
                    opt.selected = true;
                }
                sel.appendChild(opt);
            });
            actualizarVistaPreviaCampana();
        }
    } catch (e) {
        console.error('Error cargando plantillas de la sede:', e);
    }
}

function renderizarKpisGlobales(kpis) {
    document.getElementById('kpiTotalEnviados').textContent = kpis.total_enviados || 0;
    document.getElementById('kpiTotalEntregados').textContent = kpis.total_entregados || 0;
    document.getElementById('kpiTasaEntrega').textContent = `${kpis.tasa_entrega_pct || 0}%`;

    document.getElementById('kpiTotalLeidos').textContent = kpis.total_leidos || 0;
    document.getElementById('kpiTasaLectura').textContent = `${kpis.tasa_lectura_pct || 0}%`;

    document.getElementById('kpiTotalRespondidos').textContent = kpis.total_respondidos || 0;
    document.getElementById('kpiTasaRespuesta').textContent = `${kpis.tasa_respuesta_pct || 0}%`;

    const costoTotal = parseFloat(kpis.costo_total_usd || 0).toFixed(2);
    document.getElementById('kpiCostoTotalUsd').textContent = `$${costoTotal}`;
}

function renderizarFiltroEtiquetas() {
    const sel = document.getElementById('filtroAudienciaEtiqueta');
    const valActual = sel.value;
    sel.innerHTML = '<option value="">Todas las etiquetas</option>';
    ECO_STATE.etiquetas.forEach(tag => {
        const opt = document.createElement('option');
        opt.value = tag;
        opt.textContent = tag;
        if (tag === valActual) opt.selected = true;
        sel.appendChild(opt);
    });
}

function filtrarAudiencia() {
    const origen = document.getElementById('filtroAudienciaOrigen').value;
    const tagFiltro = document.getElementById('filtroAudienciaEtiqueta').value;
    const buscar = (document.getElementById('filtroAudienciaBuscar').value || '').toLowerCase().trim();

    const boxDir = document.getElementById('boxAudienciaDirectorio');
    const boxMan = document.getElementById('boxAudienciaManual');

    if (origen === 'MANUAL') {
        boxDir.classList.add('d-none');
        boxMan.classList.remove('d-none');
        recalcularPresupuestoCampana();
        return;
    }

    boxDir.classList.remove('d-none');
    boxMan.classList.add('d-none');

    const sedeId = parseInt(ECO_STATE.sedeSeleccionada, 10);

    ECO_STATE.contactosVisibles = ECO_STATE.contactos.filter(c => {
        if (origen === 'SEDE' && sedeId > 0) {
            if (!Array.isArray(c.sedes_ids) || !c.sedes_ids.includes(sedeId)) {
                return false;
            }
        }
        if (tagFiltro && (!Array.isArray(c.etiquetas) || !c.etiquetas.includes(tagFiltro))) {
            return false;
        }
        if (buscar) {
            const matchNom = (c.nombre || '').toLowerCase().includes(buscar);
            const matchTel = (c.telefono || '').toLowerCase().includes(buscar);
            if (!matchNom && !matchTel) return false;
        }
        return true;
    });

    document.getElementById('lblCountVisibles').textContent = ECO_STATE.contactosVisibles.length;
    renderizarListaContactosCheck();
    recalcularPresupuestoCampana();
}

function renderizarListaContactosCheck() {
    const cont = document.getElementById('listaContactosCheck');
    if (ECO_STATE.contactosVisibles.length === 0) {
        cont.innerHTML = `<div class="p-4 text-center text-muted small">No se encontraron contactos con este filtro. Pruebe cambiando a "Todo el Directorio 360".</div>`;
        return;
    }

    let html = '';
    ECO_STATE.contactosVisibles.forEach(c => {
        const checked = ECO_STATE.seleccionadosIds.has(c.id) ? 'checked' : '';
        const tagsBadges = (c.etiquetas || []).slice(0, 2).map(t => `<span class="badge bg-light text-secondary border ms-1" style="font-size:0.68rem;">${escapeHtml(t)}</span>`).join('');
        html += `
            <label class="recipient-item m-0">
                <div class="d-flex align-items-center gap-2">
                    <input type="checkbox" class="form-check-input mt-0" value="${c.id}" ${checked} onchange="toggleContactoIndividual(${c.id}, this.checked)">
                    <div>
                        <div class="fw-semibold text-dark">${escapeHtml(c.nombre)} ${tagsBadges}</div>
                        <div class="text-muted" style="font-size: 0.76rem;"><i class="fa-brands fa-whatsapp text-success me-1"></i>${escapeHtml(c.telefono)}</div>
                    </div>
                </div>
            </label>
        `;
    });
    cont.innerHTML = html;
}

function toggleContactoIndividual(idCliente, isChecked) {
    if (isChecked) {
        ECO_STATE.seleccionadosIds.add(parseInt(idCliente, 10));
    } else {
        ECO_STATE.seleccionadosIds.delete(parseInt(idCliente, 10));
    }
    recalcularPresupuestoCampana();
}

function toggleSeleccionarTodosVisibles(isChecked) {
    ECO_STATE.contactosVisibles.forEach(c => {
        if (isChecked) {
            ECO_STATE.seleccionadosIds.add(c.id);
        } else {
            ECO_STATE.seleccionadosIds.delete(c.id);
        }
    });
    renderizarListaContactosCheck();
    recalcularPresupuestoCampana();
}

function seleccionarHastaCuotaDiaria() {
    const sedeObj = obtenerSedeActualObj();
    const disp = sedeObj ? parseInt(sedeObj.disponibles_hoy || 250, 10) : 250;
    if (disp <= 0) {
        alert('La sede ya alcanzó su límite diario hoy.');
        return;
    }
    ECO_STATE.seleccionadosIds.clear();
    ECO_STATE.contactosVisibles.slice(0, disp).forEach(c => {
        ECO_STATE.seleccionadosIds.add(c.id);
    });
    renderizarListaContactosCheck();
    recalcularPresupuestoCampana();
}

function obtenerDestinatariosSeleccionadosArray() {
    const origen = document.getElementById('filtroAudienciaOrigen').value;
    if (origen === 'MANUAL') {
        const raw = document.getElementById('txtAudienciaManual').value || '';
        const lines = raw.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
        const result = [];
        lines.forEach(line => {
            if (line.includes(',')) {
                const parts = line.split(',');
                const nombre = parts[0].trim() || 'Cliente';
                const tel = parts.slice(1).join('').replace(/[^0-9]/g, '');
                if (tel.length >= 8) {
                    result.push({ id_cliente: 0, nombre, telefono: tel });
                }
            } else {
                const tel = line.replace(/[^0-9]/g, '');
                if (tel.length >= 8) {
                    result.push({ id_cliente: 0, nombre: 'Cliente', telefono: tel });
                }
            }
        });
        return result;
    } else {
        const list = [];
        ECO_STATE.contactos.forEach(c => {
            if (ECO_STATE.seleccionadosIds.has(c.id)) {
                list.push({
                    id_cliente: c.id,
                    nombre: c.nombre || 'Cliente',
                    telefono: c.telefono
                });
            }
        });
        return list;
    }
}

function recalcularPresupuestoCampana() {
    const dests = obtenerDestinatariosSeleccionadosArray();
    const totalSel = dests.length;
    document.getElementById('badgeTotalSeleccionados').textContent = `${totalSel} seleccionados`;

    const sedeObj = obtenerSedeActualObj();
    const costoUnit = sedeObj ? parseFloat(sedeObj.costo_marketing_usd || 0.07) : 0.07;
    const dispHoy = sedeObj ? parseInt(sedeObj.disponibles_hoy || 250, 10) : 250;

    const presMaxInput = parseFloat(document.getElementById('campPresupuestoMax').value || 0);
    let efectivos = totalSel;
    let recortePresupuesto = 0;

    if (presMaxInput > 0 && costoUnit > 0) {
        const maxPorPresupuesto = Math.floor((presMaxInput + 0.00001) / costoUnit);
        if (efectivos > maxPorPresupuesto) {
            recortePresupuesto = efectivos - maxPorPresupuesto;
            efectivos = Math.max(0, maxPorPresupuesto);
        }
    }

    const costoEstimado = (efectivos * costoUnit).toFixed(4);
    document.getElementById('lblCostoEstimadoCampana').textContent = `$${costoEstimado} USD`;

    const hoySalen = Math.min(efectivos, dispHoy);
    const quedanCola = Math.max(0, efectivos - hoySalen);

    const lblResumen = document.getElementById('lblResumenFlujoYPresupuesto');
    if (totalSel === 0) {
        lblResumen.innerHTML = `Seleccione destinatarios del Directorio o pegue una lista manual para calcular presupuesto y flujo diario.`;
        return;
    }

    let msg = `<i class="fa-solid fa-circle-check text-success me-1"></i>Se enviarán <strong>${hoySalen} mensajes hoy</strong> ($${(hoySalen * costoUnit).toFixed(2)} USD).`;
    if (quedanCola > 0) {
        msg += ` <span class="text-danger fw-bold"><i class="fa-solid fa-clock ms-1"></i>${quedanCola} quedarán en cola PENDIENTE</span> para mañana por protección Anti-Bloqueo.`;
    }
    if (recortePresupuesto > 0) {
        msg += ` <span class="text-dark fw-semibold">(${recortePresupuesto} omitidos por tope de presupuesto máx.)</span>`;
    }
    lblResumen.innerHTML = msg;
}

function actualizarVistaPreviaCampana() {
    const p2 = (document.getElementById('campParam2').value || '').trim() || '{{2}}';
    const p3 = (document.getElementById('campParam3').value || '').trim() || '{{3}}';
    const bubble = document.getElementById('waBubblePreview');

    bubble.innerHTML = `
        <div>👋¡Hola <strong>Carlos</strong>!</div>
        <div class="mt-1">Hoy queremos ${escapeHtml(p2)}.</div>
        <div class="mt-1">Recuerda que en ${escapeHtml(p3)}.</div>
        <div class="mt-2">📲 <strong>Responde a este mensaje para obtener más información</strong>👇</div>
        <div class="mt-2 pt-1 border-top border-secondary border-opacity-25 text-muted" style="font-size: 0.72rem;">
            Servicio de mensajería inteligente de STARFI CRM
        </div>
    `;
}

// =========================================================================
// LANZAMIENTO Y CONTROL DE COLA EN TIEMPO REAL
// =========================================================================
async function iniciarLanzamientoCampana() {
    if (ECO_STATE.enviandoCola) return;

    const sedeObj = obtenerSedeActualObj();
    if (!sedeObj || !sedeObj.tiene_linea_activa) {
        alert('La sede seleccionada no tiene una línea oficial de WhatsApp activa.');
        return;
    }

    const nombreCampana = (document.getElementById('campNombre').value || '').trim() || `Campaña ${sedeObj.nombre_sede} - ${new Date().toLocaleDateString()}`;
    const selTpl = document.getElementById('campTemplate');
    const templateName = selTpl.value || 'starfi_difusion_directa';
    const langCode = selTpl.options[selTpl.selectedIndex]?.dataset?.lang || 'es';
    const param2 = (document.getElementById('campParam2').value || '').trim();
    const param3 = (document.getElementById('campParam3').value || '').trim();
    const presupuestoMax = parseFloat(document.getElementById('campPresupuestoMax').value || 0);
    const costoUnit = parseFloat(sedeObj.costo_marketing_usd || 0.07);

    if (!param2 || !param3) {
        alert('Por favor complete las variables {{2}} y {{3}} de la plantilla.');
        return;
    }

    const destinatarios = obtenerDestinatariosSeleccionadosArray();
    if (destinatarios.length === 0) {
        alert('Seleccione al menos un destinatario para lanzar la campaña.');
        return;
    }

    const confirmar = confirm(
        `¿Confirmar lanzamiento de la campaña "${nombreCampana}"?\n\n` +
        `• Sede Emisora: ${sedeObj.nombre_sede} (${sedeObj.numero_telefono})\n` +
        `• Audiencia Seleccionada: ${destinatarios.length} contactos\n` +
        `• Costo Unitario Meta: $${costoUnit.toFixed(4)} USD`
    );
    if (!confirmar) return;

    try {
        const fd = new FormData();
        fd.append('action', 'create_campaign_with_queue');
        fd.append('id_sede', sedeObj.id);
        fd.append('nombre_campana', nombreCampana);
        fd.append('template_name', templateName);
        fd.append('param_cuerpo', param2);
        fd.append('param_eslogan', param3);
        fd.append('costo_unitario_usd', costoUnit);
        fd.append('presupuesto_max_usd', presupuestoMax);
        fd.append('destinatarios', JSON.stringify(destinatarios));

        const resp = await fetch('back_campanas.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.status !== 'success') {
            alert(data.message || 'No se pudo crear la campaña.');
            return;
        }

        const cola = data.cola_envio || [];
        if (cola.length === 0) {
            alert(`La campaña fue creada con ${data.total_encolados} destinatarios en cola, pero la sede ya alcanzó hoy su tope diario (${data.limite_diario} mensajes). Podrá reanudarla mañana con 1 clic.`);
            recargarEcosistema();
            return;
        }

        await procesarColaDeEnvio(data.id_campana, cola, data.cadencia_ms || 1000, langCode);
    } catch (err) {
        console.error('Error al iniciar campaña:', err);
        alert('Error de red al iniciar la campaña.');
    }
}

async function procesarColaDeEnvio(idCampana, cola, cadenciaMs, langCode = 'es') {
    ECO_STATE.enviandoCola = true;
    ECO_STATE.detenerSolicitado = false;

    const boxConsola = document.getElementById('boxConsolaEnvio');
    const lblEstado = document.getElementById('lblEstadoConsola');
    const lblContador = document.getElementById('lblContadorConsola');
    const barProgreso = document.getElementById('barProgresoConsola');
    const logDiv = document.getElementById('logConsolaEnvio');
    const btnLanzar = document.getElementById('btnLanzarCampana');
    const btnDetener = document.getElementById('btnDetenerCola');

    boxConsola.classList.remove('d-none');
    btnDetener.classList.remove('d-none');
    btnLanzar.disabled = true;
    logDiv.innerHTML = '';

    let enviadosOk = 0;
    let fallidos = 0;
    const total = cola.length;

    for (let i = 0; i < total; i++) {
        if (ECO_STATE.detenerSolicitado) {
            logDiv.innerHTML = `<div class="text-warning">⏸ Envío pausado manualmente por el operador. Los restantes quedan como PENDIENTE.</div>` + logDiv.innerHTML;
            break;
        }

        const item = cola[i];
        lblContador.textContent = `${i + 1} / ${total}`;
        const pct = Math.round(((i + 1) / total) * 100);
        barProgreso.style.width = `${pct}%`;

        try {
            const fd = new FormData();
            fd.append('action', 'dispatch_queue_item');
            fd.append('id_campana', idCampana);
            fd.append('id_destinatario', item.id_destinatario);
            fd.append('language_code', langCode);

            const r = await fetch('back_campanas.php', { method: 'POST', body: fd });
            const res = await r.json();

            if (res.status === 'success') {
                enviadosOk++;
                logDiv.innerHTML = `<div class="text-success">✔ [${i + 1}/${total}] Enviado a ${escapeHtml(item.nombre)} (${escapeHtml(res.telefono)}) - $${parseFloat(res.costo_usd).toFixed(4)}</div>` + logDiv.innerHTML;
            } else if (res.status === 'quota_reached' || res.status === 'budget_reached') {
                logDiv.innerHTML = `<div class="text-warning fw-bold">🛡 ${escapeHtml(res.message)}</div>` + logDiv.innerHTML;
                break;
            } else {
                fallidos++;
                logDiv.innerHTML = `<div class="text-danger">✖ [${i + 1}/${total}] Falló ${escapeHtml(item.telefono)}: ${escapeHtml(res.message || 'Error')}</div>` + logDiv.innerHTML;
            }
        } catch (e) {
            fallidos++;
            logDiv.innerHTML = `<div class="text-danger">✖ Error de red enviando a ${escapeHtml(item.telefono)}</div>` + logDiv.innerHTML;
        }

        if (i < total - 1 && !ECO_STATE.detenerSolicitado) {
            await new Promise(resolve => setTimeout(resolve, cadenciaMs));
        }
    }

    // Marcar fin del lote en el backend
    const fdFin = new FormData();
    fdFin.append('action', 'finish_campaign_batch');
    fdFin.append('id_campana', idCampana);
    await fetch('back_campanas.php', { method: 'POST', body: fdFin });

    ECO_STATE.enviandoCola = false;
    btnDetener.classList.add('d-none');
    btnLanzar.disabled = false;
    lblEstado.innerHTML = `<i class="fa-solid fa-circle-check text-success me-1"></i>Lote finalizado (${enviadosOk} enviados, ${fallidos} fallidos)`;

    recargarEcosistema();
}

function detenerColaEnvio() {
    ECO_STATE.detenerSolicitado = true;
}

// =========================================================================
// REANUDAR CAMPAÑA CON DESTINATARIOS PENDIENTES
// =========================================================================
async function reanudarCampanaPendiente(idCampana) {
    if (ECO_STATE.enviandoCola) {
        alert('Ya hay un envío en curso.');
        return;
    }
    try {
        const resp = await fetch(`back_campanas.php?action=get_pending_queue&id_campana=${idCampana}`);
        const data = await resp.json();
        if (data.status !== 'success') {
            alert(data.message || 'No se pudo obtener la cola pendiente.');
            return;
        }
        const cola = data.cola_envio || [];
        if (cola.length === 0) {
            alert('Esta campaña ya no tiene destinatarios pendientes.');
            recargarEcosistema();
            return;
        }
        if (!confirm(`Se reanudará el envío de ${cola.length} destinatarios pendientes de "${data.nombre_campana}" respetando la cuota diaria de la sede. ¿Continuar?`)) {
            return;
        }
        await procesarColaDeEnvio(idCampana, cola, data.cadencia_ms || 1000, 'es');
    } catch (e) {
        console.error(e);
        alert('Error al reanudar la campaña.');
    }
}

// =========================================================================
// TABLA DE CAMPAÑAS Y MODAL DE AUDITORÍA DE DESTINATARIOS (LEÍDOS Y RESPONDIDOS)
// =========================================================================
function renderizarTablaCampanas() {
    const tbody = document.getElementById('tbodyCampanas');
    document.getElementById('badgeTotalCampanas').textContent = `${ECO_STATE.campanas.length} campañas`;

    if (ECO_STATE.campanas.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-5 text-muted">
                    <i class="fa-solid fa-bullhorn fs-3 d-block mb-2 text-secondary opacity-50"></i>
                    Aún no hay campañas registradas para esta sede. Cree su primera campaña en el panel izquierdo.
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    ECO_STATE.campanas.forEach(c => {
        const total = parseInt(c.total_destinatarios || 0, 10);
        const pend = parseInt(c.pendientes || 0, 10);
        const env = parseInt(c.enviados || 0, 10);
        const ent = parseInt(c.entregados || 0, 10);
        const lei = parseInt(c.leidos || 0, 10);
        const resp = parseInt(c.respondidos || 0, 10);
        const fal = parseInt(c.fallidos || 0, 10);
        const costo = parseFloat(c.costo_real_usd || 0).toFixed(2);
        const presMax = parseFloat(c.presupuesto_max_usd || 0);

        let badgeEstado = `<span class="badge bg-success-subtle text-success border border-success-subtle">Completada</span>`;
        if (pend > 0 || c.estado === 'EN_COLA_DIARIA' || c.estado === 'PAUSADA_CUOTA_DIARIA') {
            badgeEstado = `<span class="badge bg-warning-subtle text-dark border border-warning">${pend} en Cola Diaria</span>`;
        } else if (c.estado === 'EN_PROCESO') {
            badgeEstado = `<span class="badge bg-primary-subtle text-primary">En Proceso</span>`;
        }

        const btnReanudar = pend > 0
            ? `<button class="btn btn-sm btn-warning fw-semibold me-1" title="Reanudar pendientes hoy" onclick="reanudarCampanaPendiente(${c.id})">
                   <i class="fa-solid fa-play"></i>
               </button>`
            : '';

        html += `
            <tr>
                <td class="ps-3">
                    <div class="fw-bold text-dark">${escapeHtml(c.nombre_campana)}</div>
                    <div class="text-muted" style="font-size: 0.75rem;">
                        <i class="fa-solid fa-building me-1"></i>${escapeHtml(c.nombre_sede || 'Sede')} 
                        <span class="ms-1">• ${escapeHtml(c.fecha_creacion || '')}</span>
                    </div>
                </td>
                <td class="text-center">
                    <span class="fw-bold">${total}</span>
                    ${fal > 0 ? `<div class="text-danger" style="font-size:0.7rem;">${fal} fallidos</div>` : ''}
                </td>
                <td class="text-center">
                    <span class="funnel-pill" style="background: rgba(59,130,246,0.1); color:#2563EB;">
                        <i class="fa-solid fa-check"></i> ${env}
                    </span>
                    <div class="text-muted" style="font-size: 0.7rem;">Entregados: ${ent}</div>
                </td>
                <td class="text-center">
                    <span class="funnel-pill" style="background: rgba(14,165,233,0.12); color:#0284C7;">
                        <i class="fa-solid fa-check-double"></i> ${lei}
                    </span>
                    <div class="text-info fw-semibold" style="font-size: 0.7rem;">${c.tasa_lectura || 0}% abiertos</div>
                </td>
                <td class="text-center">
                    <span class="funnel-pill" style="background: rgba(16,185,129,0.14); color:#059669;">
                        <i class="fa-solid fa-reply"></i> ${resp}
                    </span>
                    <div class="text-success fw-semibold" style="font-size: 0.7rem;">${c.tasa_respuesta || 0}% resp.</div>
                </td>
                <td class="text-end">
                    <div class="fw-bold text-dark">$${costo}</div>
                    ${presMax > 0 ? `<div class="text-muted" style="font-size:0.7rem;">Tope: $${presMax.toFixed(2)}</div>` : ''}
                </td>
                <td class="text-center">${badgeEstado}</td>
                <td class="text-end pe-3">
                    ${btnReanudar}
                    <button class="btn btn-sm btn-outline-primary" onclick="verDetalleDestinatariosCampana(${c.id})">
                        <i class="fa-solid fa-eye me-1"></i>Auditoría
                    </button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

async function verDetalleDestinatariosCampana(idCampana) {
    try {
        const resp = await fetch(`back_campanas.php?action=get_campaign_recipients&id_campana=${idCampana}`);
        const data = await resp.json();
        if (data.status !== 'success') {
            alert(data.message || 'No se pudo cargar el detalle de la campaña.');
            return;
        }

        const camp = data.campana || {};
        ECO_STATE.destinatariosModalCache = data.destinatarios || [];

        document.getElementById('detTituloCampana').textContent = `${camp.nombre_campana || 'Campaña'} — Embudo de Destinatarios`;
        document.getElementById('detSubtituloCampana').textContent = `Sede: ${camp.nombre_sede || ''} (${camp.linea_telefono || ''}) | Plantilla: ${camp.template_name || ''}`;

        filtrarTablaDestinatariosModal('TODOS', null);
        const modal = new bootstrap.Modal(document.getElementById('modalDetalleCampana'));
        modal.show();
    } catch (e) {
        console.error(e);
    }
}

function filtrarTablaDestinatariosModal(filtro, btnEl) {
    if (btnEl) {
        const group = btnEl.parentElement.querySelectorAll('.btn');
        group.forEach(b => b.classList.remove('active'));
        btnEl.classList.add('active');
    }

    const lista = ECO_STATE.destinatariosModalCache.filter(d => {
        if (filtro === 'TODOS') return true;
        if (filtro === 'RESPONDIDO') return parseInt(d.respondido, 10) === 1 || d.estado_envio === 'RESPONDIDO';
        if (filtro === 'LEIDO') return d.fecha_lectura !== null || d.estado_envio === 'LEIDO' || d.estado_envio === 'RESPONDIDO';
        return d.estado_envio === filtro;
    });

    document.getElementById('detResumenModal').textContent = `Mostrando ${lista.length} de ${ECO_STATE.destinatariosModalCache.length} destinatarios`;

    const tbody = document.getElementById('tbodyDetalleDestinatarios');
    if (lista.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted">No hay destinatarios en este estado.</td></tr>`;
        return;
    }

    let html = '';
    lista.forEach(d => {
        let badge = `<span class="badge bg-secondary">${escapeHtml(d.estado_envio)}</span>`;
        if (d.estado_envio === 'RESPONDIDO' || parseInt(d.respondido, 10) === 1) {
            badge = `<span class="badge bg-success"><i class="fa-solid fa-reply me-1"></i>RESPONDIDO</span>`;
        } else if (d.estado_envio === 'LEIDO' || d.fecha_lectura) {
            badge = `<span class="badge bg-info text-dark"><i class="fa-solid fa-check-double me-1"></i>LEÍDO</span>`;
        } else if (d.estado_envio === 'ENTREGADO') {
            badge = `<span class="badge bg-primary-subtle text-primary">ENTREGADO</span>`;
        } else if (d.estado_envio === 'ENVIADO') {
            badge = `<span class="badge bg-light text-dark border">ENVIADO</span>`;
        } else if (d.estado_envio === 'FALLIDO') {
            badge = `<span class="badge bg-danger" title="${escapeHtml(d.error_detalle || '')}">FALLIDO</span>`;
        }

        const respTxt = d.respuesta_texto
            ? `<div class="fw-semibold text-success"><i class="fa-regular fa-comment-dots me-1"></i>"${escapeHtml(d.respuesta_texto)}"</div>
               <small class="text-muted">${escapeHtml(d.fecha_respuesta || '')}</small>`
            : (d.error_detalle ? `<span class="text-danger small">${escapeHtml(d.error_detalle)}</span>` : '<span class="text-muted">—</span>');

        html += `
            <tr>
                <td class="fw-semibold">${escapeHtml(d.nombre)}</td>
                <td><code>${escapeHtml(d.telefono)}</code></td>
                <td class="text-center">${badge}</td>
                <td class="small text-muted">${escapeHtml(d.fecha_envio || ' En cola')}</td>
                <td class="small text-info fw-semibold">${escapeHtml(d.fecha_lectura || '—')}</td>
                <td>${respTxt}</td>
                <td class="text-end fw-semibold">$${parseFloat(d.costo_usd || 0).toFixed(4)}</td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

// =========================================================================
// CONFIGURACIÓN DE FLUJO DIARIO Y TARIFAS META POR SEDE
// =========================================================================
function abrirModalFlujoSede() {
    const sedeObj = obtenerSedeActualObj();
    if (!sedeObj) return;

    document.getElementById('cfgLimiteDiario').value = sedeObj.limite_diario_mensajes || 250;
    document.getElementById('cfgCadenciaMs').value = sedeObj.cadencia_ms || 1000;
    document.getElementById('cfgCostoMarketing').value = parseFloat(sedeObj.costo_marketing_usd || 0.07).toFixed(4);
    document.getElementById('cfgCostoUtility').value = parseFloat(sedeObj.costo_utility_usd || 0.015).toFixed(4);

    const modal = new bootstrap.Modal(document.getElementById('modalFlujoSede'));
    modal.show();
}

async function guardarConfiguracionFlujoSede() {
    const sedeObj = obtenerSedeActualObj();
    if (!sedeObj) return;

    const fd = new FormData();
    fd.append('action', 'save_sede_flow_config');
    fd.append('id_sede', sedeObj.id);
    fd.append('limite_diario_mensajes', document.getElementById('cfgLimiteDiario').value);
    fd.append('cadencia_ms', document.getElementById('cfgCadenciaMs').value);
    fd.append('costo_marketing_usd', document.getElementById('cfgCostoMarketing').value);
    fd.append('costo_utility_usd', document.getElementById('cfgCostoUtility').value);

    try {
        const resp = await fetch('back_campanas.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('modalFlujoSede'))?.hide();
            recargarEcosistema();
        } else {
            alert(data.message || 'Error al guardar configuración.');
        }
    } catch (e) {
        alert('Error de conexión al guardar parámetros.');
    }
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
