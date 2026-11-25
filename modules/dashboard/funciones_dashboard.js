// modules/dashboard/funciones_dashboard.js
// Controlador Frontend del Dashboard de Supervisión y Exportación Real SheetJS (.xlsx) — STARFI CRM (QA-STD-GLOBAL-2.1)

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

let chatsChartInstance = null;
let motivosChartInstance = null;
let lastDashboardPayload = null;

$(document).ready(function () {
    loadDashboardData();

    $('#formFiltrosDashboard').on('submit', function (e) {
        e.preventDefault();
        loadDashboardData();
    });

    $('#btnExport').on('click', function () {
        exportDashboardExcel($(this), {
            agentes: true,
            volumen: true,
            motivos: true
        });
    });

    $('#modalDetalleExportDashboard').on('show.bs.modal', function () {
        populateExportModalPreview();
    });

    $('#formExportDashboard').on('submit', function (e) {
        e.preventDefault();
        const $submitBtn = $('#btnConfirmModalExport');
        exportDashboardExcel($submitBtn, {
            agentes: $('#chkSheetAgentes').is(':checked'),
            volumen: $('#chkSheetVolumen').is(':checked'),
            motivos: $('#chkSheetMotivos').is(':checked')
        }, true);
    });

    const toggleBtn = document.getElementById('toggleSidebar');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const sb = document.getElementById('sidebar');
            if (sb) sb.classList.toggle('collapsed');
        });
    }
});

function getActiveDashboardFilters() {
    const idSede = $('#filterSede').val() || 'all';
    const sedeNombre = $('#filterSede option:selected').text().trim() || 'Todas las sedes';
    const fechaDesde = $('#filterFechaDesde').val() || '';
    const fechaHasta = $('#filterFechaHasta').val() || '';
    return {
        id_sede: idSede,
        sede_nombre: sedeNombre,
        fecha_desde: fechaDesde,
        fecha_hasta: fechaHasta
    };
}

function loadDashboardData() {
    const $btn = $('#btnApplyFilters');
    if ($btn.prop('disabled')) return;
    $btn.prop('disabled', true);

    const filters = getActiveDashboardFilters();

    $.ajax({
        url: 'back_dashboard.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'load_kpis',
            id_sede: filters.id_sede,
            fecha_desde: filters.fecha_desde,
            fecha_hasta: filters.fecha_hasta
        },
        success: function (res) {
            if (res && res.status === 'success' && res.data) {
                const data = res.data;
                lastDashboardPayload = data;

                $('#kpiTotalChats').text(String(data.total_chats ?? 0));
                $('#kpiAvgRes').text(String(data.avg_res ?? '0 min'));
                $('#kpiConversion').text(String(data.conversion_rate ?? '0%'));
                $('#kpiCAC').text(String(data.cac ?? '$0.00'));

                $('#kpiLeadScore').text(String(data.lead_score ?? 0));
                $('#leadStarsContainer').html(generateStarsHTML(data.lead_score));

                $('#kpiCsatScore').text(String(data.csat_score ?? 0));
                $('#csatStarsContainer').html(generateStarsHTML(data.csat_score));

                renderOperatorPerformance(Array.isArray(data.operadores) ? data.operadores : []);
                renderChart(Array.isArray(data.chart_data) ? data.chart_data : []);
                renderMotivosChart(Array.isArray(data.motivos_data) ? data.motivos_data : []);
            } else {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atención',
                    text: (res && res.message) ? String(res.message) : 'No se pudieron actualizar las métricas.'
                });
            }
        },
        error: function () {
            Swal.fire({
                icon: 'error',
                title: 'Error de conexión',
                text: 'No fue posible consultar las métricas del panel de supervisión.'
            });
        },
        complete: function () {
            $btn.prop('disabled', false);
        }
    });
}

