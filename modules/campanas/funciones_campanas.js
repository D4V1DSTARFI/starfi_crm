// modules/campanas/funciones_campanas.js
// Controlador Frontend del Ecosistema de Campañas de Difusión Masiva | STARFI CRM

let ECO_STATE = {
    sedeSeleccionada: 0,
    esGlobal: false,
    pasoWizard: 1,
    filtroHistorial: 'TODAS',
    paginaHistorial: 1,
    porPaginaHistorial: 6,
    sedes: [],
    etiquetas: [],
    contactos: [],
    contactosVisibles: [],
    seleccionadosIds: new Set(),
    campanas: [],
    templatesSede: [],
    valoresPorPlantilla: {
        'starfi_difusion_directa': {
            bodyVars: {
                1: '@NOMBRE_CLIENTE',
                2: 'invitarte este 16 de noviembre a la GRAN INAUGURACIÓN de nuestra nueva sede CARPINTERO EXPRESS BOLEÍTA 🎉🪵 Ven a disfrutar de grandes ofertas especiales de apertura, sorpresas y todo lo que necesitas para tus proyectos',
                3: 'Carpintero Express estamos cada día más cerca de ti y somos tu parada obligatoria'
            },
            autoClienteVars: { 1: true },
            headerTextVar: ''
        }
    },
    enviandoCola: false,
    detenerSolicitado: false,
    destinatariosModalCache: [],
    campanaModalActual: null
};

function irPasoWizard(paso) {
    if (paso > 1 && ECO_STATE.pasoWizard === 1) {
        const valid = validarVariablesPlantillaActiva(true);
        if (!valid.ok) return;
    }
    ECO_STATE.pasoWizard = paso;
    [1, 2, 3].forEach(n => {
        const panel = document.getElementById(`wizardStep${n}`);
        const btn = document.getElementById(`btnStep${n}`);
        if (panel) panel.classList.toggle('d-none', n !== paso);
        if (btn) {
            btn.classList.toggle('active', n === paso);
            btn.classList.toggle('completed', n < paso);
        }
    });
    recalcularPresupuestoCampana();
}

function formatearHora12h(hora24) {
    const clean = String(hora24 || '09:00').trim().substring(0, 5);
    const parts = clean.split(':');
    let h = parseInt(parts[0] || '9', 10);
    const m = parts[1] || '00';
    if (isNaN(h)) h = 9;
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${String(h12).padStart(2, '0')}:${m} ${ampm}`;
}

function actualizarRelojLocalBarra() {
    const el = document.getElementById('lblRelojServidor');
    if (!el) return;
    try {
        const now = new Date();
        el.textContent = now.toLocaleTimeString('es-VE', { hour: '2-digit', minute: '2-digit', hour12: true });
    } catch (e) { }
}

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
    } catch (e) { }

    cambiarPlantillaSeleccionada();
    recargarEcosistema();

    // Reloj en vivo y verificación periódica cada 60s de la Hora Programada del Piloto Automático
    setInterval(() => {
        actualizarRelojLocalBarra();
        verificarPilotoAutomaticoDiario();
    }, 60000);
});

// =========================================================================
// SISTEMA DE DIÁLOGOS Y ALERTAS CORPORATIVAS (REEMPLAZO DE ALERT/CONFIRM NATIVO)
// =========================================================================
function mostrarDialogoStarfi({
    badge = 'NOTIFICACIÓN STARFI',
    badgeColor = '#E85B14',
    title = 'Información',
    icon = 'fa-solid fa-bullhorn',
    iconBg = 'rgba(232, 91, 20, 0.2)',
    iconColor = '#FB923C',
    bodyHtml = '',
    confirmText = 'Aceptar',
    confirmColor = '#E85B14',
    cancelText = null
}) {
    return new Promise((resolve) => {
        const modalEl = document.getElementById('modalStarfiDialog');
        if (!modalEl) {
            resolve(true);
            return;
        }

        const badgeEl = document.getElementById('starfiDialogBadge');
        const titleEl = document.getElementById('starfiDialogTitle');
        const iconWrap = document.getElementById('starfiDialogIcon');
        const bodyEl = document.getElementById('starfiDialogBody');
        const btnConfirm = document.getElementById('btnStarfiDialogConfirm');
        const btnCancel = document.getElementById('btnStarfiDialogCancel');
        const btnCloseX = document.getElementById('btnStarfiDialogCloseX');

        badgeEl.textContent = badge;
        badgeEl.style.backgroundColor = badgeColor;
        titleEl.textContent = title;
        iconWrap.style.background = iconBg;
        iconWrap.style.color = iconColor;
        iconWrap.innerHTML = `<i class="${icon}"></i>`;
        bodyEl.innerHTML = bodyHtml;

        btnConfirm.innerHTML = confirmText;
        btnConfirm.style.backgroundColor = confirmColor;
        btnConfirm.style.borderColor = confirmColor;

        if (cancelText) {
            btnCancel.textContent = cancelText;
            btnCancel.classList.remove('d-none');
        } else {
            btnCancel.classList.add('d-none');
        }

        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        let settled = false;
        let pendingResult = false;

        const onHidden = () => {
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            settled = true;
            resolve(pendingResult);
        };

        const finish = (result) => {
            if (settled) return;
            settled = true;
            pendingResult = result;
            if (modalEl.classList.contains('show')) {
                bsModal.hide();
            } else {
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                resolve(result);
            }
        };

        btnConfirm.onclick = () => finish(true);
        btnCancel.onclick = () => finish(false);
        btnCloseX.onclick = () => finish(false);
        modalEl.addEventListener('hidden.bs.modal', onHidden);

        bsModal.show();
    });
}

function mostrarToastStarfi(mensaje, tipo = 'info') {
    const container = document.getElementById('starfiToastContainer');
    if (!container) return;

    const palette = {
        success: { bg: '#065F46', icon: 'fa-circle-check', title: 'Operación Exitosa' },
        warning: { bg: '#9A3412', icon: 'fa-triangle-exclamation', title: 'Atención' },
        danger: { bg: '#991B1B', icon: 'fa-circle-xmark', title: 'Aviso del Sistema' },
        info: { bg: '#1E293B', icon: 'fa-circle-info', title: 'Información' }
    }[tipo] || { bg: '#1E293B', icon: 'fa-circle-info', title: 'Notificación' };

    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-white border-0 shadow-lg mb-2';
    toastEl.style.backgroundColor = palette.bg;
    toastEl.style.borderRadius = '12px';
    toastEl.setAttribute('role', 'alert');
    toastEl.innerHTML = `
        <div class="d-flex p-3 align-items-center">
            <i class="fa-solid ${palette.icon} fs-5 me-3"></i>
            <div class="toast-body p-0">
                <div class="fw-bold" style="font-size: 0.78rem; opacity: 0.85; text-transform: uppercase;">${palette.title}</div>
                <div style="font-size: 0.88rem;">${mensaje}</div>
            </div>
            <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    container.appendChild(toastEl);
    const t = new bootstrap.Toast(toastEl, { delay: 4200 });
    toastEl.addEventListener('hidden.bs.modal', () => toastEl.remove());
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    t.show();
}

function obtenerSedeActualObj() {
    return ECO_STATE.sedes.find(s => parseInt(s.id, 10) === parseInt(ECO_STATE.sedeSeleccionada, 10)) || null;
}

function actualizarEstadoBloqueoYCargaSede(cargando = false) {
    const wrapper = document.getElementById('ecoWorkspaceWrapper');
    const lockOverlay = document.getElementById('overlaySedeBloqueada');
    const loadOverlay = document.getElementById('overlaySedeCargando');
    const spinnerTop = document.getElementById('spinnerSedeSelect');
    const btnFlujo = document.getElementById('btnAbrirFlujoSede');
    const btnRefresh = document.getElementById('btnActualizarMetricasTop');
    const sinSede = parseInt(ECO_STATE.sedeSeleccionada || 0, 10) <= 0;

    if (spinnerTop) {
        spinnerTop.classList.toggle('d-none', !cargando);
    }
    if (loadOverlay) {
        loadOverlay.classList.toggle('d-none', !cargando);
    }
    if (lockOverlay) {
        lockOverlay.classList.toggle('d-none', cargando || !sinSede);
    }
    if (wrapper) {
        wrapper.classList.toggle('sede-locked', cargando || sinSede);
    }
    if (btnFlujo) btnFlujo.disabled = cargando || sinSede;
    if (btnRefresh) btnRefresh.disabled = cargando || sinSede;
}

async function recargarEcosistema(forzarSedeId = null) {
    const sedeParam = forzarSedeId !== null ? forzarSedeId : ECO_STATE.sedeSeleccionada;
    const selTop = document.getElementById('selectSedeActiva');
    if (parseInt(sedeParam, 10) > 0) {
        actualizarEstadoBloqueoYCargaSede(true);
        if (selTop) selTop.disabled = true;
    }
    try {
        const resp = await fetch(`back_campanas.php?action=get_ecosystem_data&id_sede=${sedeParam}`);
        const rawText = await resp.text();
        let data = null;
        try {
            data = JSON.parse(rawText);
        } catch (jsonErr) {
            console.error(`Respuesta no JSON (HTTP ${resp.status}) en recargarEcosistema:`, rawText.slice(0, 200));
            mostrarToastStarfi(`El servidor tardó demasiado en responder o devolvió un error (HTTP ${resp.status}). Intente de nuevo.`, 'danger');
            return;
        }
        if (!data || data.status !== 'success') {
            mostrarToastStarfi((data && data.message) || 'Error al cargar el ecosistema de campañas.', 'danger');
            return;
        }

        ECO_STATE.esGlobal = !!data.es_global;
        ECO_STATE.sedes = data.sedes || [];
        ECO_STATE.sedeSeleccionada = parseInt(data.sede_seleccionada || 0, 10);
        ECO_STATE.etiquetas = data.etiquetas || [];
        ECO_STATE.contactos = data.contactos || [];
        ECO_STATE.campanas = data.campanas || [];

        const selOrigen = document.getElementById('filtroAudienciaOrigen');
        if (selOrigen && !ECO_STATE.esGlobal) {
            const optTodos = selOrigen.querySelector('option[value="TODOS"]');
            if (optTodos) optTodos.remove();
            if (selOrigen.value !== 'SEDE' && selOrigen.value !== 'MANUAL') {
                selOrigen.value = 'SEDE';
            }
        }

        renderizarSelectorSedeYBarraFlujo();
        renderizarKpisGlobales(data.kpis || {});
        renderizarFiltroEtiquetas();
        filtrarAudiencia();
        renderizarTablaCampanas();
        if (ECO_STATE.sedeSeleccionada > 0) {
            await cargarPlantillasDeSede(ECO_STATE.sedeSeleccionada);
            verificarPilotoAutomaticoDiario();
        }
    } catch (err) {
        console.error('Error en recargarEcosistema:', err);
    } finally {
        actualizarEstadoBloqueoYCargaSede(false);
        if (selTop) selTop.disabled = !ECO_STATE.esGlobal;
    }
}

function renderizarSelectorSedeYBarraFlujo() {
    const sel = document.getElementById('selectSedeActiva');
    sel.innerHTML = '';

    if (ECO_STATE.sedes.length === 0) {
        sel.innerHTML = '<option value="0">Sin sedes registradas</option>';
        return;
    }

    if (ECO_STATE.esGlobal) {
        const optPlaceholder = document.createElement('option');
        optPlaceholder.value = '0';
        optPlaceholder.textContent = '-- Seleccione una Sede Emisora --';
        if (ECO_STATE.sedeSeleccionada <= 0) {
            optPlaceholder.selected = true;
        }
        sel.appendChild(optPlaceholder);
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
    sel.disabled = !ECO_STATE.esGlobal;

    const sedeObj = obtenerSedeActualObj();
    const badgeLinea = document.getElementById('badgeLineaSede');
    const txtNum = document.getElementById('txtNumeroSede');
    const lblCuota = document.getElementById('lblCuotaDiariaResumen');
    const barCuota = document.getElementById('barCuotaDiariaSede');
    const lblDisp = document.getElementById('lblDisponiblesHoy');
    const lblCad = document.getElementById('lblCadenciaSede');
    const kpiTarifa = document.getElementById('kpiTarifaUnitariaSede');
    const badgeCostoUnit = document.getElementById('badgeCostoUnitario');
    const previewSedeTitle = document.getElementById('waPreviewSedeTitle');

    if (!sedeObj) {
        badgeLinea.classList.add('offline');
        txtNum.textContent = 'Seleccione una sede para activar...';
        lblCuota.textContent = '0 / 0 hoy';
        barCuota.style.width = '0%';
        lblDisp.textContent = 'Seleccione una sede emisora';
        lblCad.textContent = 'Cadencia: --';
        if (kpiTarifa) kpiTarifa.textContent = '$0.0000';
        if (badgeCostoUnit) badgeCostoUnit.textContent = 'Seleccione sede';
        if (previewSedeTitle) {
            previewSedeTitle.textContent = 'Seleccione una Sede Oficial';
        }
        recalcularPresupuestoCampana();
        return;
    }

    if (previewSedeTitle) {
        previewSedeTitle.textContent = `${sedeObj.nombre_sede} (${sedeObj.numero_telefono})`;
    }

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
    if (kpiTarifa) kpiTarifa.textContent = `$${costoMkt}`;
    if (badgeCostoUnit) badgeCostoUnit.textContent = `Marketing: $${costoMkt} / msg`;

    const inpCuota = document.getElementById('campCuotaDiariaProg');
    if (inpCuota) {
        inpCuota.max = lim;
        if (!inpCuota.dataset.touched || parseInt(inpCuota.value || 0, 10) > lim) {
            inpCuota.value = lim;
        }
    }

    recalcularPresupuestoCampana();
}

function cambiarSedeActiva() {
    const sel = document.getElementById('selectSedeActiva');
    const nuevaSede = parseInt(sel.value, 10) || 0;
    ECO_STATE.sedeSeleccionada = nuevaSede;
    ECO_STATE.paginaHistorial = 1;
    ECO_STATE.seleccionadosIds.clear();

    const lblLoading = document.getElementById('lblTituloCargandoSede');
    if (lblLoading && sel && sel.selectedIndex >= 0 && nuevaSede > 0) {
        const txtOpt = sel.options[sel.selectedIndex].textContent || 'sede seleccionada';
        lblLoading.textContent = `Cargando ${txtOpt}...`;
    }

    if (nuevaSede <= 0) {
        ECO_STATE.contactos = [];
        ECO_STATE.campanas = [];
        renderizarSelectorSedeYBarraFlujo();
        renderizarKpisGlobales({});
        filtrarAudiencia();
        renderizarTablaCampanas();
        actualizarEstadoBloqueoYCargaSede(false);
        return;
    }

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
            cambiarPlantillaSeleccionada();
        }
    } catch (e) {
        console.error('Error cargando plantillas de la sede:', e);
    }
}

function obtenerPlantillaPorDefectoStarfi() {
    return {
        name: 'starfi_difusion_directa',
        language: 'es',
        category: 'MARKETING',
        status: 'APPROVED',
        header_format: 'IMAGE',
        header_text: '',
        header_examples: [],
        body: "👋¡Hola {{1}}!\nHoy queremos {{2}}.\nRecuerda que en {{3}}.\n📲 *Responde a este mensaje para obtener más información*👇",
        body_examples: [
            'Carlos',
            'invitarte este 16 de noviembre a la GRAN INAUGURACIÓN de nuestra nueva sede CARPINTERO EXPRESS BOLEÍTA 🎉🪵 Ven a disfrutar de grandes ofertas especiales de apertura, sorpresas y todo lo que necesitas para tus proyectos',
            'Carpintero Express estamos cada día más cerca de ti y somos tu parada obligatoria'
        ],
        footer: 'Servicio de mensajería inteligente de STARFI CRM',
        buttons: []
    };
}

function obtenerPlantillaSeleccionadaObj() {
    const sel = document.getElementById('campTemplate');
    const tplName = sel ? sel.value : 'starfi_difusion_directa';
    const optSel = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
    const tplLang = optSel?.dataset?.lang || '';

    if (Array.isArray(ECO_STATE.templatesSede) && ECO_STATE.templatesSede.length > 0) {
        let found = ECO_STATE.templatesSede.find(t => t.name === tplName && (!tplLang || t.language === tplLang));
        if (!found) {
            found = ECO_STATE.templatesSede.find(t => t.name === tplName);
        }
        if (found) return found;
    }
    return obtenerPlantillaPorDefectoStarfi();
}

function extraerVariablesDeTexto(texto) {
    if (!texto) return [];
    const regex = /\{\{(\d+)\}\}/g;
    const nums = new Set();
    let m;
    while ((m = regex.exec(String(texto))) !== null) {
        const n = parseInt(m[1], 10);
        if (!isNaN(n) && n > 0) nums.add(n);
    }
    return Array.from(nums).sort((a, b) => a - b);
}