function populateExportModalPreview() {
    const filters = getActiveDashboardFilters();
    $('#modalExportSedeLabel').text(filters.sede_nombre);
    $('#modalExportRangoLabel').text((filters.fecha_desde || '-') + ' al ' + (filters.fecha_hasta || '-'));

    const $tbody = $('#modalExportResumenBody');
    $tbody.empty();

    if (!lastDashboardPayload) {
        $tbody.append('<tr><td colspan="2" class="text-center text-muted py-3">Consultando datos actualizados...</td></tr>');
        return;
    }

    const rows = [
        ['Volumen Total de Chats', lastDashboardPayload.total_chats ?? 0],
        ['Ciclo de Ventas Promedio', lastDashboardPayload.avg_res ?? '0 min'],
        ['Tasa de Conversión Comercial', lastDashboardPayload.conversion_rate ?? '0%'],
        ['Costo de Adquisición (CAC)', lastDashboardPayload.cac ?? '$0.00'],
        ['Gasto WABA Consolidado (USD)', '$' + Number(lastDashboardPayload.gasto_real_usd || 0).toFixed(4)],
        ['Lead Scoring Promedio (Operador)', lastDashboardPayload.lead_score ?? 0],
        ['CSAT Promedio (Cliente)', lastDashboardPayload.csat_score ?? 0]
    ];

    rows.forEach(function (pair) {
        $tbody.append(
            '<tr>' +
                '<td>' + escapeHtml(pair[0]) + '</td>' +
                '<td class="text-end fw-bold">' + escapeHtml(pair[1]) + '</td>' +
            '</tr>'
        );
    });
}