function extraerContextoVariable(textoCuerpo, numVar) {
    if (!textoCuerpo) return `Parámetro {{${numVar}}}`;
    const token = `{{${numVar}}}`;
    const lineas = String(textoCuerpo).split(/\r?\n/);
    for (const lin of lineas) {
        if (lin.includes(token)) {
            let clean = lin.replace(/[*_~]/g, '').trim();
            if (clean.length > 56) {
                const pos = clean.indexOf(token);
                const start = Math.max(0, pos - 22);
                const end = Math.min(clean.length, pos + token.length + 24);
                clean = (start > 0 ? '…' : '') + clean.substring(start, end) + (end < clean.length ? '…' : '');
            }
            return `"${clean}"`;
        }
    }
    return `Parámetro {{${numVar}}}`;
}

function esVariableSaludoCliente(textoCuerpo, numVar, nombrePlantilla) {
    if (nombrePlantilla === 'starfi_difusion_directa' && numVar === 1) return true;
    if (numVar !== 1) return false;
    const token = `{{1}}`;
    const pos = String(textoCuerpo || '').indexOf(token);
    if (pos === -1) return false;
    const previo = String(textoCuerpo).substring(Math.max(0, pos - 35), pos).toLowerCase();
    return /(hola|estimad|saludos|buen|cliente|sr|sra|amigo|👋)/i.test(previo);
}

function obtenerEstadoValoresPlantilla(tplObj) {
    const key = `${tplObj.name}__${tplObj.language || 'es'}`;
    if (!ECO_STATE.valoresPorPlantilla[key]) {
        // Si es starfi_difusion_directa, clonar valores por defecto
        if (tplObj.name === 'starfi_difusion_directa' && ECO_STATE.valoresPorPlantilla['starfi_difusion_directa']) {
            ECO_STATE.valoresPorPlantilla[key] = JSON.parse(JSON.stringify(ECO_STATE.valoresPorPlantilla['starfi_difusion_directa']));
        } else {
            const vars = extraerVariablesDeTexto(tplObj.body || '');
            const bodyVars = {};
            const autoClienteVars = {};
            vars.forEach(n => {
                if (esVariableSaludoCliente(tplObj.body || '', n, tplObj.name)) {
                    bodyVars[n] = '@NOMBRE_CLIENTE';
                    autoClienteVars[n] = true;
                } else {
                    bodyVars[n] = '';
                    autoClienteVars[n] = false;
                }
            });
            ECO_STATE.valoresPorPlantilla[key] = {
                bodyVars,
                autoClienteVars,
                headerTextVar: ''
            };
        }
    }
    return ECO_STATE.valoresPorPlantilla[key];
}

function cambiarPlantillaSeleccionada() {
    const tpl = obtenerPlantillaSeleccionadaObj();
    const stateTpl = obtenerEstadoValoresPlantilla(tpl);
    const varsBody = extraerVariablesDeTexto(tpl.body || '');
    const headerFmt = String(tpl.header_format || 'NONE').toUpperCase();
    const varsHeader = headerFmt === 'TEXT' ? extraerVariablesDeTexto(tpl.header_text || '') : [];
    const cat = String(tpl.category || 'MARKETING').toUpperCase();
    const lang = String(tpl.language || 'es').toUpperCase();

    // 1. Actualizar píldoras de especificación de la plantilla
    const pillsCont = document.getElementById('pillsEspecificacionPlantilla');
    const btnEjemplo = document.getElementById('btnRellenarEjemploPlantilla');
    const sedeObj = obtenerSedeActualObj();
    const tarifaUnit = sedeObj
        ? parseFloat(cat === 'UTILITY' ? (sedeObj.costo_utility_usd || 0.0165) : (sedeObj.costo_marketing_usd || 0.0770)).toFixed(4)
        : '0.0000';

    if (pillsCont) {
        const badgeCatClass = cat === 'UTILITY'
            ? 'bg-info-subtle text-info-emphasis border-info-subtle'
            : 'bg-warning-subtle text-dark border-warning-subtle';
        let labelHeader = 'Sin cabecera';
        let iconHeader = 'fa-align-left';
        if (headerFmt === 'IMAGE') { labelHeader = 'Cabecera: Imagen/Flyer'; iconHeader = 'fa-image text-starfi-orange'; }
        else if (headerFmt === 'TEXT') { labelHeader = 'Cabecera: Texto'; iconHeader = 'fa-heading text-primary'; }
        else if (headerFmt === 'DOCUMENT') { labelHeader = 'Cabecera: Documento'; iconHeader = 'fa-file-pdf text-danger'; }
        else if (headerFmt === 'VIDEO') { labelHeader = 'Cabecera: Video'; iconHeader = 'fa-video text-danger'; }

        pillsCont.innerHTML = `
            <span class="badge ${badgeCatClass} border" style="font-size:0.68rem;">${escapeHtml(cat)} ($${tarifaUnit})</span>
            <span class="badge bg-white text-secondary border" style="font-size:0.68rem;"><i class="fa-solid fa-globe me-1"></i>${escapeHtml(lang)}</span>
            <span class="badge bg-white text-secondary border" style="font-size:0.68rem;"><i class="fa-solid ${iconHeader} me-1"></i>${labelHeader}</span>
            <span class="badge bg-white text-dark border" style="font-size:0.68rem;"><i class="fa-solid fa-code text-primary me-1"></i>${varsBody.length === 0 ? 'Texto fijo (0 vars)' : `${varsBody.length} ${varsBody.length === 1 ? 'variable' : 'variables'}`}</span>
        `;
    }

    if (btnEjemplo) {
        const tieneEjemplos = (Array.isArray(tpl.body_examples) && tpl.body_examples.length > 0 && varsBody.length > 0) || tpl.name === 'starfi_difusion_directa';
        btnEjemplo.classList.toggle('d-none', !tieneEjemplos);
    }

    // 2. Mostrar/Ocultar sección de Cabecera (Imagen vs Texto vs Sin Cabecera)
    const boxImg = document.getElementById('boxCampHeaderImagen');
    const boxTxtHeader = document.getElementById('boxCampHeaderTexto');

    if (boxImg) {
        boxImg.classList.toggle('d-none', headerFmt !== 'IMAGE');
    }
    if (boxTxtHeader) {
        if (headerFmt === 'TEXT' && tpl.header_text) {
            boxTxtHeader.classList.remove('d-none');
            if (varsHeader.length > 0) {
                const ejHdr = (Array.isArray(tpl.header_examples) && tpl.header_examples[0]) ? tpl.header_examples[0] : 'Valor de cabecera';
                boxTxtHeader.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold text-dark mb-0">
                            <i class="fa-solid fa-heading text-primary me-1"></i>Variable de Cabecera <code>{{1}}</code> — "${escapeHtml(tpl.header_text)}" *
                        </label>
                    </div>
                    <input type="text" id="campHeaderTextParam" class="form-control form-control-sm"
                        placeholder="Ej: ${escapeHtml(ejHdr)}"
                        value="${escapeHtml(stateTpl.headerTextVar || '')}"
                        oninput="onInputHeaderTextDinamica(this.value)">
                `;
            } else {
                boxTxtHeader.innerHTML = `
                    <div class="small text-secondary">
                        <i class="fa-solid fa-heading text-primary me-1"></i>Cabecera fija de la plantilla: <strong class="text-dark">${escapeHtml(tpl.header_text)}</strong>
                    </div>
                `;
            }
        } else {
            boxTxtHeader.classList.add('d-none');
            boxTxtHeader.innerHTML = '';
        }
    }

    // 3. Renderizar dinámicamente los campos de variables solicitados por el BODY de la plantilla
    renderizarCamposVariablesPlantilla(tpl, stateTpl, varsBody);

    // 4. Actualizar simulador en vivo y costos según la categoría de la plantilla
    actualizarVistaPreviaCampana();
    recalcularPresupuestoCampana();
}

function renderizarCamposVariablesPlantilla(tpl, stateTpl, varsBody) {
    const cont = document.getElementById('contenedorVariablesPlantilla');
    if (!cont) return;

    if (varsBody.length === 0) {
        cont.innerHTML = `
            <div class="mb-3 p-3 rounded-3 border bg-success-subtle border-success-subtle text-success-emphasis small">
                <div class="fw-bold mb-1"><i class="fa-solid fa-circle-check me-1"></i>Plantilla de Contenido Fijo</div>
                <div>Esta plantilla aprobada en Meta no solicita rellenar variables adicionales. Se enviará tal como se muestra en la vista previa central.</div>
            </div>
        `;
        return;
    }

    let html = '';
    varsBody.forEach(numVar => {
        const ctxLabel = extraerContextoVariable(tpl.body || '', numVar);
        const isAutoCliente = !!stateTpl.autoClienteVars[numVar];
        const valActual = stateTpl.bodyVars[numVar] ?? '';
        const ejemploMeta = (Array.isArray(tpl.body_examples) && tpl.body_examples[numVar - 1])
            ? String(tpl.body_examples[numVar - 1])
            : '';
        const placeholderTxt = ejemploMeta
            ? `Ej: ${ejemploMeta}`
            : `Ingrese el valor para la variable {{${numVar}}}...`;

        const usarTextarea = (!isAutoCliente && (valActual.length > 68 || ejemploMeta.length > 68 || (tpl.name === 'starfi_difusion_directa' && numVar === 2)));

        html += `
            <div class="mb-3 p-2.5 px-3 rounded-3 border" style="background: #FFFFFF; border-color: #E2E8F0 !important;">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1.5">
                    <label class="form-label small fw-bold text-secondary mb-0" for="campDynamicVar_${numVar}">
                        Variable <code>{{${numVar}}}</code> — <span class="text-dark">${escapeHtml(ctxLabel)}</span> *
                    </label>
                    <div class="d-inline-flex align-items-center gap-1">
                        <button type="button" class="btn btn-sm py-0 px-2 ${isAutoCliente ? 'btn-success text-white fw-bold' : 'btn-outline-secondary'}" style="font-size: 0.68rem; border-radius: 6px;" title="Insertar automáticamente el nombre de cada contacto destinatario" onclick="setModoVariablePlantilla(${numVar}, 'AUTO_CLIENTE')">
                            <i class="fa-solid fa-user-tag me-1"></i>Nombre Cliente
                        </button>
                        <button type="button" class="btn btn-sm py-0 px-2 btn-outline-secondary" style="font-size: 0.68rem; border-radius: 6px;" title="Insertar el nombre de la Sede activa" onclick="setModoVariablePlantilla(${numVar}, 'INSERT_SEDE')">
                            <i class="fa-solid fa-store me-1"></i>Sede
                        </button>
                        <button type="button" class="btn btn-sm py-0 px-2 ${!isAutoCliente ? 'btn-dark text-white fw-bold' : 'btn-outline-secondary'}" style="font-size: 0.68rem; border-radius: 6px;" title="Escribir un texto fijo personalizado para esta variable" onclick="setModoVariablePlantilla(${numVar}, 'TEXTO_FIJO')">
                            <i class="fa-solid fa-pen me-1"></i>Texto
                        </button>
                    </div>
                </div>
                ${isAutoCliente ? `
                    <div class="p-2 px-3 rounded-2 d-flex align-items-center justify-content-between" style="background: #ECFDF5; border: 1px dashed #6EE7B7; font-size: 0.78rem; color: #065F46;">
                        <span><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Dinámico: Se enviará el <strong>primer nombre de cada contacto</strong> (Ej: <em>"Carlos"</em>).</span>
                        <span class="badge bg-success text-white" style="font-size: 0.65rem;">Automático</span>
                    </div>
                ` : (usarTextarea ? `
                    <textarea id="campDynamicVar_${numVar}" class="form-control form-control-sm mt-1" rows="2"
                        placeholder="${escapeHtml(placeholderTxt)}"
                        oninput="onInputVariableDinamica(${numVar}, this.value)">${escapeHtml(valActual)}</textarea>
                ` : `
                    <input type="text" id="campDynamicVar_${numVar}" class="form-control form-control-sm mt-1"
                        placeholder="${escapeHtml(placeholderTxt)}"
                        value="${escapeHtml(valActual)}"
                        oninput="onInputVariableDinamica(${numVar}, this.value)">
                `)}
            </div>
        `;
    });

    cont.innerHTML = html;
}

function setModoVariablePlantilla(numVar, modo) {
    const tpl = obtenerPlantillaSeleccionadaObj();
    const stateTpl = obtenerEstadoValoresPlantilla(tpl);
    const varsBody = extraerVariablesDeTexto(tpl.body || '');

    if (modo === 'AUTO_CLIENTE') {
        stateTpl.autoClienteVars[numVar] = true;
        stateTpl.bodyVars[numVar] = '@NOMBRE_CLIENTE';
    } else if (modo === 'INSERT_SEDE') {
        const sedeObj = obtenerSedeActualObj();
        const nomSede = sedeObj ? sedeObj.nombre_sede : '@NOMBRE_SEDE';
        stateTpl.autoClienteVars[numVar] = false;
        const prev = (stateTpl.bodyVars[numVar] === '@NOMBRE_CLIENTE') ? '' : (stateTpl.bodyVars[numVar] || '');
        stateTpl.bodyVars[numVar] = prev ? `${prev} ${nomSede}` : nomSede;
    } else {
        stateTpl.autoClienteVars[numVar] = false;
        if (stateTpl.bodyVars[numVar] === '@NOMBRE_CLIENTE') {
            stateTpl.bodyVars[numVar] = '';
        }
    }

    renderizarCamposVariablesPlantilla(tpl, stateTpl, varsBody);
    if (modo !== 'AUTO_CLIENTE') {
        const inp = document.getElementById(`campDynamicVar_${numVar}`);
        if (inp) inp.focus();
    }
    actualizarVistaPreviaCampana();
}

function onInputVariableDinamica(numVar, valor) {
    const tpl = obtenerPlantillaSeleccionadaObj();
    const stateTpl = obtenerEstadoValoresPlantilla(tpl);
    stateTpl.autoClienteVars[numVar] = false;
    stateTpl.bodyVars[numVar] = valor;
    actualizarVistaPreviaCampana();
}

function onInputHeaderTextDinamica(valor) {
    const tpl = obtenerPlantillaSeleccionadaObj();
    const stateTpl = obtenerEstadoValoresPlantilla(tpl);
    stateTpl.headerTextVar = valor;
    actualizarVistaPreviaCampana();
}

function autocompletarEjemploPlantillaActiva() {
    const tpl = obtenerPlantillaSeleccionadaObj();
    const stateTpl = obtenerEstadoValoresPlantilla(tpl);
    const varsBody = extraerVariablesDeTexto(tpl.body || '');

    varsBody.forEach(numVar => {
        if (esVariableSaludoCliente(tpl.body || '', numVar, tpl.name)) {
            stateTpl.autoClienteVars[numVar] = true;
            stateTpl.bodyVars[numVar] = '@NOMBRE_CLIENTE';
        } else {
            stateTpl.autoClienteVars[numVar] = false;
            const ej = (Array.isArray(tpl.body_examples) && tpl.body_examples[numVar - 1])
                ? String(tpl.body_examples[numVar - 1])
                : `Valor de ejemplo ${numVar}`;
            stateTpl.bodyVars[numVar] = ej;
        }
    });

    if (String(tpl.header_format || '').toUpperCase() === 'TEXT' && Array.isArray(tpl.header_examples) && tpl.header_examples[0]) {
        stateTpl.headerTextVar = String(tpl.header_examples[0]);
        const inpH = document.getElementById('campHeaderTextParam');
        if (inpH) inpH.value = stateTpl.headerTextVar;
    }

    renderizarCamposVariablesPlantilla(tpl, stateTpl, varsBody);
    actualizarVistaPreviaCampana();
    mostrarToastStarfi('Variables completadas con el ejemplo de la plantilla.', 'info');
}

function validarVariablesPlantillaActiva(mostrarAlerta = true) {
    const tpl = obtenerPlantillaSeleccionadaObj();
    const stateTpl = obtenerEstadoValoresPlantilla(tpl);
    const varsBody = extraerVariablesDeTexto(tpl.body || '');
    const headerFmt = String(tpl.header_format || 'NONE').toUpperCase();
    const varsHeader = headerFmt === 'TEXT' ? extraerVariablesDeTexto(tpl.header_text || '') : [];

    if (varsHeader.length > 0) {
        const valH = String(stateTpl.headerTextVar || '').trim();
        if (!valH) {
            if (mostrarAlerta) {
                mostrarToastStarfi('Por favor complete la Variable {{1}} de la cabecera de la plantilla.', 'warning');
                document.getElementById('campHeaderTextParam')?.focus();
            }
            return { ok: false };
        }
    }

    for (const numVar of varsBody) {
        const isAuto = !!stateTpl.autoClienteVars[numVar];
        const val = isAuto ? '@NOMBRE_CLIENTE' : String(stateTpl.bodyVars[numVar] || '').trim();
        if (!val) {
            if (mostrarAlerta) {
                mostrarToastStarfi(`Por favor complete la Variable {{${numVar}}} solicitada por la plantilla "${tpl.name}".`, 'warning');
                document.getElementById(`campDynamicVar_${numVar}`)?.focus();
            }
            return { ok: false };
        }
    }

    return { ok: true };
}

function obtenerPayloadVariablesPlantillaActiva() {
    const tpl = obtenerPlantillaSeleccionadaObj();
    const stateTpl = obtenerEstadoValoresPlantilla(tpl);
    const varsBody = extraerVariablesDeTexto(tpl.body || '');
    const headerFmt = String(tpl.header_format || 'NONE').toUpperCase();
    const varsHeader = headerFmt === 'TEXT' ? extraerVariablesDeTexto(tpl.header_text || '') : [];

    const bodyParams = varsBody.map(numVar => {
        if (stateTpl.autoClienteVars[numVar]) return '@NOMBRE_CLIENTE';
        return String(stateTpl.bodyVars[numVar] || '').trim();
    });

    return {
        template_name: tpl.name,
        template_language: tpl.language || 'es',
        template_category: String(tpl.category || 'MARKETING').toUpperCase(),
        header_format: headerFmt,
        header_has_var: varsHeader.length > 0,
        header_text_param: varsHeader.length > 0 ? String(stateTpl.headerTextVar || '').trim() : '',
        body_params: bodyParams,
        body_template: tpl.body || '',
        header_text_template: tpl.header_text || '',
        footer_text: tpl.footer || ''
    };
}

function renderizarKpisGlobales(kpis) {
    const elEnv = document.getElementById('kpiTotalEnviados');
    if (!elEnv) return;
    elEnv.textContent = kpis.total_enviados || 0;
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
    const antiFatiga = document.getElementById('chkFiltroAntiFatiga')?.checked ?? true;

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
    let omitidosFatiga = 0;

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
        if (antiFatiga && c.fatiga_24h) {
            omitidosFatiga++;
            ECO_STATE.seleccionadosIds.delete(c.id);
            return false;
        }
        return true;
    });

    document.getElementById('lblCountVisibles').textContent = ECO_STATE.contactosVisibles.length;
    const lblFat = document.getElementById('lblOmitidosFatiga');
    if (lblFat) {
        lblFat.innerHTML = omitidosFatiga > 0
            ? `<i class="fa-solid fa-shield-heart text-success me-1"></i>${omitidosFatiga} protegidos (24h)`
            : '';
    }
    renderizarListaContactosCheck();
    recalcularPresupuestoCampana();
}

function aplicarSegmentoRapido(tipo) {
    const selOrigen = document.getElementById('filtroAudienciaOrigen');
    if (selOrigen && selOrigen.value === 'MANUAL') {
        selOrigen.value = 'SEDE';
        filtrarAudiencia();
    }
    if (tipo === 'LIMPIAR') {
        ECO_STATE.seleccionadosIds.clear();
        const chkAll = document.getElementById('chkSelectAllAudience');
        if (chkAll) chkAll.checked = false;
        const desg = document.getElementById('desgloseCuotaPorSede');
        if (desg) {
            desg.classList.add('d-none');
            desg.innerHTML = '';
        }
        const badgeC = document.getElementById('badgeResumenCuotaPorSede');
        if (badgeC) badgeC.textContent = 'Multisede desde línea activa';
        renderizarListaContactosCheck();
        recalcularPresupuestoCampana();
        return;
    }
    if (tipo === 'CARTERA_GLOBAL') {
        seleccionarCuotaPorCadaSede('TODOS');
        return;
    }
    if (tipo === 'TODOS_SEDE') {
        if (selOrigen) selOrigen.value = 'SEDE';
        filtrarAudiencia();
        ECO_STATE.seleccionadosIds.clear();
        ECO_STATE.contactosVisibles.forEach(c => ECO_STATE.seleccionadosIds.add(c.id));
        const chkAll = document.getElementById('chkSelectAllAudience');
        if (chkAll) chkAll.checked = true;
        const desg = document.getElementById('desgloseCuotaPorSede');
        if (desg) desg.classList.add('d-none');
        const badgeStep3 = document.getElementById('badgeStep3AlcanceSedes');
        if (badgeStep3) badgeStep3.textContent = 'Solo Sede Activa';
        renderizarListaContactosCheck();
        recalcularPresupuestoCampana();
        mostrarToastStarfi(`Seleccionados ${ECO_STATE.seleccionadosIds.size} contactos de la sede.`, 'info');
        return;
    }
    if (tipo === 'RECIENTES_30D') {
        const hace30d = Date.now() - (30 * 24 * 60 * 60 * 1000);
        ECO_STATE.seleccionadosIds.clear();
        ECO_STATE.contactosVisibles.forEach(c => {
            if (c.ultima_interaccion) {
                const ts = new Date(c.ultima_interaccion).getTime();
                if (!isNaN(ts) && ts >= hace30d) {
                    ECO_STATE.seleccionadosIds.add(c.id);
                }
            }
        });
        renderizarListaContactosCheck();
        recalcularPresupuestoCampana();
        mostrarToastStarfi(`Seleccionados ${ECO_STATE.seleccionadosIds.size} contactos activos en los últimos 30 días.`, 'info');
    }
}

function renderizarListaContactosCheck() {
    const cont = document.getElementById('listaContactosCheck');
    if (ECO_STATE.contactosVisibles.length === 0) {
        const msgVacio = ECO_STATE.esGlobal
            ? 'No se encontraron contactos con este filtro para la sede seleccionada. Pruebe cambiando a "Todo el Directorio 360" o ingresando una lista manual.'
            : 'No se encontraron contactos registrados para tu sede con este filtro. Puedes agregarlos desde el Directorio o usar "Ingresar lista manual (CSV/Texto)".';
        cont.innerHTML = `<div class="p-4 text-center text-muted small">${msgVacio}</div>`;
        return;
    }

    const origenActual = document.getElementById('filtroAudienciaOrigen')?.value || 'SEDE';
    const mapaSedesNombres = {};
    (ECO_STATE.sedes || []).forEach(s => {
        mapaSedesNombres[parseInt(s.id, 10)] = s.nombre_sede;
    });

    const MAX_RENDER = 500;
    const subset = ECO_STATE.contactosVisibles.slice(0, MAX_RENDER);
    let html = '';
    subset.forEach(c => {
        const checked = ECO_STATE.seleccionadosIds.has(c.id) ? 'checked' : '';
        const tagsBadges = (c.etiquetas || []).slice(0, 2).map(t => `<span class="badge bg-light text-secondary border ms-1" style="font-size:0.68rem;">${escapeHtml(t)}</span>`).join('');
        let sedeBadgeHtml = '';
        if (origenActual === 'TODOS') {
            const idSedeCli = parseInt(c.id_sede_primaria || (Array.isArray(c.sedes_ids) && c.sedes_ids.length > 0 ? c.sedes_ids[0] : 0), 10);
            const nomSedeCli = mapaSedesNombres[idSedeCli] || (idSedeCli > 0 ? `Sede #${idSedeCli}` : 'Sin sede');
            sedeBadgeHtml = `<span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1" style="font-size:0.65rem;"><i class="fa-solid fa-store me-1"></i>${escapeHtml(nomSedeCli)}</span>`;
        }
        html += `
            <label class="recipient-item m-0">
                <div class="d-flex align-items-center gap-2">
                    <input type="checkbox" class="form-check-input mt-0" value="${c.id}" ${checked} onchange="toggleContactoIndividual(${c.id}, this.checked)">
                    <div>
                        <div class="fw-semibold text-dark">${escapeHtml(c.nombre)} ${sedeBadgeHtml} ${tagsBadges}</div>
                        <div class="text-muted" style="font-size: 0.76rem;"><i class="fa-brands fa-whatsapp text-success me-1"></i>${escapeHtml(c.telefono)}</div>
                    </div>
                </div>
            </label>
        `;
    });
    if (ECO_STATE.contactosVisibles.length > MAX_RENDER) {
        html += `<div class="p-2 text-center bg-light text-muted small border-top">Mostrando los primeros ${MAX_RENDER} de ${ECO_STATE.contactosVisibles.length} contactos. Use el buscador o los selectores de cuota para operar sobre la lista filtrada.</div>`;
    }
    cont.innerHTML = html;
}