function exportDashboardExcel($triggerBtn, sheetOpts, closeModalOnFinish) {
    const $btnMain = $('#btnExport');
    if ($btnMain.prop('disabled') || ($triggerBtn && $triggerBtn.prop('disabled'))) {
        return;
    }

    if (typeof window.XLSX === 'undefined' || !window.XLSX.utils) {
        Swal.fire({
            icon: 'error',
            title: 'Motor Excel no disponible',
            text: 'No se encontró la librería local SheetJS para generar el archivo .xlsx.'
        });
        return;
    }

    $btnMain.prop('disabled', true);
    if ($triggerBtn) {
        $triggerBtn.prop('disabled', true);
    }

    const filters = getActiveDashboardFilters();
    const opts = Object.assign({ agentes: true, volumen: true, motivos: true }, sheetOpts || {});

    $.ajax({
        url: 'back_dashboard.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'load_kpis',
            id_sede: filters.id_sede,
            fecha_desde: filters.fecha_desde,
            fecha_hasta: filters.fecha_hasta
        },
        success: function (res) {
            if (!res || res.status !== 'success' || !res.data) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No se pudo exportar',
                    text: (res && res.message) ? String(res.message) : 'Respuesta inválida al consolidar los KPIs.'
                });
                return;
            }

            try {
                const data = res.data;
                lastDashboardPayload = data;

                const wb = window.XLSX.utils.book_new();
                const generadoEn = new Date().toISOString().replace('T', ' ').substring(0, 19);

                // Hoja 1: Resumen_KPIs
                const resumenAoA = [
                    ['STARFI CRM — Reporte Ejecutivo de Métricas y KPIs · Generado por Starfi'],
                    ['Sede / Sucursal', String(filters.sede_nombre)],
                    ['Fecha Inicio', String(filters.fecha_desde || 'N/A')],
                    ['Fecha Fin', String(filters.fecha_hasta || 'N/A')],
                    ['Fecha de Generación', generadoEn],
                    [],
                    ['Indicador Clave (KPI)', 'Valor Consolidado'],
                    ['Volumen Total de Chats', Number(data.total_chats || 0)],
                    ['Ciclo de Ventas Promedio', String(data.avg_res || '0 min')],
                    ['Tasa de Conversión', String(data.conversion_rate || '0%')],
                    ['Costo de Adquisición (CAC)', String(data.cac || '$0.00')],
                    ['Gasto WABA Consolidado (USD)', Number(data.gasto_real_usd || 0)],
                    ['Lead Scoring Promedio (1-5)', Number(data.lead_score || 0)],
                    ['CSAT Promedio Cliente (1-5)', Number(data.csat_score || 0)]
                ];
                const wsResumen = window.XLSX.utils.aoa_to_sheet(resumenAoA);
                wsResumen['!cols'] = [{ wch: 42 }, { wch: 28 }];
                window.XLSX.utils.book_append_sheet(wb, wsResumen, 'Resumen_KPIs');

                // Hoja 2: Rendimiento_Agentes
                if (opts.agentes) {
                    const ops = Array.isArray(data.operadores) ? data.operadores : [];
                    const totalOpChats = ops.reduce(function (acc, o) {
                        return acc + Number(o.chats_atendidos || 0);
                    }, 0);
                    const agentesAoA = [
                        ['STARFI CRM — Rendimiento por Operador · Generado por Starfi'],
                        ['Operador / Agente', 'Chats Atendidos', 'Participación (%)']
                    ];
                    if (ops.length === 0) {
                        agentesAoA.push(['Sin registros en el periodo seleccionado', 0, '0.0%']);
                    } else {
                        ops.forEach(function (op) {
                            const atendidos = Number(op.chats_atendidos || 0);
                            const pct = totalOpChats > 0 ? ((atendidos / totalOpChats) * 100).toFixed(1) + '%' : '0.0%';
                            agentesAoA.push([String(op.nombre_completo || 'Operador'), atendidos, pct]);
                        });
                    }
                    const wsAgentes = window.XLSX.utils.aoa_to_sheet(agentesAoA);
                    wsAgentes['!cols'] = [{ wch: 36 }, { wch: 20 }, { wch: 20 }];
                    window.XLSX.utils.book_append_sheet(wb, wsAgentes, 'Rendimiento_Agentes');
                }

                // Hoja 3: Volumen_Diario
                if (opts.volumen) {
                    const volRows = Array.isArray(data.chart_data) ? data.chart_data : [];
                    const volumenAoA = [
                        ['STARFI CRM — Volumen Diario de Conversaciones · Generado por Starfi'],
                        ['Fecha', 'Conversaciones Iniciadas']
                    ];
                    if (volRows.length === 0) {
                        volumenAoA.push(['Sin actividad en el rango seleccionado', 0]);
                    } else {
                        volRows.forEach(function (item) {
                            volumenAoA.push([String(item.fecha || ''), Number(item.volumen || 0)]);
                        });
                    }
                    const wsVolumen = window.XLSX.utils.aoa_to_sheet(volumenAoA);
                    wsVolumen['!cols'] = [{ wch: 24 }, { wch: 26 }];
                    window.XLSX.utils.book_append_sheet(wb, wsVolumen, 'Volumen_Diario');
                }

                // Hoja 4: Motivos_Cierre
                if (opts.motivos) {
                    const motRows = Array.isArray(data.motivos_data) ? data.motivos_data : [];
                    const motivosAoA = [
                        ['STARFI CRM — Distribución de Motivos de Cierre · Generado por Starfi'],
                        ['Motivo Comercial', 'Cantidad de Conversaciones']
                    ];
                    if (motRows.length === 0) {
                        motivosAoA.push(['Sin cierres registrados en el rango', 0]);
                    } else {
                        motRows.forEach(function (m) {
                            const motivoLimpio = String(m.motivo || 'NO_ESPECIFICADO').replace(/_/g, ' ');
                            motivosAoA.push([motivoLimpio, Number(m.cantidad || 0)]);
                        });
                    }
                    const wsMotivos = window.XLSX.utils.aoa_to_sheet(motivosAoA);
                    wsMotivos['!cols'] = [{ wch: 34 }, { wch: 28 }];
                    window.XLSX.utils.book_append_sheet(wb, wsMotivos, 'Motivos_Cierre');
                }

                const fechaStr = ((filters.fecha_desde || 'inicio') + '_a_' + (filters.fecha_hasta || 'hoy')).replace(/[^0-9A-Za-z_-]/g, '');
                const fileName = 'Reporte_KPIs_STARFI_CRM_' + fechaStr + '.xlsx';
                window.XLSX.writeFile(wb, fileName);

                // Registrar auditoría formal en backend
                $.post('back_dashboard.php', {
                    action: 'audit_export_excel',
                    fecha_inicio: filters.fecha_desde,
                    fecha_fin: filters.fecha_hasta,
                    id_sede: filters.id_sede
                });

                if (closeModalOnFinish) {
                    $('#modalDetalleExportDashboard').modal('hide');
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Reporte Excel Generado',
                    text: 'Archivo descargado correctamente: ' + fileName,
                    timer: 2200,
                    showConfirmButton: false
                });
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error al generar Excel',
                    text: 'Ocurrió un inconveniente al construir el libro de trabajo .xlsx.'
                });
            }
        },
        error: function () {
            Swal.fire({
                icon: 'error',
                title: 'Error de red',
                text: 'No se pudo obtener la información para exportar el reporte.'
            });
        },
        complete: function () {
            $btnMain.prop('disabled', false);
            if ($triggerBtn) {
                $triggerBtn.prop('disabled', false);
            }
        }
    });
}

function generateStarsHTML(score) {
    const numScore = parseFloat(score) || 0;
    let html = '';
    for (let i = 1; i <= 5; i++) {
        if (i <= Math.floor(numScore)) {
            html += '<i class="fa-solid fa-star"></i>';
        } else if (i === Math.ceil(numScore) && !Number.isInteger(numScore)) {
            html += '<i class="fa-solid fa-star-half-stroke"></i>';
        } else {
            html += '<i class="fa-regular fa-star"></i>';
        }
    }
    return html;
}