function seleccionarCuotaPorCadaSede(cuotaInput) {
    if (!ECO_STATE.esGlobal) return;

    const selOrigen = document.getElementById('filtroAudienciaOrigen');
    if (selOrigen && selOrigen.value !== 'TODOS') {
        selOrigen.value = 'TODOS';
        filtrarAudiencia();
    }

    const esCarteraCompleta = (cuotaInput === 'TODOS' || cuotaInput === 0 || cuotaInput === '0');
    const cuotaPorSede = esCarteraCompleta ? Infinity : Math.max(1, parseInt(cuotaInput, 10) || 100);

    if (!esCarteraCompleta) {
        const inpCustom = document.getElementById('inpCuotaPersonalizadaPorSede');
        if (inpCustom) inpCustom.value = cuotaPorSede;
        const inpStep3 = document.getElementById('inpStep3CuotaPorSede');
        if (inpStep3) inpStep3.value = cuotaPorSede;
    }

    const mapaSedesNombres = {};
    (ECO_STATE.sedes || []).forEach(s => {
        mapaSedesNombres[parseInt(s.id, 10)] = s.nombre_sede;
    });

    const conteoPorSede = {};
    ECO_STATE.seleccionadosIds.clear();

    // Iterar sobre los contactos visibles (que ya respetan búsqueda, etiqueta y Escudo Anti-Fatiga 24h)
    ECO_STATE.contactosVisibles.forEach(c => {
        const idSedePrim = parseInt(c.id_sede_primaria || (Array.isArray(c.sedes_ids) && c.sedes_ids.length > 0 ? c.sedes_ids[0] : 0), 10);
        const keySede = idSedePrim > 0 ? idSedePrim : 0;
        if (!conteoPorSede[keySede]) {
            conteoPorSede[keySede] = 0;
        }
        if (conteoPorSede[keySede] < cuotaPorSede) {
            ECO_STATE.seleccionadosIds.add(c.id);
            conteoPorSede[keySede]++;
        }
    });

    const sedesConSeleccion = Object.keys(conteoPorSede).filter(k => conteoPorSede[k] > 0);
    const totalSel = ECO_STATE.seleccionadosIds.size;

    const badgeResumen = document.getElementById('badgeResumenCuotaPorSede');
    if (badgeResumen) {
        badgeResumen.textContent = esCarteraCompleta
            ? `Cartera Completa: ${totalSel} contactos (${sedesConSeleccion.length} sedes)`
            : `Cuota ${cuotaPorSede}/sede: ${totalSel} contactos (${sedesConSeleccion.length} sedes)`;
    }
    const badgeStep3 = document.getElementById('badgeStep3AlcanceSedes');
    if (badgeStep3) {
        badgeStep3.textContent = esCarteraCompleta
            ? `Cartera Global (${sedesConSeleccion.length} sedes)`
            : `Cuota ${cuotaPorSede}/sede (${sedesConSeleccion.length} sedes)`;
    }

    const boxDesglose = document.getElementById('desgloseCuotaPorSede');
    if (boxDesglose) {
        if (sedesConSeleccion.length > 0) {
            const pills = sedesConSeleccion.map(k => {
                const idS = parseInt(k, 10);
                const nom = mapaSedesNombres[idS] || (idS > 0 ? `Sede #${idS}` : 'General');
                return `<span class="badge bg-white text-dark border me-1 mb-1" style="font-size:0.66rem;"><strong>${escapeHtml(nom)}:</strong> ${conteoPorSede[k]}</span>`;
            }).join('');
            boxDesglose.innerHTML = `<div class="mt-1 pt-1 border-top border-primary-subtle">${pills}</div>`;
            boxDesglose.classList.remove('d-none');
        } else {
            boxDesglose.classList.add('d-none');
            boxDesglose.innerHTML = '';
        }
    }

    const chkAll = document.getElementById('chkSelectAllAudience');
    if (chkAll) {
        chkAll.checked = (totalSel > 0 && totalSel === ECO_STATE.contactosVisibles.length);
    }

    renderizarListaContactosCheck();
    recalcularPresupuestoCampana();

    if (esCarteraCompleta) {
        mostrarToastStarfi(`Cartera Global seleccionada: <strong>${totalSel} contactos</strong> de <strong>${sedesConSeleccion.length} sedes</strong> listos para enviarse desde la línea activa.`, 'info');
    } else {
        mostrarToastStarfi(`Seleccionados hasta <strong>${cuotaPorSede} contactos por sede</strong> (Total: <strong>${totalSel} contactos</strong> en ${sedesConSeleccion.length} sedes).`, 'info');
    }
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
        mostrarToastStarfi('La sede ya alcanzó su límite diario de mensajes hoy.', 'warning');
        return;
    }
    ECO_STATE.seleccionadosIds.clear();
    ECO_STATE.contactosVisibles.slice(0, disp).forEach(c => {
        ECO_STATE.seleccionadosIds.add(c.id);
    });
    renderizarListaContactosCheck();
    recalcularPresupuestoCampana();
    mostrarToastStarfi(`Se seleccionaron ${Math.min(disp, ECO_STATE.contactosVisibles.length)} contactos ajustados a la cuota disponible hoy.`, 'info');
}

function obtenerDestinatariosSeleccionadosArray() {
    const origen = document.getElementById('filtroAudienciaOrigen').value;
    if (origen === 'MANUAL') {
        const raw = document.getElementById('txtAudienciaManual').value || '';
        const lines = raw.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
        const result = [];
        const seenTels = new Set();
        let duplicados = 0;
        lines.forEach(line => {
            let nombre = 'Cliente';
            let tel = '';
            if (line.includes(',')) {
                const parts = line.split(',');
                nombre = parts[0].trim() || 'Cliente';
                tel = parts.slice(1).join('').replace(/[^0-9]/g, '');
            } else {
                tel = line.replace(/[^0-9]/g, '');
            }
            if (tel.length >= 8) {
                let norm = tel;
                if (norm.length === 11 && norm.startsWith('0')) norm = '58' + norm.substring(1);
                else if (norm.length === 10 && /^(412|414|424|416|426)/.test(norm)) norm = '58' + norm;
                if (seenTels.has(norm)) {
                    duplicados++;
                } else {
                    seenTels.add(norm);
                    result.push({ id_cliente: 0, nombre, telefono: norm });
                }
            }
        });
        const badgeMan = document.getElementById('badgeResumenManual');
        if (badgeMan) {
            badgeMan.textContent = `${result.length} válidos${duplicados > 0 ? ` • ${duplicados} duplicados omitidos` : ''}`;
        }
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
    const lblStep2 = document.getElementById('lblStep2Count');
    if (lblStep2) lblStep2.textContent = totalSel;

    const sedeObj = obtenerSedeActualObj();
    const tplObj = obtenerPlantillaSeleccionadaObj();
    const esUtility = String(tplObj.category || 'MARKETING').toUpperCase() === 'UTILITY';
    const costoUnit = sedeObj
        ? parseFloat(esUtility ? (sedeObj.costo_utility_usd || 0.0165) : (sedeObj.costo_marketing_usd || 0.07))
        : 0.07;
    const limSede = sedeObj ? parseInt(sedeObj.limite_diario_mensajes || 250, 10) : 250;
    const dispHoySede = sedeObj ? parseInt(sedeObj.disponibles_hoy || 250, 10) : 250;

    const badgeCostoUnit = document.getElementById('badgeCostoUnitario');
    if (badgeCostoUnit && sedeObj) {
        badgeCostoUnit.textContent = `${esUtility ? 'Utility' : 'Marketing'}: $${costoUnit.toFixed(4)} / msg`;
    }

    const inpCuota = document.getElementById('campCuotaDiariaProg');
    let cuotaDiariaProg = inpCuota ? parseInt(inpCuota.value || limSede, 10) : limSede;
    if (isNaN(cuotaDiariaProg) || cuotaDiariaProg < 1) cuotaDiariaProg = 1;
    if (cuotaDiariaProg > 100000) cuotaDiariaProg = 100000;
    const dispEfectivoHoy = Math.max(dispHoySede, cuotaDiariaProg);

    const pilotoAuto = document.getElementById('campPilotoAutomatico')?.checked ?? true;
    const horaProgInput = (document.getElementById('campHoraProgramada')?.value || '09:00').trim();
    const horaProg12 = formatearHora12h(horaProgInput);
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

    // Calcular desglose de tandas diarias proyectadas
    const cupoTanda1 = Math.min(cuotaDiariaProg, dispEfectivoHoy);
    let restantes = efectivos;
    const tandas = [];
    let diaOffset = 0;
    let numTanda = 1;

    if (efectivos > 0) {
        if (cupoTanda1 > 0) {
            const c1 = Math.min(restantes, cupoTanda1);
            tandas.push({ tanda: numTanda++, diaOffset: 0, cantidad: c1, costo: (c1 * costoUnit).toFixed(2) });
            restantes -= c1;
            diaOffset = 1;
        } else {
            diaOffset = 1;
        }
        while (restantes > 0) {
            const ct = Math.min(restantes, cuotaDiariaProg);
            tandas.push({ tanda: numTanda++, diaOffset: diaOffset++, cantidad: ct, costo: (ct * costoUnit).toFixed(2) });
            restantes -= ct;
        }
    }

    const totalTandas = Math.max(1, tandas.length);
    const lblTandas = document.getElementById('lblTotalTandasCampana');
    if (lblTandas) {
        lblTandas.textContent = efectivos === 0 ? '0 tandas' : `${totalTandas} ${totalTandas === 1 ? 'tanda (1 día)' : `tandas (${totalTandas} días)`}`;
    }

    const lblResumen = document.getElementById('lblResumenFlujoYPresupuesto');
    if (totalSel === 0) {
        lblResumen.innerHTML = `Seleccione destinatarios del Directorio o pegue una lista manual para estimar costo y proyectar las tandas diarias en Piloto Automático.`;
        return;
    }

    const resumenTandasHtml = tandas.slice(0, 4).map(t => {
        const fechaTanda = new Date();
        fechaTanda.setDate(fechaTanda.getDate() + t.diaOffset);
        const fechaStr = fechaTanda.toLocaleDateString('es-VE', { weekday: 'short', day: '2-digit', month: 'short' });
        const etqDia = t.diaOffset === 0 ? `Hoy (${fechaStr})` : `Día ${t.diaOffset + 1} (${fechaStr})`;
        return `
            <div class="tanda-timeline-item">
                <div>
                    <span class="badge bg-dark me-1">Tanda #${t.tanda}</span>
                    <strong class="text-dark">${etqDia}</strong>
                    <span class="text-muted ms-1"><i class="fa-regular fa-clock me-1"></i>${horaProg12}</span>
                </div>
                <div class="text-end">
                    <span class="fw-bold text-primary">${t.cantidad} msgs</span>
                    <span class="badge bg-warning-subtle text-dark border ms-1">$${t.costo}</span>
                </div>
            </div>
        `;
    }).join('') + (tandas.length > 4 ? `<div class="text-center small text-muted mt-1">+${tandas.length - 4} tandas diarias adicionales programadas a las ${horaProg12}</div>` : '');

    // Resumen de distribución por sede cuando hay múltiples sedes seleccionadas (Difusión Global Master)
    let desgloseSedesStep3Html = '';
    if (ECO_STATE.esGlobal && ECO_STATE.seleccionadosIds.size > 0) {
        const mapaSedesNom = {};
        (ECO_STATE.sedes || []).forEach(s => { mapaSedesNom[parseInt(s.id, 10)] = s.nombre_sede; });
        const conteoSedeSel = {};
        ECO_STATE.contactos.forEach(c => {
            if (ECO_STATE.seleccionadosIds.has(c.id)) {
                const idSp = parseInt(c.id_sede_primaria || (Array.isArray(c.sedes_ids) && c.sedes_ids.length > 0 ? c.sedes_ids[0] : 0), 10);
                conteoSedeSel[idSp] = (conteoSedeSel[idSp] || 0) + 1;
            }
        });
        const keysSedes = Object.keys(conteoSedeSel);
        const badgeStep3 = document.getElementById('badgeStep3AlcanceSedes');
        if (badgeStep3) {
            badgeStep3.textContent = keysSedes.length > 1
                ? `Multisede (${keysSedes.length} sedes • ${totalSel} contactos)`
                : (keysSedes.length === 1 ? `${mapaSedesNom[parseInt(keysSedes[0], 10)] || '1 sede'} (${totalSel})` : 'Sin selección');
        }
        if (keysSedes.length > 1) {
            const chipsSedes = keysSedes.map(k => {
                const idS = parseInt(k, 10);
                const nomS = mapaSedesNom[idS] || (idS > 0 ? `Sede #${idS}` : 'General');
                return `<span class="badge bg-white text-dark border me-1 mb-1" style="font-size:0.65rem;"><strong>${escapeHtml(nomS)}:</strong> ${conteoSedeSel[k]}</span>`;
            }).join('');
            desgloseSedesStep3Html = `
                <div class="mb-2 p-2 rounded border bg-white">
                    <div class="fw-bold text-primary mb-1" style="font-size:0.71rem;">
                        <i class="fa-solid fa-layer-group me-1"></i>Distribución Global por Sede (${keysSedes.length} sedes → salen desde ${escapeHtml(sedeObj ? sedeObj.nombre_sede : 'Línea Activa')}):
                    </div>
                    <div>${chipsSedes}</div>
                </div>
            `;
        }
    }

    let msg = `
        ${desgloseSedesStep3Html}
        <div class="mb-2">
            <i class="fa-solid fa-clipboard-check text-primary me-1"></i>
            La campaña quedará en <strong>PENDIENTE DE APROBACIÓN</strong> con <strong>${efectivos} contactos</strong> ($${(efectivos * costoUnit).toFixed(2)} USD total) en <strong>${totalTandas} ${totalTandas === 1 ? 'tanda' : 'tandas'}</strong> de hasta <strong>${cuotaDiariaProg} msgs/día</strong>:
        </div>
        <div class="mt-1">${resumenTandasHtml}</div>
        <div class="mt-2 small ${pilotoAuto ? 'text-success fw-semibold' : 'text-muted'}">
            <i class="fa-solid ${pilotoAuto ? 'fa-robot' : 'fa-hand'} me-1"></i>
            ${pilotoAuto
            ? `Piloto Automático ACTIVO: tras aprobar, despachará cada tanda diaria automáticamente a las ${horaProg12}.`
            : 'Modo Manual: tras aprobar, enviará la Tanda #1 y esperará confirmación manual para las siguientes.'}
        </div>
    `;
    if (recortePresupuesto > 0) {
        msg += `<div class="text-danger small fw-semibold mt-1">(${recortePresupuesto} contactos omitidos por tope de presupuesto máx.)</div>`;
    }
    lblResumen.innerHTML = msg;
}

let CAMP_HEADER_PREVIEW_URL = null;

function previsualizarImagenCabecera(inputEl) {
    if (CAMP_HEADER_PREVIEW_URL) {
        URL.revokeObjectURL(CAMP_HEADER_PREVIEW_URL);
        CAMP_HEADER_PREVIEW_URL = null;
    }
    const badge = document.getElementById('badgeEstadoImagenHeader');
    const btnClear = document.getElementById('btnLimpiarImagenHeader');
    if (inputEl && inputEl.files && inputEl.files.length > 0) {
        const file = inputEl.files[0];
        CAMP_HEADER_PREVIEW_URL = URL.createObjectURL(file);
        if (badge) {
            badge.className = 'badge bg-success-subtle text-success border border-success-subtle';
            badge.textContent = 'Imagen Personalizada';
        }
        if (btnClear) btnClear.classList.remove('d-none');
    } else {
        if (badge) {
            badge.className = 'badge bg-warning-subtle text-dark border border-warning-subtle';
            badge.textContent = 'Logo Oficial Automático';
        }
        if (btnClear) btnClear.classList.add('d-none');
    }
    actualizarVistaPreviaCampana();
}

function limpiarImagenCabecera() {
    const inputEl = document.getElementById('campHeaderImagen');
    if (inputEl) inputEl.value = '';
    previsualizarImagenCabecera(inputEl);
}

function formatearTextoWhatsAppHtml(textoPlano) {
    let html = escapeHtml(textoPlano || '');
    html = html.replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>');
    html = html.replace(/_([^_\n]+)_/g, '<em>$1</em>');
    html = html.replace(/~([^~\n]+)~/g, '<del>$1</del>');
    html = html.replace(/\r?\n/g, '<br>');
    return html;
}

function actualizarVistaPreviaCampana(liveOverride = null) {
    const sedeObj = obtenerSedeActualObj();
    const nombreSede = sedeObj ? sedeObj.nombre_sede : 'Sede Oficial';
    const nombreRaw = liveOverride?.nombre || 'Carlos';
    const primerNombre = (String(nombreRaw).trim().split(/\s+/)[0]) || 'Cliente';
    const telefonoDemo = liveOverride?.telefono || '+58412...';

    const lblNom = document.getElementById('lblPreviewNombreCliente');
    const lblTel = document.getElementById('lblPreviewTelefonoCliente');
    const badgeSim = document.getElementById('badgeModoSimulador');
    const subSim = document.getElementById('lblSubtituloSimulador');

    if (lblNom) lblNom.textContent = liveOverride ? `${nombreRaw}` : 'Carlos (Ejemplo)';
    if (lblTel) lblTel.textContent = telefonoDemo;

    if (liveOverride) {
        if (badgeSim) {
            badgeSim.className = 'badge bg-danger text-white';
            badgeSim.innerHTML = `<i class="fa-solid fa-satellite-dish fa-fade me-1"></i>EN VIVO (${liveOverride.progreso || ''})`;
        }
        if (subSim) subSim.textContent = `Emitiendo: ${liveOverride.nombreCampana || 'Campaña'}`;
    } else {
        if (badgeSim) {
            badgeSim.className = 'badge bg-success-subtle text-success border border-success-subtle';
            badgeSim.innerHTML = `<i class="fa-solid fa-eye me-1"></i>Diseño`;
        }
        if (subSim) subSim.textContent = 'Vista previa en tiempo real';
    }

    // Determinar plantilla activa (o plantilla de la campaña en vivo)
    let tpl = obtenerPlantillaSeleccionadaObj();
    if (liveOverride?.templateName && Array.isArray(ECO_STATE.templatesSede)) {
        const foundLive = ECO_STATE.templatesSede.find(t => t.name === liveOverride.templateName);
        if (foundLive) tpl = foundLive;
    }

    const stateTpl = obtenerEstadoValoresPlantilla(tpl);
    const headerFmt = String(liveOverride?.headerFormat || tpl.header_format || 'NONE').toUpperCase();

    // Resolver parámetros del cuerpo para la vista previa
    const varsBody = extraerVariablesDeTexto(tpl.body || '');
    const valoresResueltos = {};

    if (liveOverride?.bodyParams && Array.isArray(liveOverride.bodyParams)) {
        liveOverride.bodyParams.forEach((v, i) => {
            let s = String(v || '');
            s = s.replace(/@NOMBRE_CLIENTE|\{\{nombre_cliente\}\}/gi, primerNombre);
            s = s.replace(/@NOMBRE_COMPLETO|\{\{nombre_completo\}\}/gi, nombreRaw);
            s = s.replace(/@TELEFONO_CLIENTE|\{\{telefono_cliente\}\}/gi, telefonoDemo);
            s = s.replace(/@NOMBRE_SEDE|\{\{nombre_sede\}\}/gi, nombreSede);
            valoresResueltos[i + 1] = s;
        });
    } else {
        varsBody.forEach(numVar => {
            if (stateTpl.autoClienteVars[numVar]) {
                valoresResueltos[numVar] = primerNombre;
            } else {
                let rawV = String(stateTpl.bodyVars[numVar] || '').trim();
                rawV = rawV.replace(/@NOMBRE_CLIENTE|\{\{nombre_cliente\}\}/gi, primerNombre);
                rawV = rawV.replace(/@NOMBRE_COMPLETO|\{\{nombre_completo\}\}/gi, nombreRaw);
                rawV = rawV.replace(/@TELEFONO_CLIENTE|\{\{telefono_cliente\}\}/gi, telefonoDemo);
                rawV = rawV.replace(/@NOMBRE_SEDE|\{\{nombre_sede\}\}/gi, nombreSede);
                valoresResueltos[numVar] = rawV;
            }
        });
    }

    // 1. Construir Cabecera según header_format
    let headerPreviewHtml = '';
    if (headerFmt === 'IMAGE') {
        const fileInput = document.getElementById('campHeaderImagen');
        const tieneImgCustom = fileInput && fileInput.files && fileInput.files.length > 0;
        const imgUrlEfectiva = liveOverride?.imgUrl || CAMP_HEADER_PREVIEW_URL;
        const nombreImg = liveOverride?.imgLabel || (tieneImgCustom ? fileInput.files[0].name : 'Logo Oficial de la Sede (Automático)');

        headerPreviewHtml = imgUrlEfectiva
            ? `<div class="mb-2 rounded overflow-hidden border bg-white text-center">
                   <img src="${imgUrlEfectiva}" alt="Header" style="max-height: 155px; width: 100%; object-fit: cover; display: block;">
                   <div class="py-1 px-2 small text-success fw-semibold bg-light border-top" style="font-size: 0.7rem;">
                       <i class="fa-solid fa-image me-1"></i>${escapeHtml(nombreImg)}
                   </div>
               </div>`
            : `<div class="mb-2 p-2 rounded text-center bg-white bg-opacity-75 border border-secondary border-opacity-25 small text-secondary" style="font-size: 0.74rem;">
                   <i class="fa-regular fa-image text-success me-1"></i>Cabecera: <strong>${escapeHtml(nombreImg)}</strong>
               </div>`;
    } else if (headerFmt === 'TEXT' && tpl.header_text) {
        let hdrTxt = String(tpl.header_text);
        const valHdr = String(liveOverride?.headerTextParam ?? stateTpl.headerTextVar ?? '').trim() || '{{1}}';
        hdrTxt = hdrTxt.replace(/\{\{1\}\}/g, valHdr);
        headerPreviewHtml = `<div class="fw-bold text-dark mb-1" style="font-size: 0.92rem;">${escapeHtml(hdrTxt)}</div>`;
    }

    // 2. Construir Cuerpo reemplazando {{1}}, {{2}}, {{3}}, ...
    let bodyRaw = String(liveOverride?.bodyTemplate || tpl.body || '');
    let bodyHtml = formatearTextoWhatsAppHtml(bodyRaw);
    varsBody.forEach(numVar => {
        const val = valoresResueltos[numVar];
        const reemplazoHtml = val
            ? `<strong>${escapeHtml(val)}</strong>`
            : `<span class="badge bg-warning-subtle text-dark border border-warning px-1" style="font-size:0.74rem;">{{${numVar}}}</span>`;
        const regVar = new RegExp(`\\{\\{${numVar}\\}\\}`, 'g');
        bodyHtml = bodyHtml.replace(regVar, reemplazoHtml);
    });

    // 3. Construir Footer y Botones interactivos si la plantilla los tiene
    const footerTxt = String(liveOverride?.footerText ?? tpl.footer ?? '');
    const horaActualBubble = new Date().toLocaleTimeString('es-VE', { hour: '2-digit', minute: '2-digit', hour12: true });
    let buttonsHtml = '';
    if (Array.isArray(tpl.buttons) && tpl.buttons.length > 0) {
        buttonsHtml = `<div class="mt-2 pt-1 border-top border-secondary border-opacity-25 d-grid gap-1">` +
            tpl.buttons.map(b => {
                const bIcon = b.type === 'URL' ? 'fa-arrow-up-right-from-square' : (b.type === 'PHONE_NUMBER' ? 'fa-phone' : 'fa-reply');
                return `<div class="text-center py-1 rounded bg-white bg-opacity-75 text-primary fw-semibold small border" style="font-size: 0.76rem;">
                            <i class="fa-solid ${bIcon} me-1"></i>${escapeHtml(b.text || 'Botón')}
                        </div>`;
            }).join('') +
            `</div>`;
    }

    const bubble = document.getElementById('waBubblePreview');
    if (bubble) {
        bubble.innerHTML = `
            ${headerPreviewHtml}
            <div style="line-height: 1.45; word-break: break-word;">${bodyHtml}</div>
            <div class="mt-2 pt-1 border-top border-secondary border-opacity-25 d-flex justify-content-between align-items-center text-muted" style="font-size: 0.7rem;">
                <span>${escapeHtml(footerTxt)}</span>
                <span class="ms-auto">${horaActualBubble} <i class="fa-solid fa-check-double text-info"></i></span>
            </div>
            ${buttonsHtml}
        `;
    }
}

// =========================================================================
// 1. REGISTRO DE CAMPAÑA (QUEDA PENDIENTE DE APROBACIÓN CON ESTIMACIÓN Y TANDAS)
// =========================================================================
async function iniciarLanzamientoCampana() {
    if (ECO_STATE.enviandoCola) return;

    const sedeObj = obtenerSedeActualObj();
    if (!sedeObj || !sedeObj.tiene_linea_activa) {
        mostrarToastStarfi('La sede seleccionada no tiene una línea oficial de WhatsApp activa.', 'warning');
        return;
    }

    const validVars = validarVariablesPlantillaActiva(true);
    if (!validVars.ok) {
        irPasoWizard(1);
        return;
    }

    const payloadTpl = obtenerPayloadVariablesPlantillaActiva();
    const nombreCampana = (document.getElementById('campNombre').value || '').trim() || `Campaña ${sedeObj.nombre_sede} - ${new Date().toLocaleDateString()}`;
    const templateName = payloadTpl.template_name || 'starfi_difusion_directa';
    const esUtility = payloadTpl.template_category === 'UTILITY';
    const param2Compat = payloadTpl.body_params[1] ?? (payloadTpl.body_params[0] ?? 'Plantilla sin variables');
    const param3Compat = payloadTpl.body_params[2] ?? (payloadTpl.body_params[1] ?? (payloadTpl.body_params[0] ?? ''));
    const presupuestoMax = parseFloat(document.getElementById('campPresupuestoMax').value || 0);
    const costoUnit = parseFloat(esUtility ? (sedeObj.costo_utility_usd || 0.0165) : (sedeObj.costo_marketing_usd || 0.07));
    const limSede = parseInt(sedeObj.limite_diario_mensajes || 250, 10);
    let cuotaDiariaProg = parseInt(document.getElementById('campCuotaDiariaProg')?.value || limSede, 10);
    if (isNaN(cuotaDiariaProg) || cuotaDiariaProg < 1) cuotaDiariaProg = limSede;
    cuotaDiariaProg = Math.min(cuotaDiariaProg, 100000);
    const pilotoAutomatico = document.getElementById('campPilotoAutomatico')?.checked ? 1 : 0;
    const horaProgramada = (document.getElementById('campHoraProgramada')?.value || '09:00').trim();
    const horaProg12 = formatearHora12h(horaProgramada);

    const destinatarios = obtenerDestinatariosSeleccionadosArray();
    if (destinatarios.length === 0) {
        mostrarToastStarfi('Seleccione al menos un destinatario para registrar la campaña.', 'warning');
        return;
    }

    const costoEstTotal = (destinatarios.length * costoUnit).toFixed(2);
    const tandasEst = Math.max(1, Math.ceil(destinatarios.length / cuotaDiariaProg));

    const confirmar = await mostrarDialogoStarfi({
        badge: 'PASO 1 • REGISTRO Y PLANIFICACIÓN',
        badgeColor: '#E85B14',
        title: `¿Registrar "${nombreCampana}"?`,
        icon: 'fa-solid fa-clipboard-list',
        iconBg: 'rgba(232, 91, 20, 0.2)',
        iconColor: '#FB923C',
        confirmText: '<i class="fa-solid fa-check me-1"></i>Registrar como Pendiente',
        confirmColor: '#E85B14',
        cancelText: 'Revisar Parámetros',
        bodyHtml: `
            <p class="mb-2">La campaña se guardará en estado <span class="badge bg-warning text-dark">PENDIENTE DE APROBACIÓN</span> con la siguiente proyección:</p>
            <div class="starfi-summary-grid">
                <div class="starfi-summary-item">
                    <div class="label">Sede Emisora</div>
                    <div class="value text-truncate">${escapeHtml(sedeObj.nombre_sede)}</div>
                    <div class="text-muted" style="font-size: 0.75rem;"><i class="fa-brands fa-whatsapp text-success me-1"></i>${escapeHtml(sedeObj.numero_telefono)}</div>
                </div>
                <div class="starfi-summary-item">
                    <div class="label">Audiencia Total</div>
                    <div class="value">${destinatarios.length} contactos</div>
                    <div class="text-muted" style="font-size: 0.75rem;">Plantilla: ${escapeHtml(templateName)} (${escapeHtml(payloadTpl.template_language.toUpperCase())})</div>
                </div>
                <div class="starfi-summary-item">
                    <div class="label">Inversión Estimada Meta</div>
                    <div class="value" style="color: #EA580C;">$${costoEstTotal} USD</div>
                    <div class="text-muted" style="font-size: 0.75rem;">Tarifa ${esUtility ? 'Utility' : 'Marketing'}: $${costoUnit.toFixed(4)} / msg</div>
                </div>
                <div class="starfi-summary-item">
                    <div class="label">Plan de Tandas Diarias</div>
                    <div class="value">${tandasEst} ${tandasEst === 1 ? 'Tanda' : 'Tandas'}</div>
                    <div class="text-muted" style="font-size: 0.75rem;">Hasta ${cuotaDiariaProg} msgs/día • ${horaProg12}</div>
                </div>
            </div>
            <div class="p-2.5 px-3 rounded-3 bg-light border d-flex align-items-center justify-content-between mb-2">
                <span class="small fw-bold text-dark"><i class="fa-solid fa-robot text-primary me-2"></i>Piloto Automático Diario (${horaProg12}):</span>
                <span class="badge ${pilotoAutomatico ? 'bg-success' : 'bg-secondary'}">${pilotoAutomatico ? 'SÍ (Activado)' : 'NO (Manual)'}</span>
            </div>
            <div class="alert alert-warning small py-2 px-3 mb-0 border-warning">
                <i class="fa-solid fa-shield-halved me-1"></i>
                <strong>Importante:</strong> El envío a WhatsApp <strong>NO</strong> iniciará hasta que apruebes la campaña en la tabla derecha.
            </div>
        `
    });
    if (!confirmar) return;

    try {
        const fd = new FormData();
        fd.append('action', 'create_campaign_with_queue');
        fd.append('id_sede', sedeObj.id);
        fd.append('nombre_campana', nombreCampana);
        fd.append('template_name', templateName);
        fd.append('template_language', payloadTpl.template_language);
        fd.append('template_category', payloadTpl.template_category);
        fd.append('header_format', payloadTpl.header_format);
        fd.append('template_params_json', JSON.stringify(payloadTpl));
        fd.append('param_cuerpo', param2Compat);
        fd.append('param_eslogan', param3Compat);
        fd.append('costo_unitario_usd', costoUnit);
        fd.append('presupuesto_max_usd', presupuestoMax);
        fd.append('cuota_diaria_programada', cuotaDiariaProg);
        fd.append('piloto_automatico', pilotoAutomatico);
        fd.append('hora_programada', horaProgramada);
        fd.append('destinatarios', JSON.stringify(destinatarios));
        const fileImg = document.getElementById('campHeaderImagen');
        if (payloadTpl.header_format === 'IMAGE' && fileImg && fileImg.files && fileImg.files.length > 0) {
            fd.append('header_imagen', fileImg.files[0]);
        }

        const resp = await fetch('back_campanas.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.status !== 'success') {
            mostrarToastStarfi(data.message || 'No se pudo registrar la campaña.', 'danger');
            return;
        }

        // Limpiar selección tras crear campaña y volver al Paso 1
        ECO_STATE.seleccionadosIds.clear();
        const chkAll = document.getElementById('chkSelectAllAudience');
        if (chkAll) chkAll.checked = false;
        document.getElementById('campNombre').value = '';
        irPasoWizard(1);

        await recargarEcosistema();

        await mostrarDialogoStarfi({
            badge: 'CAMPAÑA REGISTRADA',
            badgeColor: '#10B981',
            title: '¡Lista para Aprobación!',
            icon: 'fa-solid fa-circle-check',
            iconBg: 'rgba(16, 185, 129, 0.2)',
            iconColor: '#34D399',
            confirmText: 'Entendido',
            confirmColor: '#10B981',
            bodyHtml: `
                <p class="mb-2">La campaña <strong>"${escapeHtml(data.nombre_campana)}"</strong> quedó registrada correctamente.</p>
                <div class="starfi-summary-grid">
                    <div class="starfi-summary-item">
                        <div class="label">Estado Actual</div>
                        <div class="value text-warning">Pendiente Aprobación</div>
                    </div>
                    <div class="starfi-summary-item">
                        <div class="label">Contactos Encolados</div>
                        <div class="value">${data.total_encolados} destinatarios</div>
                    </div>
                    <div class="starfi-summary-item">
                        <div class="label">Costo Estimado</div>
                        <div class="value" style="color: #EA580C;">$${parseFloat(data.costo_estimado_usd || 0).toFixed(2)} USD</div>
                    </div>
                    <div class="starfi-summary-item">
                        <div class="label">Tandas Programadas</div>
                        <div class="value">${data.total_tandas} (${data.cuota_diaria_programada}/día)</div>
                    </div>
                </div>
                <div class="small text-muted mt-2">
                    <i class="fa-solid fa-arrow-right text-success me-1"></i>
                    Para iniciar la emisión de mensajes, pulsa el botón verde <strong>"Aprobar e Iniciar"</strong> en el Historial de Campañas.
                </div>
            `
        });
    } catch (err) {
        console.error('Error al registrar campaña:', err);
        mostrarToastStarfi('Error de red al registrar la campaña.', 'danger');
    }
}

// =========================================================================
// 2. APROBAR CAMPAÑA E INICIAR DIFUSIÓN (Y ACTIVAR PILOTO AUTOMÁTICO DIARIO)
// =========================================================================
async function aprobarEIniciarCampana(idCampana) {
    if (ECO_STATE.enviandoCola) {
        mostrarToastStarfi('Ya hay un envío en curso en este momento.', 'warning');
        return;
    }

    const camp = ECO_STATE.campanas.find(c => parseInt(c.id, 10) === parseInt(idCampana, 10));
    const nom = camp ? camp.nombre_campana : `#${idCampana}`;
    const total = camp ? parseInt(camp.total_destinatarios || 0, 10) : 0;
    const costoEst = camp ? parseFloat(camp.costo_estimado_usd || (total * parseFloat(camp.costo_unitario_usd || 0.07))).toFixed(2) : '0.00';
    const cuotaProg = camp ? parseInt(camp.cuota_diaria_programada || 250, 10) : 250;
    const tandas = camp ? parseInt(camp.total_tandas || 1, 10) : 1;
    const piloto = camp ? parseInt(camp.piloto_automatico ?? 1, 10) === 1 : true;
    const horaProgSaved = (camp && camp.hora_programada) ? String(camp.hora_programada).trim().substring(0, 5) : '09:00';
    const imgPathSaved = (camp && camp.header_imagen_path) ? String(camp.header_imagen_path).trim() : '';
    const campHeaderFmt = String(camp?.header_format || (camp?.template_name === 'starfi_difusion_directa' ? 'IMAGE' : 'NONE')).toUpperCase();
    const usaImagenCabecera = (campHeaderFmt === 'IMAGE' || imgPathSaved !== '');

    try {
        const mainImgInput = document.getElementById('campHeaderImagen');
        const mainSelectedFile = (usaImagenCabecera && mainImgInput && mainImgInput.files && mainImgInput.files.length > 0)
            ? mainImgInput.files[0]
            : null;
        let modalPickedFile = null;

        const bloqueFlyerModal = usaImagenCabecera ? `
            <div class="p-2.5 rounded-3 border bg-light mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small fw-bold text-dark"><i class="fa-regular fa-image text-starfi-orange me-1"></i>Imagen / Flyer de Cabecera:</span>
                    <span class="badge ${imgPathSaved ? 'bg-success' : 'bg-secondary'}">${imgPathSaved ? 'Flyer Propio Cargado' : 'Logo Sede (Por defecto)'}</span>
                </div>
                ${imgPathSaved ? `<div class="mb-2 text-center"><img src="../../${escapeHtml(imgPathSaved)}" style="max-height:110px; border-radius:8px; border:1px solid #cbd5e1;"></div>` : ''}
                <label class="small text-muted d-block mb-1" style="font-size:0.74rem;">${imgPathSaved ? '¿Deseas cambiar el flyer antes de enviar?' : 'Selecciona aquí tu Flyer si no deseas enviar el logo por defecto:'}</label>
                <input type="file" id="modalApproveHeaderImg" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp">
            </div>
        ` : '';

        const ok = await mostrarDialogoStarfi({
            badge: 'PASO 2 • APROBACIÓN Y EMISIÓN WABA',
            badgeColor: '#10B981',
            title: `¿Aprobar e Iniciar "${nom}"?`,
            icon: 'fa-solid fa-paper-plane',
            iconBg: 'rgba(16, 185, 129, 0.2)',
            iconColor: '#34D399',
            confirmText: '<i class="fa-solid fa-play me-1"></i>Aprobar Campaña',
            confirmColor: '#10B981',
            cancelText: 'Todavía no',
            bodyHtml: `
                <p class="mb-2">Estás a punto de autorizar la emisión oficial de la campaña por WhatsApp Business API:</p>
                <div class="starfi-summary-grid">
                    <div class="starfi-summary-item">
                        <div class="label">Destinatarios Totales</div>
                        <div class="value">${total} contactos</div>
                    </div>
                    <div class="starfi-summary-item">
                        <div class="label">Costo Total Estimado</div>
                        <div class="value" style="color: #EA580C;">$${costoEst} USD</div>
                    </div>
                    <div class="starfi-summary-item">
                        <div class="label">Plan de Tandas</div>
                        <div class="value">${tandas} ${tandas === 1 ? 'Tanda' : 'Tandas'} (máx. ${cuotaProg}/día)</div>
                    </div>
                    <div class="starfi-summary-item">
                        <div class="label">Piloto Automático</div>
                        <div class="value ${piloto ? 'text-success' : 'text-secondary'}">${piloto ? 'ACTIVO Diario' : 'Manual'}</div>
                    </div>
                </div>
                <div class="p-2.5 rounded-3 border bg-light mb-2">
                    <div class="row g-2 align-items-center">
                        <div class="col-5">
                            <label class="small fw-bold text-dark d-block mb-1" style="font-size:0.75rem;">
                                <i class="fa-regular fa-clock text-primary me-1"></i>Hora Diaria:
                            </label>
                            <input type="time" id="modalApproveHoraProg" class="form-control form-control-sm fw-bold" value="${escapeHtml(horaProgSaved)}">
                        </div>
                        <div class="col-7">
                            <label class="small fw-bold text-dark d-block mb-1" style="font-size:0.75rem;">
                                <i class="fa-solid fa-bolt text-warning me-1"></i>Modo de Inicio (Tanda #1):
                            </label>
                            <select id="modalApproveModoInicio" class="form-select form-select-sm fw-semibold">
                                <option value="1" selected>Enviar 1ª tanda AHORA mismo</option>
                                <option value="0">Esperar a la hora programada</option>
                            </select>
                        </div>
                    </div>
                </div>
                ${bloqueFlyerModal}
                <div class="alert alert-success small py-2 px-3 mb-0">
                    <i class="fa-solid fa-bolt me-1"></i>
                    Las tandas de los días siguientes se despacharán automáticamente a la hora programada.
                </div>
            `
        });
        if (!ok) return;

        const modalFileAfter = document.getElementById('modalApproveHeaderImg')?.files?.[0] || modalPickedFile || null;
        const mainFileAfter = usaImagenCabecera ? (document.getElementById('campHeaderImagen')?.files?.[0] || mainSelectedFile || null) : null;
        const fileToUpload = modalFileAfter || mainFileAfter;
        const horaElegida = (document.getElementById('modalApproveHoraProg')?.value || horaProgSaved).trim();
        const iniciarInmediato = document.getElementById('modalApproveModoInicio')?.value ?? '1';

        const fd = new FormData();
        fd.append('action', 'approve_and_start_campaign');
        fd.append('id_campana', idCampana);
        fd.append('hora_programada', horaElegida);
        fd.append('iniciar_inmediato', iniciarInmediato);
        if (fileToUpload) {
            fd.append('header_imagen', fileToUpload);
        }

        const resp = await fetch('back_campanas.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.status !== 'success') {
            mostrarToastStarfi(data.message || 'No se pudo aprobar la campaña.', 'danger');
            return;
        }

        const cola = data.cola_envio || [];
        if (cola.length === 0) {
            const msgProg = data.esperando_hora
                ? `La campaña <strong>"${escapeHtml(data.nombre_campana)}"</strong> ha sido <strong>APROBADA</strong> y quedó programada en Piloto Automático para iniciar hoy a las <strong>${formatearHora12h(data.hora_programada || horaElegida)}</strong>.`
                : `La campaña <strong>"${escapeHtml(data.nombre_campana)}"</strong> ha sido <strong>APROBADA</strong>. Como la sede ya completó su cuota diaria de hoy, el Piloto Automático despachará la primera tanda automáticamente mañana a las <strong>${formatearHora12h(data.hora_programada || horaElegida)}</strong>.`;
            await mostrarDialogoStarfi({
                badge: 'PILOTO AUTOMÁTICO PROGRAMADO',
                badgeColor: '#0284C7',
                title: 'Campaña Aprobada y Programada',
                icon: 'fa-solid fa-clock',
                iconBg: 'rgba(14, 165, 233, 0.2)',
                iconColor: '#38BDF8',
                confirmText: 'Entendido',
                confirmColor: '#0284C7',
                bodyHtml: `<p class="mb-0">${msgProg}</p>`
            });
            recargarEcosistema();
            return;
        }

        await procesarColaDeEnvio(idCampana, cola, data.cadencia_ms || 1000, data.template_language || 'es');
    } catch (e) {
        console.error(e);
        mostrarToastStarfi('Error al aprobar e iniciar la campaña.', 'danger');
    }
}

async function cancelarCampanaPendiente(idCampana) {
    const ok = await mostrarDialogoStarfi({
        badge: 'CANCELAR CAMPAÑA',
        badgeColor: '#EF4444',
        title: '¿Rechazar / Cancelar esta campaña?',
        icon: 'fa-solid fa-ban',
        iconBg: 'rgba(239, 68, 68, 0.2)',
        iconColor: '#F87171',
        confirmText: '<i class="fa-solid fa-trash-can me-1"></i>Sí, Cancelar Campaña',
        confirmColor: '#EF4444',
        cancelText: 'Volver',
        bodyHtml: `<p class="mb-0">Esta campaña pasará a estado <strong>CANCELADA</strong> y no enviará ningún mensaje a los destinatarios encolados.</p>`
    });
    if (!ok) return;
    try {
        const fd = new FormData();
        fd.append('action', 'reject_campaign');
        fd.append('id_campana', idCampana);
        const resp = await fetch('back_campanas.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.status === 'success') {
            mostrarToastStarfi('Campaña cancelada correctamente.', 'info');
            recargarEcosistema();
        } else {
            mostrarToastStarfi(data.message || 'No se pudo cancelar.', 'danger');
        }
    } catch (e) {
        console.error(e);
    }
}

window.recalcularPreviewModalEditarCampana = function (costoUnit, totalOriginal) {
    const inpCuota = document.getElementById('editCampCuotaDiaria');
    const inpTope = document.getElementById('editCampTopeDestinatarios');
    const inpPres = document.getElementById('editCampPresupuestoMax');
    const lblPreview = document.getElementById('editCampLivePreviewBox');
    if (!inpCuota || !inpTope || !lblPreview) return;

    let cuota = Math.max(1, parseInt(inpCuota.value || 1000, 10));
    let tope = Math.max(1, Math.min(totalOriginal, parseInt(inpTope.value || totalOriginal, 10)));
    const pres = parseFloat(inpPres?.value || 0);
    if (pres > 0 && costoUnit > 0) {
        const maxPorPres = Math.max(1, Math.floor((pres + 0.00001) / costoUnit));
        if (maxPorPres < tope) tope = maxPorPres;
    }

    const tandas = Math.max(1, Math.ceil(tope / cuota));
    const costoTotal = (tope * costoUnit).toFixed(2);
    lblPreview.innerHTML = `
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <i class="fa-solid fa-calculator text-primary me-1"></i>
                Resultado tras guardar: <strong>${tope} contactos</strong> en <strong>${tandas} ${tandas === 1 ? 'tanda (1 día)' : `tandas (${tandas} días)`}</strong> a razón de <strong>${cuota} msgs/día</strong>
            </div>
            <span class="badge bg-warning text-dark border border-warning fs-6">$${costoTotal} USD</span>
        </div>
    `;
};

window.aplicarAtajoEdicionCampana = function (modo, totalOriginal, costoUnit) {
    const inpCuota = document.getElementById('editCampCuotaDiaria');
    const inpTope = document.getElementById('editCampTopeDestinatarios');
    const inpCuotaSede = document.getElementById('editCampCuotaPorSede');
    if (!inpCuota || !inpTope) return;

    if (modo === '1_DIA_1000') {
        inpCuota.value = 1000;
        inpTope.value = Math.min(1000, totalOriginal);
        if (inpCuotaSede) inpCuotaSede.value = '';
    } else if (modo === 'CUOTA_1000_TODOS') {
        inpCuota.value = 1000;
        inpTope.value = totalOriginal;
        if (inpCuotaSede) inpCuotaSede.value = '';
    } else if (modo === 'CUOTA_2000_TODOS') {
        inpCuota.value = 2000;
        inpTope.value = totalOriginal;
        if (inpCuotaSede) inpCuotaSede.value = '';
    } else if (modo === 'TODOS_1_DIA') {
        inpCuota.value = totalOriginal;
        inpTope.value = totalOriginal;
        if (inpCuotaSede) inpCuotaSede.value = '';
    }
    window.recalcularPreviewModalEditarCampana(costoUnit, totalOriginal);
};

async function abrirModalEditarCampana(idCampana) {
    const camp = ECO_STATE.campanas.find(c => parseInt(c.id, 10) === parseInt(idCampana, 10));
    if (!camp) {
        mostrarToastStarfi('No se encontró la información de la campaña.', 'warning');
        return;
    }

    const totalActual = parseInt(camp.total_destinatarios || 0, 10);
    const cuotaActual = parseInt(camp.cuota_diaria_programada || 250, 10);
    const tandasActual = parseInt(camp.total_tandas || Math.max(1, Math.ceil(totalActual / cuotaActual)), 10);
    const horaActual = (camp.hora_programada || '09:00').substring(0, 5);
    const pilotoActual = parseInt(camp.piloto_automatico ?? 1, 10) === 1;
    const presMaxActual = parseFloat(camp.presupuesto_max_usd || 0);
    const costoUnit = parseFloat(camp.costo_unitario_usd || 0.0770);
    const paramCuerpo = camp.param_cuerpo || '';
    const paramEslogan = camp.param_eslogan || '';
    const imgPathSaved = (camp.header_imagen_path || '').trim();
    const campHeaderFmt = String(camp.header_format || 'IMAGE').toUpperCase();

    const ok = await mostrarDialogoStarfi({
        badge: `EDITAR CAMPAÑA #${camp.id}`,
        badgeColor: '#F59E0B',
        title: `Ajustar y Corregir Campaña`,
        icon: 'fa-solid fa-pen-to-square',
        iconBg: 'rgba(245, 158, 11, 0.2)',
        iconColor: '#FBBF24',
        confirmText: '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar Ajustes y Recalcular Tandas',
        confirmColor: '#E85B14',
        cancelText: 'Cancelar',
        bodyHtml: `
            <div class="mb-2">
                <label class="form-label small fw-bold text-dark mb-1">Nombre de la Campaña</label>
                <input type="text" id="editCampNombre" class="form-control form-control-sm fw-semibold" value="${escapeHtml(camp.nombre_campana || '')}">
            </div>

            <!-- Atajos rápidos para corregir tandas y cantidad -->
            <div class="p-2 px-2.5 rounded-3 border mb-2" style="background: #FFFBEB; border-color: #FDE68A !important;">
                <div class="small fw-bold text-dark mb-1" style="font-size: 0.74rem;">
                    <i class="fa-solid fa-wand-magic-sparkles text-warning me-1"></i>Atajos Rápidos para Corregir Días / Tandas:
                </div>
                <div class="d-flex flex-wrap gap-1">
                    <button type="button" class="btn btn-sm btn-warning text-dark py-0 px-2 fw-bold" style="font-size:0.71rem;" onclick="aplicarAtajoEdicionCampana('1_DIA_1000', ${totalActual}, ${costoUnit})">
                        Solo 1,000 msgs en 1 tanda (1 día)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 fw-semibold" style="font-size:0.71rem;" onclick="aplicarAtajoEdicionCampana('CUOTA_1000_TODOS', ${totalActual}, ${costoUnit})">
                        Subir ritmo a 1,000/día (${Math.ceil(totalActual / 1000)} tandas)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 fw-semibold" style="font-size:0.71rem;" onclick="aplicarAtajoEdicionCampana('CUOTA_2000_TODOS', ${totalActual}, ${costoUnit})">
                        Subir ritmo a 2,000/día (${Math.ceil(totalActual / 2000)} tandas)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 fw-semibold" style="font-size:0.71rem;" onclick="aplicarAtajoEdicionCampana('TODOS_1_DIA', ${totalActual}, ${costoUnit})">
                        Todos (${totalActual}) en 1 tanda
                    </button>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small fw-bold text-dark mb-1" style="font-size:0.75rem;">
                        Cuota Diaria (Msgs / Día)
                    </label>
                    <input type="number" id="editCampCuotaDiaria" class="form-control form-control-sm fw-bold" min="1" max="100000" value="${cuotaActual}" oninput="recalcularPreviewModalEditarCampana(${costoUnit}, ${totalActual})">
                    <small class="text-muted" style="font-size:0.68rem;">Actualiza también el límite diario de la sede</small>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-bold text-dark mb-1" style="font-size:0.75rem;">
                        Total Contactos a Enviar (Tope)
                    </label>
                    <input type="number" id="editCampTopeDestinatarios" class="form-control form-control-sm fw-bold" min="1" max="${totalActual}" value="${totalActual}" oninput="recalcularPreviewModalEditarCampana(${costoUnit}, ${totalActual})">
                    <small class="text-muted" style="font-size:0.68rem;">De ${totalActual} encolados actualmente</small>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-4">
                    <label class="form-label small fw-bold text-dark mb-1" style="font-size:0.74rem;">Hora Diaria</label>
                    <input type="time" id="editCampHoraProg" class="form-control form-control-sm fw-bold" value="${escapeHtml(horaActual)}">
                </div>
                <div class="col-4">
                    <label class="form-label small fw-bold text-dark mb-1" style="font-size:0.74rem;">Presupuesto Máx ($)</label>
                    <input type="number" step="0.50" min="0" id="editCampPresupuestoMax" class="form-control form-control-sm" placeholder="Sin límite" value="${presMaxActual > 0 ? presMaxActual : ''}" oninput="recalcularPreviewModalEditarCampana(${costoUnit}, ${totalActual})">
                </div>
                <div class="col-4">
                    <label class="form-label small fw-bold text-dark mb-1" style="font-size:0.74rem;">Recortar por Sede</label>
                    <input type="number" min="0" id="editCampCuotaPorSede" class="form-control form-control-sm" placeholder="Ej: 100/sede">
                </div>
            </div>

            <div id="editCampLivePreviewBox" class="p-2 px-3 rounded-3 border bg-light mb-2 small">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <i class="fa-solid fa-calculator text-primary me-1"></i>
                        Actual: <strong>${totalActual} contactos</strong> en <strong>${tandasActual} ${tandasActual === 1 ? 'tanda' : 'tandas'}</strong> (${cuotaActual} msgs/día)
                    </div>
                    <span class="badge bg-warning text-dark border border-warning fs-6">$${(totalActual * costoUnit).toFixed(2)} USD</span>
                </div>
            </div>

            <div class="accordion accordion-flush border rounded-3 mb-2" id="accEditVarsCamp">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed py-2 px-3 small fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseEditVars">
                            <i class="fa-solid fa-comment-pen text-primary me-2"></i>Editar Texto del Mensaje o Flyer (Opcional)
                        </button>
                    </h2>
                    <div id="collapseEditVars" class="accordion-collapse collapse" data-bs-parent="#accEditVarsCamp">
                        <div class="accordion-body p-2.5 bg-light">
                            <div class="mb-2">
                                <label class="form-label small fw-bold mb-1" style="font-size:0.73rem;">Cuerpo Principal / Variable {{2}}</label>
                                <textarea id="editCampParamCuerpo" class="form-control form-control-sm" rows="2">${escapeHtml(paramCuerpo)}</textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold mb-1" style="font-size:0.73rem;">Cierre / Eslogan / Variable {{3}}</label>
                                <input type="text" id="editCampParamEslogan" class="form-control form-control-sm" value="${escapeHtml(paramEslogan)}">
                            </div>
                            ${campHeaderFmt === 'IMAGE' ? `
                            <div>
                                <label class="form-label small fw-bold mb-1" style="font-size:0.73rem;">Reemplazar Imagen / Flyer (${imgPathSaved ? 'Flyer Propio activo' : 'Logo por defecto'})</label>
                                <input type="file" id="editCampHeaderImg" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp">
                            </div>` : ''}
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="editCampPilotoAuto" ${pilotoActual ? 'checked' : ''}>
                <label class="form-check-label small fw-bold text-dark" for="editCampPilotoAuto" style="font-size:0.76rem;">
                    <i class="fa-solid fa-robot text-primary me-1"></i>Piloto Automático Diario Activo
                </label>
            </div>
        `
    });

    if (!ok) return;

    try {
        const fd = new FormData();
        fd.append('action', 'edit_pending_campaign');
        fd.append('id_campana', idCampana);
        fd.append('nombre_campana', document.getElementById('editCampNombre')?.value || camp.nombre_campana);
        fd.append('cuota_diaria_programada', document.getElementById('editCampCuotaDiaria')?.value || cuotaActual);
        fd.append('tope_destinatarios', document.getElementById('editCampTopeDestinatarios')?.value || totalActual);
        fd.append('hora_programada', document.getElementById('editCampHoraProg')?.value || horaActual);
        fd.append('presupuesto_max_usd', document.getElementById('editCampPresupuestoMax')?.value || 0);
        fd.append('cuota_por_sede', document.getElementById('editCampCuotaPorSede')?.value || 0);
        fd.append('piloto_automatico', document.getElementById('editCampPilotoAuto')?.checked ? 1 : 0);
        fd.append('param_cuerpo', document.getElementById('editCampParamCuerpo')?.value || paramCuerpo);
        fd.append('param_eslogan', document.getElementById('editCampParamEslogan')?.value || paramEslogan);

        const newImgFile = document.getElementById('editCampHeaderImg')?.files?.[0];
        if (newImgFile) {
            fd.append('header_imagen', newImgFile);
        }

        const resp = await fetch('back_campanas.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.status === 'success') {
            mostrarToastStarfi(data.message || 'Campaña actualizada exitosamente.', 'success');
            await recargarEcosistema();
        } else {
            mostrarToastStarfi(data.message || 'No se pudo actualizar la campaña.', 'danger');
        }
    } catch (e) {
        console.error(e);
        mostrarToastStarfi('Error de red al guardar los cambios de la campaña.', 'danger');
    }
}

async function togglePilotoAutomaticoCampana(idCampana, nuevoEstado) {
    try {
        const fd = new FormData();
        fd.append('action', 'toggle_autopilot');
        fd.append('id_campana', idCampana);
        fd.append('piloto_automatico', nuevoEstado ? 1 : 0);
        await fetch('back_campanas.php', { method: 'POST', body: fd });
        recargarEcosistema();
    } catch (e) {
        console.error(e);
    }
}

// Verificar automáticamente si hay campañas APROBADAS con Piloto Automático pendientes de despachar su cuota de hoy (cuando ya llegó su hora_programada)
let VERIFICANDO_AUTOPILOT = false;
async function verificarPilotoAutomaticoDiario() {
    if (ECO_STATE.enviandoCola || VERIFICANDO_AUTOPILOT) return;
    VERIFICANDO_AUTOPILOT = true;
    try {
        const resp = await fetch(`back_campanas.php?action=check_autopilot_due&id_sede=${ECO_STATE.sedeSeleccionada}`);
        const data = await resp.json();
        if (data.status === 'success' && Array.isArray(data.autopilot_campaigns) && data.autopilot_campaigns.length > 0) {
            for (const cap of data.autopilot_campaigns) {
                if (ECO_STATE.enviandoCola) break;
                const rQueue = await fetch(`back_campanas.php?action=get_pending_queue&id_campana=${cap.id_campana}`);
                const dQueue = await rQueue.json();
                if (dQueue.status === 'success' && Array.isArray(dQueue.cola_envio) && dQueue.cola_envio.length > 0) {
                    await procesarColaDeEnvio(cap.id_campana, dQueue.cola_envio, dQueue.cadencia_ms || 1000, 'es');
                }
            }
        }
    } catch (e) {
        console.error('Error verificando Piloto Automático:', e);
    } finally {
        VERIFICANDO_AUTOPILOT = false;
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
    const campObj = ECO_STATE.campanas.find(c => parseInt(c.id, 10) === parseInt(idCampana, 10)) || null;
    const liveImgUrl = (campObj && campObj.header_imagen_path)
        ? `../../${String(campObj.header_imagen_path).replace(/^\/+/, '')}`
        : CAMP_HEADER_PREVIEW_URL;
    const liveImgLabel = (campObj && campObj.header_imagen_path) ? 'Flyer Oficial de la Campaña' : 'Logo Oficial de la Sede';

    let parsedTplJson = null;
    if (campObj && campObj.template_params_json) {
        try { parsedTplJson = JSON.parse(campObj.template_params_json); } catch (e) { }
    }

    lblEstado.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i>Despachando Tanda Diaria en vivo...`;
    lblContador.textContent = `0 / ${total}`;
    barProgreso.style.width = '0%';
    boxConsola.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    for (let i = 0; i < total; i++) {
        if (ECO_STATE.detenerSolicitado) {
            logDiv.innerHTML = `<div class="text-warning">⏸ Envío pausado manualmente por el operador. Los restantes quedan como PENDIENTE.</div>` + logDiv.innerHTML;
            break;
        }

        const item = cola[i];
        lblContador.textContent = `${i + 1} / ${total}`;
        const pct = Math.round(((i + 1) / total) * 100);
        barProgreso.style.width = `${pct}%`;

        // Actualizar en vivo el teléfono central con el nombre, número y variables de la plantilla de la campaña
        actualizarVistaPreviaCampana({
            nombre: item.nombre || 'Cliente',
            telefono: item.telefono || '',
            templateName: campObj?.template_name || undefined,
            headerFormat: campObj?.header_format || undefined,
            bodyParams: parsedTplJson?.body_params || ['@NOMBRE_CLIENTE', campObj?.param_cuerpo || '', campObj?.param_eslogan || ''],
            headerTextParam: parsedTplJson?.header_text_param || '',
            bodyTemplate: parsedTplJson?.body_template || undefined,
            footerText: parsedTplJson?.footer_text || undefined,
            imgUrl: liveImgUrl,
            imgLabel: liveImgLabel,
            nombreCampana: campObj?.nombre_campana || `Campaña #${idCampana}`,
            progreso: `${i + 1}/${total}`
        });

        try {
            const fd = new FormData();
            fd.append('action', 'dispatch_queue_item');
            fd.append('id_campana', idCampana);
            fd.append('id_destinatario', item.id_destinatario);
            fd.append('language_code', langCode);

            const r = await fetch('back_campanas.php', { method: 'POST', body: fd });
            const rawText = await r.text();
            let res = null;
            try {
                res = JSON.parse(rawText);
            } catch (parseErr) {
                const jsonMatch = rawText.match(/\{[\s\S]*\}$/);
                if (jsonMatch) {
                    res = JSON.parse(jsonMatch[0]);
                } else {
                    throw new Error(rawText ? rawText.substring(0, 120) : `HTTP ${r.status}`);
                }
            }

            if (res.status === 'success') {
                enviadosOk++;
                logDiv.innerHTML = `<div class="text-success">✔ [${i + 1}/${total}] Enviado a ${escapeHtml(item.nombre)} (${escapeHtml(res.telefono)}) - $${parseFloat(res.costo_usd).toFixed(4)}</div>` + logDiv.innerHTML;
            } else if (res.status === 'already_processed') {
                logDiv.innerHTML = `<div class="text-muted">⏭ [${i + 1}/${total}] Omitido ${escapeHtml(item.telefono)}: ${escapeHtml(res.message || 'Ya procesado previamente')}</div>` + logDiv.innerHTML;
                continue;
            } else if (res.status === 'quota_reached' || res.status === 'budget_reached') {
                logDiv.innerHTML = `<div class="text-warning fw-bold">🛡 ${escapeHtml(res.message)}</div>` + logDiv.innerHTML;
                break;
            } else {
                fallidos++;
                logDiv.innerHTML = `<div class="text-danger">✖ [${i + 1}/${total}] Falló ${escapeHtml(item.telefono)}: ${escapeHtml(res.message || 'Error')}</div>` + logDiv.innerHTML;
            }
        } catch (e) {
            fallidos++;
            const errDet = (e && e.message) ? ` (${escapeHtml(e.message)})` : '';
            logDiv.innerHTML = `<div class="text-danger">✖ Error de red enviando a ${escapeHtml(item.telefono)}${errDet}</div>` + logDiv.innerHTML;
        }

        if (i < total - 1 && !ECO_STATE.detenerSolicitado) {
            await new Promise(resolve => setTimeout(resolve, cadenciaMs));
        }
    }

    // Marcar fin del lote en el backend (espera webhooks asíncronos de Meta para reportar entregas/fallos reales)
    lblEstado.innerHTML = `<i class="fa-solid fa-satellite-dish fa-fade text-info me-1"></i>Verificando estado de entrega con Meta...`;
    const fdFin = new FormData();
    fdFin.append('action', 'finish_campaign_batch');
    fdFin.append('id_campana', idCampana);
    const respFin = await fetch('back_campanas.php', { method: 'POST', body: fdFin });
    const dataFin = await respFin.json().catch(() => ({}));

    if (Array.isArray(dataFin.errores_async) && dataFin.errores_async.length > 0) {
        enviadosOk = parseInt(dataFin.enviados ?? enviadosOk, 10);
        fallidos = parseInt(dataFin.fallidos ?? fallidos, 10);
        dataFin.errores_async.forEach(ea => {
            logDiv.innerHTML = `<div class="text-danger fw-semibold">⚠ Webhook Meta (${escapeHtml(ea.telefono)} - ${escapeHtml(ea.nombre)}): ${escapeHtml(ea.error_detalle)}</div>` + logDiv.innerHTML;
        });
    }

    ECO_STATE.enviandoCola = false;
    btnDetener.classList.add('d-none');
    btnLanzar.disabled = false;
    lblEstado.innerHTML = `<i class="fa-solid fa-circle-check text-success me-1"></i>Lote finalizado (${enviadosOk} enviados, ${fallidos} fallidos)`;
    actualizarVistaPreviaCampana(null);

    mostrarToastStarfi(
        `Difusión finalizada: <strong>${enviadosOk} enviados</strong>${fallidos > 0 ? `, <span class="text-warning">${fallidos} fallidos</span>` : ''} de ${total} destinatarios.`,
        fallidos === 0 ? 'success' : (enviadosOk > 0 ? 'warning' : 'danger')
    );

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
        mostrarToastStarfi('Ya hay un envío en curso en este momento.', 'warning');
        return;
    }
    try {
        const fd = new FormData();
        fd.append('action', 'get_pending_queue');
        fd.append('id_campana', idCampana);
        const fileImg = document.getElementById('campHeaderImagen');
        if (fileImg && fileImg.files && fileImg.files.length > 0) {
            fd.append('header_imagen', fileImg.files[0]);
        }

        const resp = await fetch('back_campanas.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.status !== 'success') {
            mostrarToastStarfi(data.message || 'No se pudo obtener la cola pendiente.', 'warning');
            return;
        }
        if (fileImg && fileImg.files && fileImg.files.length > 0) {
            limpiarImagenCabecera();
        }
        const cola = data.cola_envio || [];
        if (cola.length === 0) {
            mostrarToastStarfi('Esta campaña ya no tiene destinatarios pendientes.', 'info');
            recargarEcosistema();
            return;
        }
        const ok = await mostrarDialogoStarfi({
            badge: 'EJECUTAR TANDA DIARIA',
            badgeColor: '#F59E0B',
            title: `¿Reanudar "${data.nombre_campana}"?`,
            icon: 'fa-solid fa-play',
            iconBg: 'rgba(245, 158, 11, 0.2)',
            iconColor: '#FBBF24',
            confirmText: `<i class="fa-solid fa-paper-plane me-1"></i>Despachar ${cola.length} Contactos`,
            confirmColor: '#E85B14',
            cancelText: 'Cancelar',
            bodyHtml: `<p class="mb-0">Se despachará el lote de <strong>${cola.length} destinatarios pendientes</strong> respetando el ritmo y la cuota diaria de protección Anti-Bloqueo de la sede.</p>`
        });
        if (!ok) return;
        await procesarColaDeEnvio(idCampana, cola, data.cadencia_ms || 1000, 'es');
    } catch (e) {
        console.error(e);
        mostrarToastStarfi('Error al reanudar la campaña.', 'danger');
    }
}