function renderMotivosChart(chartData) {
    const canvas = document.getElementById('motivosChart');
    if (!canvas) return;

    if (motivosChartInstance) {
        motivosChartInstance.destroy();
    }

    const ctx = canvas.getContext('2d');

    if (!chartData || chartData.length === 0) {
        motivosChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: ['Sin datos'], datasets: [{ data: [1], backgroundColor: ['#E2E8F0'] }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%' }
        });
        return;
    }

    const labels = [];
    const values = [];
    const bgColors = [];

    chartData.forEach(function (item) {
        const rawMotivo = String(item.motivo || 'NO_ESPECIFICADO');
        labels.push(rawMotivo.replace(/_/g, ' '));
        values.push(Number(item.cantidad || 0));

        if (rawMotivo === 'VENTA_CERRADA') bgColors.push('#10B981');
        else if (rawMotivo === 'DUDA_RESUELTA') bgColors.push('#3B82F6');
        else if (rawMotivo === 'NO_INTERESADO') bgColors.push('#EF4444');
        else if (rawMotivo === 'NO_ESPECIFICADO') bgColors.push('#9CA3AF');
        else bgColors.push('#F59E0B');
    });

    motivosChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: bgColors,
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } }
            },
            cutout: '70%'
        }
    });
}

function renderChart(chartData) {
    const canvas = document.getElementById('chatsChart');
    if (!canvas) return;

    if (chatsChartInstance) {
        chatsChartInstance.destroy();
    }

    const ctx = canvas.getContext('2d');

    if (!chartData || chartData.length === 0) {
        chatsChartInstance = new Chart(ctx, {
            type: 'bar',
            data: { labels: ['Sin datos'], datasets: [{ label: 'Volumen', data: [0], backgroundColor: '#CBD5E1' }] },
            options: { responsive: true, maintainAspectRatio: false }
        });
        return;
    }

    const labels = [];
    const values = [];

    chartData.forEach(function (item) {
        const rawFecha = String(item.fecha || '');
        const dateParts = rawFecha.split('-');
        const shortDate = dateParts.length === 3 ? (dateParts[2] + '/' + dateParts[1]) : rawFecha;
        labels.push(shortDate);
        values.push(Number(item.volumen || 0));
    });

    chatsChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Volumen de Chats',
                data: values,
                backgroundColor: '#E85B14',
                borderRadius: 4,
                barPercentage: 0.6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true, grid: { display: true, color: '#f0f2f5' }, border: { display: false } },
                x: { grid: { display: false }, border: { display: false } }
            }
        }
    });
}

function renderOperatorPerformance(operadores) {
    const container = $('#operatorPerformanceContainer');
    if (!container.length) return;
    container.empty();

    if (!Array.isArray(operadores) || operadores.length === 0) {
        container.append('<p class="text-muted text-center my-3">No hay datos suficientes en el rango seleccionado.</p>');
        return;
    }

    let maxChats = Math.max.apply(null, operadores.map(function (o) {
        return Number(o.chats_atendidos || 0);
    }));
    if (!Number.isFinite(maxChats) || maxChats <= 0) {
        maxChats = 1;
    }

    const colors = ['bg-starfi-primary', 'bg-success', 'bg-starfi-dark', 'bg-info', 'bg-warning'];

    operadores.forEach(function (op, index) {
        const atendidos = Math.max(0, parseInt(op.chats_atendidos, 10) || 0);
        const percent = Math.min(100, Math.max(0, (atendidos / maxChats) * 100)).toFixed(1);
        const colorClass = colors[index % colors.length];
        const safeNombre = escapeHtml(op.nombre_completo || 'Operador');
        const safeChats = escapeHtml(atendidos);

        const html =
            '<div class="mb-3">' +
                '<div class="d-flex justify-content-between mb-1">' +
                    '<span style="font-size: 0.85rem; font-weight: 600;">' + safeNombre + '</span>' +
                    '<span style="font-size: 0.85rem; color: var(--text-muted);">' + safeChats + ' chats</span>' +
                '</div>' +
                '<div class="progress" style="height: 8px;">' +
                    '<div class="progress-bar ' + colorClass + '" role="progressbar" style="width: ' + percent + '%;"></div>' +
                '</div>' +
            '</div>';
        container.append(html);
    });
}