// =========================================================================
// TABLA DE CAMPAÑAS Y MODAL DE AUDITORÍA DE DESTINATARIOS (LEÍDOS Y RESPONDIDOS)
// =========================================================================
function filtrarHistorialCampanas(filtro, btnEl) {
    ECO_STATE.filtroHistorial = filtro;
    ECO_STATE.paginaHistorial = 1;
    if (btnEl) {
        const group = btnEl.parentElement.querySelectorAll('.btn');
        group.forEach(b => b.classList.remove('active'));
        btnEl.classList.add('active');
    }
    renderizarTablaCampanas();
}

function cambiarPaginaHistorialCampanas(nuevaPagina) {
    ECO_STATE.paginaHistorial = Math.max(1, parseInt(nuevaPagina, 10) || 1);
    renderizarTablaCampanas();
}

function renderizarTablaCampanas() {
    const tbody = document.getElementById('tbodyCampanas');
    const lblPag = document.getElementById('lblResumenPaginacionCampanas');
    const ctrlPag = document.getElementById('controlesPaginacionCampanas');
    const filtroHist = ECO_STATE.filtroHistorial || 'TODAS';
    const campanasFiltradas = ECO_STATE.campanas.filter(c => {
        if (filtroHist === 'TODAS') return true;
        if (filtroHist === 'PENDIENTE_APROBACION') return c.estado === 'PENDIENTE_APROBACION';
        if (filtroHist === 'EN_CURSO') return parseInt(c.pendientes || 0, 10) > 0 && c.estado !== 'PENDIENTE_APROBACION' && c.estado !== 'CANCELADA';
        if (filtroHist === 'COMPLETADA') return parseInt(c.pendientes || 0, 10) === 0 && c.estado !== 'PENDIENTE_APROBACION' && c.estado !== 'CANCELADA';
        return true;
    });

    const totalRegistros = campanasFiltradas.length;
    document.getElementById('badgeTotalCampanas').textContent = `${totalRegistros} campañas`;

    if (totalRegistros === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center py-5 text-muted">
                    <i class="fa-solid fa-bullhorn fs-3 d-block mb-2 text-secondary opacity-50"></i>
                    No hay campañas en esta categoría para la sede seleccionada.
                </td>
            </tr>
        `;
        if (lblPag) lblPag.textContent = 'Mostrando 0 de 0 campañas';
        if (ctrlPag) ctrlPag.innerHTML = '';
        return;
    }

    const porPagina = ECO_STATE.porPaginaHistorial || 6;
    const totalPaginas = Math.max(1, Math.ceil(totalRegistros / porPagina));
    if (!ECO_STATE.paginaHistorial || ECO_STATE.paginaHistorial > totalPaginas) {
        ECO_STATE.paginaHistorial = totalPaginas;
    }
    const pagActual = Math.max(1, ECO_STATE.paginaHistorial);
    const inicioIdx = (pagActual - 1) * porPagina;
    const finIdx = Math.min(inicioIdx + porPagina, totalRegistros);
    const campanasPagina = campanasFiltradas.slice(inicioIdx, finIdx);

    if (lblPag) {
        lblPag.innerHTML = `Mostrando <strong>${inicioIdx + 1}-${finIdx}</strong> de <strong>${totalRegistros}</strong>`;
    }
    if (ctrlPag) {
        if (totalPaginas <= 1) {
            ctrlPag.innerHTML = `<span class="badge bg-white text-secondary border">Página 1 / 1</span>`;
        } else {
            let btnsPag = `
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" ${pagActual <= 1 ? 'disabled' : ''} title="Página anterior" onclick="cambiarPaginaHistorialCampanas(${pagActual - 1})">
                    <i class="fa-solid fa-chevron-left" style="font-size:0.7rem;"></i>
                </button>
            `;
            for (let p = 1; p <= totalPaginas; p++) {
                if (p === 1 || p === totalPaginas || Math.abs(p - pagActual) <= 1) {
                    btnsPag += `
                        <button type="button" class="btn btn-sm ${p === pagActual ? 'btn-dark fw-bold' : 'btn-outline-secondary'} py-0 px-2" style="font-size:0.74rem;" onclick="cambiarPaginaHistorialCampanas(${p})">${p}</button>
                    `;
                } else if (Math.abs(p - pagActual) === 2) {
                    btnsPag += `<span class="text-muted px-1">…</span>`;
                }
            }
            btnsPag += `
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" ${pagActual >= totalPaginas ? 'disabled' : ''} title="Página siguiente" onclick="cambiarPaginaHistorialCampanas(${pagActual + 1})">
                    <i class="fa-solid fa-chevron-right" style="font-size:0.7rem;"></i>
                </button>
            `;
            ctrlPag.innerHTML = btnsPag;
        }
    }

    let html = '';
    campanasPagina.forEach(c => {
        const total = parseInt(c.total_destinatarios || 0, 10);
        const pend = parseInt(c.pendientes || 0, 10);
        const env = parseInt(c.enviados || 0, 10);
        const ent = parseInt(c.entregados || 0, 10);
        const lei = parseInt(c.leidos || 0, 10);
        const resp = parseInt(c.respondidos || 0, 10);
        const fal = parseInt(c.fallidos || 0, 10);
        const pctExito = total > 0 ? Math.round((env / total) * 100) : 0;
        const pctFallido = total > 0 ? Math.round((fal / total) * 100) : 0;
        const costoUnit = parseFloat(c.costo_unitario_usd || 0.07);
        const costoReal = parseFloat(c.costo_real_usd || 0).toFixed(2);
        const costoEst = parseFloat(c.costo_estimado_usd || (total * costoUnit)).toFixed(2);
        const cuotaProg = parseInt(c.cuota_diaria_programada || 250, 10);
        const tandas = parseInt(c.total_tandas || Math.max(1, Math.ceil(total / cuotaProg)), 10);
        const piloto = parseInt(c.piloto_automatico ?? 1, 10) === 1;
        const horaProg12 = formatearHora12h(c.hora_programada || '09:00');
        const esPendienteAprob = (c.estado === 'PENDIENTE_APROBACION');
        const esCancelada = (c.estado === 'CANCELADA');

        let badgeEstado = `<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-circle-check me-1"></i>Completada</span>`;
        if (esCancelada) {
            badgeEstado = `<span class="badge bg-danger-subtle text-danger border border-danger-subtle">Cancelada</span>`;
        } else if (esPendienteAprob) {
            badgeEstado = `
                <span class="badge bg-warning text-dark border border-warning">
                    <i class="fa-solid fa-hourglass-half me-1"></i>Por Aprobar
                </span>
                <div class="text-muted mt-1" style="font-size:0.68rem;">${tandas} ${tandas === 1 ? 'tanda' : 'tandas'} (${cuotaProg}/día)<br><i class="fa-regular fa-clock"></i> ${horaProg12}</div>
            `;
        } else if (pend > 0) {
            if (piloto) {
                badgeEstado = `
                    <span class="badge bg-info-subtle text-dark border border-info">
                        <i class="fa-solid fa-robot text-primary me-1"></i>Auto (${pend} pend.)
                    </span>
                    <div class="text-muted mt-1" style="font-size:0.68rem;">${cuotaProg}/día • <i class="fa-regular fa-clock"></i> ${horaProg12}</div>
                `;
            } else {
                badgeEstado = `<span class="badge bg-warning-subtle text-dark border border-warning">${pend} en Cola</span>`;
            }
        } else if (c.estado === 'EN_PROCESO' || c.estado === 'APROBADA_EN_PROCESO') {
            badgeEstado = `<span class="badge bg-primary-subtle text-primary">En Proceso</span>`;
        }

        let botonesAccion = '';
        if (esPendienteAprob) {
            botonesAccion = `
                <button type="button" class="btn btn-success camp-action-icon-btn" data-tooltip="Aprobar campaña e iniciar envío" aria-label="Aprobar campaña e iniciar envío" onclick="aprobarEIniciarCampana(${c.id})">
                    <i class="fa-solid fa-check"></i>
                </button>
                <button type="button" class="btn btn-outline-warning text-dark camp-action-icon-btn" data-tooltip="Editar cuota diaria, tandas, audiencia o mensaje" aria-label="Editar campaña" onclick="abrirModalEditarCampana(${c.id})">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button type="button" class="btn btn-outline-danger camp-action-icon-btn" data-tooltip="Cancelar campaña" aria-label="Cancelar campaña" onclick="cancelarCampanaPendiente(${c.id})">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            `;
        } else if (!esCancelada && pend > 0) {
            botonesAccion = `
                <button type="button" class="btn btn-warning camp-action-icon-btn" data-tooltip="Ejecutar tanda diaria pendiente ahora" aria-label="Ejecutar tanda diaria pendiente ahora" onclick="reanudarCampanaPendiente(${c.id})">
                    <i class="fa-solid fa-play"></i>
                </button>
                <button type="button" class="btn btn-outline-warning text-dark camp-action-icon-btn" data-tooltip="Editar cuota diaria, tandas o pendientes" aria-label="Editar campaña" onclick="abrirModalEditarCampana(${c.id})">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button type="button" class="btn ${piloto ? 'btn-outline-info' : 'btn-outline-secondary'} camp-action-icon-btn" data-tooltip="${piloto ? 'Piloto Automático Activo (clic para pausar)' : 'Piloto Automático Pausado (clic para activar)'}" aria-label="Alternar Piloto Automático" onclick="togglePilotoAutomaticoCampana(${c.id}, ${!piloto})">
                    <i class="fa-solid fa-robot"></i>
                </button>
            `;
        } else if (!esCancelada) {
            const tooltipRetry = fal > 0 ? `Reintentar fallidos (${fal})` : 'Reenviar o cambiar flyer';
            const badgeDot = fal > 0 ? `<span class="camp-action-badge-dot">${fal}</span>` : '';
            botonesAccion = `
                <button type="button" class="btn ${fal > 0 ? 'btn-outline-warning' : 'btn-outline-success'} camp-action-icon-btn" data-tooltip="${tooltipRetry}" aria-label="${tooltipRetry}" onclick="aprobarEIniciarCampana(${c.id})">
                    <i class="fa-solid fa-rotate-right"></i>
                    ${badgeDot}
                </button>
            `;
        }

        const headerFmt = String(c.header_format || 'IMAGE').toUpperCase();
        const tieneFlyerCustom = !!(c.header_imagen_path && String(c.header_imagen_path).trim() !== '');
        let badgeImagenCamp = '';
        if (headerFmt !== 'IMAGE') {
            badgeImagenCamp = `<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size:0.63rem;" title="Plantilla: ${escapeHtml(c.template_name || '')}"><i class="fa-solid fa-align-left me-1"></i>Solo Texto</span>`;
        } else if (tieneFlyerCustom) {
            badgeImagenCamp = `<span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:0.63rem;" title="${escapeHtml(c.header_imagen_path)}"><i class="fa-solid fa-image me-1"></i>Flyer Propio</span>`;
        } else {
            badgeImagenCamp = `<span class="badge bg-light text-secondary border" style="font-size:0.63rem;"><i class="fa-regular fa-image me-1"></i>Logo Sede</span>`;
        }

        const pillFallidosTabla = fal > 0
            ? `<span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1" style="font-size:0.62rem;" title="${fal} mensaje(s) fallido(s)">${fal} falló</span>`
            : '';

        html += `
            <tr>
                <td class="ps-3" style="max-width: 195px;">
                    <div class="fw-bold text-dark text-truncate" title="${escapeHtml(c.nombre_campana)}">${escapeHtml(c.nombre_campana)}</div>
                    <div class="d-flex align-items-center flex-wrap gap-1 mt-1">
                        ${badgeImagenCamp}
                        <span class="text-muted" style="font-size: 0.7rem;"><i class="fa-solid fa-building me-1"></i>${escapeHtml(c.nombre_sede || 'Sede')}</span>
                    </div>
                    <div class="text-muted" style="font-size: 0.67rem;">${escapeHtml(c.fecha_creacion || '')}</div>
                </td>
                <td class="text-center">
                    <div class="fw-bold text-dark">
                        ${env} / ${total} <span class="text-muted fw-normal" style="font-size:0.72rem;">env.</span>${pillFallidosTabla}
                    </div>
                    <div class="progress mx-auto my-1" style="height: 5px; width: 70px; background: #E2E8F0;" title="${env} enviados, ${fal} fallidos de ${total}">
                        <div class="progress-bar ${esPendienteAprob ? 'bg-warning' : 'bg-success'}" style="width: ${pctExito}%;"></div>
                        ${fal > 0 ? `<div class="progress-bar bg-danger" style="width: ${pctFallido}%;"></div>` : ''}
                    </div>
                    <div style="font-size:0.72rem;">
                        <strong style="color:#EA580C;">$${esPendienteAprob ? costoEst : costoReal}</strong>
                        <span class="text-muted">(${esPendienteAprob ? 'Est.' : 'Real'})</span>
                    </div>
                </td>
                <td class="text-center">${badgeEstado}</td>
                <td class="text-end pe-3" style="white-space: nowrap;">
                    <div class="d-inline-flex align-items-center justify-content-end gap-1">
                        ${botonesAccion}
                        <button type="button" class="btn btn-outline-primary camp-action-icon-btn" data-tooltip="Embudo de Conversión & Auditoría" aria-label="Embudo de Conversión y Auditoría" onclick="verDetalleDestinatariosCampana(${c.id})">
                            <i class="fa-solid fa-chart-simple"></i>
                        </button>
                    </div>
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
            mostrarToastStarfi(data.message || 'No se pudo cargar el detalle de la campaña.', 'danger');
            return;
        }

        const camp = data.campana || {};
        ECO_STATE.campanaModalActual = camp;
        ECO_STATE.destinatariosModalCache = data.destinatarios || [];

        document.getElementById('detTituloCampana').textContent = `${camp.nombre_campana || 'Campaña'} — Embudo de Destinatarios`;
        document.getElementById('detSubtituloCampana').textContent = `Sede: ${camp.nombre_sede || ''} (${camp.linea_telefono || ''}) | Plantilla: ${camp.template_name || ''}`;

        const totalEnv = parseInt(camp.enviados || 0, 10);
        const totalEnt = parseInt(camp.entregados || 0, 10);
        const totalLei = parseInt(camp.leidos || 0, 10);
        const totalRes = parseInt(camp.respondidos || 0, 10);
        const totalFal = parseInt(camp.fallidos || 0, 10);
        const costoRealModal = parseFloat(camp.costo_real_usd || 0).toFixed(2);
        const costoEstModal = parseFloat(camp.costo_estimado_usd || ((camp.total_destinatarios || 0) * parseFloat(camp.costo_unitario_usd || 0.077))).toFixed(2);
        const pctEnt = totalEnv > 0 ? Math.round((totalEnt / totalEnv) * 100) : 0;
        const pctLei = totalEnv > 0 ? Math.round((totalLei / totalEnv) * 100) : 0;
        const pctRes = totalEnv > 0 ? Math.round((totalRes / totalEnv) * 100) : 0;

        const elEnv = document.getElementById('detKpiEnviados');
        const elEnt = document.getElementById('detKpiEntregados');
        const elLei = document.getElementById('detKpiLeidos');
        const elRes = document.getElementById('detKpiRespondidos');
        const elFal = document.getElementById('detKpiFallidos');
        const elCos = document.getElementById('detKpiCosto');
        if (elEnv) elEnv.innerHTML = `${totalEnv} <small class="text-muted fw-normal" style="font-size:0.7rem;">/ ${camp.total_destinatarios || 0}</small>`;
        if (elEnt) elEnt.innerHTML = `${totalEnt} <small class="text-muted fw-normal" style="font-size:0.7rem;">(${pctEnt}%)</small>`;
        if (elLei) elLei.innerHTML = `${totalLei} <small class="text-info fw-semibold" style="font-size:0.7rem;">(${pctLei}%)</small>`;
        if (elRes) elRes.innerHTML = `${totalRes} <small class="text-success fw-semibold" style="font-size:0.7rem;">(${pctRes}%)</small>`;
        if (elFal) elFal.innerHTML = `${totalFal}`;
        if (elCos) elCos.innerHTML = `$${costoRealModal} <small class="text-muted fw-normal d-block" style="font-size:0.65rem;">Est: $${costoEstModal}</small>`;

        const inpBuscarModal = document.getElementById('detInputBuscarModal');
        if (inpBuscarModal) inpBuscarModal.value = '';
        ECO_STATE.modalFiltroEstado = 'TODOS';
        ECO_STATE.modalBuscarTexto = '';
        ECO_STATE.modalPaginaActual = 1;
        ECO_STATE.modalPageSize = parseInt(document.getElementById('detSelectPageSizeModal')?.value || 10, 10);

        const btnsGrp = document.querySelectorAll('#detGrupoFiltrosEstadoModal .btn');
        btnsGrp.forEach((b, idx) => b.classList.toggle('active', idx === 0));

        filtrarTablaDestinatariosModal('TODOS', null);
        const modal = new bootstrap.Modal(document.getElementById('modalDetalleCampana'));
        modal.show();
    } catch (e) {
        console.error(e);
    }
}

function buscarEnDestinatariosModal(texto) {
    ECO_STATE.modalBuscarTexto = String(texto || '').trim().toLowerCase();
    ECO_STATE.modalPaginaActual = 1;
    renderizarTablaDestinatariosModalPaginada();
}

function cambiarPageSizeDestinatariosModal(nuevoSize) {
    ECO_STATE.modalPageSize = Math.max(5, parseInt(nuevoSize || 10, 10));
    ECO_STATE.modalPaginaActual = 1;
    renderizarTablaDestinatariosModalPaginada();
}

function cambiarPaginaDestinatariosModal(nuevaPagina) {
    ECO_STATE.modalPaginaActual = Math.max(1, parseInt(nuevaPagina || 1, 10));
    renderizarTablaDestinatariosModalPaginada();
}

function filtrarTablaDestinatariosModal(filtro, btnEl) {
    if (btnEl) {
        const group = btnEl.parentElement.querySelectorAll('.btn');
        group.forEach(b => b.classList.remove('active'));
        btnEl.classList.add('active');
    }
    ECO_STATE.modalFiltroEstado = filtro || 'TODOS';
    ECO_STATE.modalPaginaActual = 1;
    renderizarTablaDestinatariosModalPaginada();
}

function obtenerListaFiltradaDestinatariosModal() {
    const filtro = ECO_STATE.modalFiltroEstado || 'TODOS';
    const q = (ECO_STATE.modalBuscarTexto || '').trim().toLowerCase();

    return (ECO_STATE.destinatariosModalCache || []).filter(d => {
        if (filtro !== 'TODOS') {
            if (filtro === 'RESPONDIDO' && !(parseInt(d.respondido, 10) === 1 || d.estado_envio === 'RESPONDIDO')) return false;
            else if (filtro === 'LEIDO' && !(d.fecha_lectura !== null || d.estado_envio === 'LEIDO' || d.estado_envio === 'RESPONDIDO')) return false;
            else if (filtro !== 'RESPONDIDO' && filtro !== 'LEIDO' && d.estado_envio !== filtro) return false;
        }
        if (q) {
            const nom = String(d.nombre || '').toLowerCase();
            const tel = String(d.telefono || '').toLowerCase();
            const sede = String(d.nombre_sede_cliente || '').toLowerCase();
            const resp = String(d.respuesta_texto || '').toLowerCase();
            const tanda = `tanda #${d.numero_tanda || 1}`;
            if (!nom.includes(q) && !tel.includes(q) && !sede.includes(q) && !resp.includes(q) && !tanda.includes(q)) {
                return false;
            }
        }
        return true;
    });
}

function renderizarTablaDestinatariosModalPaginada() {
    const listaFiltrada = obtenerListaFiltradaDestinatariosModal();
    const totalFiltrados = listaFiltrada.length;
    const totalGlobal = (ECO_STATE.destinatariosModalCache || []).length;
    const pageSize = ECO_STATE.modalPageSize || 10;
    const totalPaginas = Math.max(1, Math.ceil(totalFiltrados / pageSize));

    if (!ECO_STATE.modalPaginaActual || ECO_STATE.modalPaginaActual > totalPaginas) {
        ECO_STATE.modalPaginaActual = totalPaginas;
    }
    if (ECO_STATE.modalPaginaActual < 1) {
        ECO_STATE.modalPaginaActual = 1;
    }

    const pagActual = ECO_STATE.modalPaginaActual;
    const startIdx = (pagActual - 1) * pageSize;
    const endIdx = Math.min(startIdx + pageSize, totalFiltrados);
    const paginaItems = listaFiltrada.slice(startIdx, endIdx);

    const lblRes = document.getElementById('detResumenModal');
    if (lblRes) {
        lblRes.textContent = totalFiltrados === 0
            ? `0 coincidencias (de ${totalGlobal} destinatarios)`
            : `Mostrando ${startIdx + 1}–${endIdx} de ${totalFiltrados} destinatarios${totalFiltrados < totalGlobal ? ` (filtrado de ${totalGlobal})` : ''}`;
    }

    const ctrlPag = document.getElementById('detControlesPaginacionModal');
    if (ctrlPag) {
        if (totalFiltrados <= pageSize) {
            ctrlPag.innerHTML = `<span class="badge bg-white text-secondary border">Página 1 de 1</span>`;
        } else {
            let btns = `
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" ${pagActual <= 1 ? 'disabled' : ''} onclick="cambiarPaginaDestinatariosModal(1)" title="Primera página">
                    <i class="fa-solid fa-angles-left" style="font-size:0.7rem;"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" ${pagActual <= 1 ? 'disabled' : ''} onclick="cambiarPaginaDestinatariosModal(${pagActual - 1})" title="Anterior">
                    <i class="fa-solid fa-chevron-left" style="font-size:0.7rem;"></i>
                </button>
            `;
            for (let p = 1; p <= totalPaginas; p++) {
                if (p === 1 || p === totalPaginas || Math.abs(p - pagActual) <= 2) {
                    btns += `<button type="button" class="btn btn-sm ${p === pagActual ? 'btn-dark fw-bold' : 'btn-outline-secondary'} py-0 px-2" style="font-size:0.75rem;" onclick="cambiarPaginaDestinatariosModal(${p})">${p}</button>`;
                } else if (Math.abs(p - pagActual) === 3) {
                    btns += `<span class="text-muted px-1">…</span>`;
                }
            }
            btns += `
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" ${pagActual >= totalPaginas ? 'disabled' : ''} onclick="cambiarPaginaDestinatariosModal(${pagActual + 1})" title="Siguiente">
                    <i class="fa-solid fa-chevron-right" style="font-size:0.7rem;"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" ${pagActual >= totalPaginas ? 'disabled' : ''} onclick="cambiarPaginaDestinatariosModal(${totalPaginas})" title="Última página">
                    <i class="fa-solid fa-angles-right" style="font-size:0.7rem;"></i>
                </button>
            `;
            ctrlPag.innerHTML = btns;
        }
    }

    const tbody = document.getElementById('tbodyDetalleDestinatarios');
    if (paginaItems.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted">No se encontraron destinatarios con este filtro o búsqueda.</td></tr>`;
        return;
    }

    let html = '';
    paginaItems.forEach(d => {
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

        const numTanda = parseInt(d.numero_tanda || 1, 10);
        const fProg = d.fecha_programada ? ` (${escapeHtml(d.fecha_programada)})` : '';
        const infoEnvio = d.fecha_envio ? escapeHtml(d.fecha_envio) : `<span class="badge bg-light text-dark border">Tanda #${numTanda}${fProg}</span>`;

        const sedeCliente = d.nombre_sede_cliente || (ECO_STATE.campanaModalActual?.nombre_sede || 'General');
        const badgeSedeCli = `<span class="badge bg-light text-dark border" style="font-size:0.72rem;"><i class="fa-solid fa-store text-primary me-1"></i>${escapeHtml(sedeCliente)}</span>`;

        const paramsUrlChat = new URLSearchParams();
        if (d.telefono) paramsUrlChat.set('search', d.telefono);
        if (parseInt(d.id_conversacion || 0, 10) > 0) paramsUrlChat.set('conv_id', d.id_conversacion);
        if (parseInt(d.id_cliente || 0, 10) > 0) paramsUrlChat.set('cliente_id', d.id_cliente);
        const btnChat = `<a href="../bandeja/bandeja.php?${paramsUrlChat.toString()}" class="btn btn-sm btn-outline-success py-0 px-2" title="Abrir conversación directa en Bandeja"><i class="fa-brands fa-whatsapp me-1"></i>Chat</a>`;

        html += `
            <tr>
                <td class="fw-semibold">${escapeHtml(d.nombre)}</td>
                <td>${badgeSedeCli}</td>
                <td><code>${escapeHtml(d.telefono)}</code></td>
                <td class="text-center">${badge}</td>
                <td class="small text-muted">${infoEnvio}</td>
                <td class="small text-info fw-semibold">${escapeHtml(d.fecha_lectura || '—')}</td>
                <td>${respTxt}</td>
                <td class="text-end">${btnChat}</td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function exportarDestinatariosCampanaCsv() {
    if (!ECO_STATE.destinatariosModalCache || ECO_STATE.destinatariosModalCache.length === 0) {
        mostrarToastStarfi('No hay datos para exportar.', 'warning');
        return;
    }
    const camp = ECO_STATE.campanaModalActual || {};
    const headers = ['Cliente', 'Sede_Cliente', 'Telefono', 'Estado', 'Tanda', 'Fecha_Envio', 'Fecha_Entrega', 'Fecha_Lectura', 'Respondido', 'Respuesta_Cliente', 'Error_Detalle', 'Costo_USD'];
    const rows = [headers.join(',')];
    ECO_STATE.destinatariosModalCache.forEach(d => {
        const sedeCli = d.nombre_sede_cliente || (camp.nombre_sede || 'General');
        const vals = [
            `"${String(d.nombre || '').replace(/"/g, '""')}"`,
            `"${String(sedeCli).replace(/"/g, '""')}"`,
            `"${String(d.telefono || '')}"`,
            `"${String(d.estado_envio || '')}"`,
            d.numero_tanda || 1,
            `"${String(d.fecha_envio || '')}"`,
            `"${String(d.fecha_entrega || '')}"`,
            `"${String(d.fecha_lectura || '')}"`,
            parseInt(d.respondido, 10) === 1 ? 'SI' : 'NO',
            `"${String(d.respuesta_texto || '').replace(/"/g, '""')}"`,
            `"${String(d.error_detalle || '').replace(/"/g, '""')}"`,
            parseFloat(d.costo_usd || 0).toFixed(4)
        ];
        rows.push(vals.join(','));
    });
    const csvContent = '\uFEFF' + rows.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `reporte_campana_${camp.id || 'starfi'}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// =========================================================================
// CONFIGURACIÓN DE LÍMITE Y RITMO DIARIO POR SEDE
// =========================================================================
function abrirModalFlujoSede() {
    const sedeObj = obtenerSedeActualObj();
    if (!sedeObj) return;

    document.getElementById('cfgLimiteDiario').value = sedeObj.limite_diario_mensajes || 250;
    document.getElementById('cfgCadenciaMs').value = sedeObj.cadencia_ms || 1000;

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

    try {
        const resp = await fetch('back_campanas.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('modalFlujoSede'))?.hide();
            mostrarToastStarfi('Límite y ritmo diario actualizados para la sede.', 'success');
            recargarEcosistema();
        } else {
            mostrarToastStarfi(data.message || 'Error al guardar configuración.', 'danger');
        }
    } catch (e) {
        mostrarToastStarfi('Error de conexión al guardar parámetros.', 'danger');
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
