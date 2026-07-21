// modules/dashboard/funciones_dashboard.js

$(document).ready(function() {
    loadDashboardData();

    $('#btnExport').on('click', function() {
        Swal.fire({ title: 'Exportar a Excel', text: 'Generando archivo de reporte...', icon: 'success', timer: 1500, showConfirmButton: false });
    });

    $('#btnApplyFilters').on('click', function() {
        loadDashboardData();
    });
});

function loadDashboardData() {
    let idSede = $('#filterSede').val() || 'all';
    let fechaDesde = $('#filterFechaDesde').val() || '';
    let fechaHasta = $('#filterFechaHasta').val() || '';

    $.ajax({
        url: 'back_dashboard.php',
        type: 'POST',
        dataType: 'json',
        data: { 
            action: 'load_kpis',
            id_sede: idSede,
            fecha_desde: fechaDesde,
            fecha_hasta: fechaHasta
        },
        success: function(res) {
            if (res.status === 'success') {
                const data = res.data;
                
                // Actualizar KPIs superiores
                $('#kpiTotalChats').text(data.total_chats);
                $('#kpiAvgRes').text(data.avg_res);
                $('#kpiConversion').text(data.conversion_rate);
                $('#kpiCAC').text(data.cac);
                
                // Actualizar Lead Scoring y CSAT
                $('#kpiLeadScore').text(data.lead_score);
                $('#leadStarsContainer').html(generateStarsHTML(data.lead_score));
                
                $('#kpiCsatScore').text(data.csat_score);
                $('#csatStarsContainer').html(generateStarsHTML(data.csat_score));

                // Actualizar Lista de Operadores
                renderOperatorPerformance(data.operadores);
                
                // Actualizar Gráficos
                renderChart(data.chart_data);
                renderMotivosChart(data.motivos_data);
            }
        }
    });
}

function generateStarsHTML(score) {
    let numScore = parseFloat(score);
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

let chatsChartInstance = null;
let motivosChartInstance = null;

function renderMotivosChart(chartData) {
    if (motivosChartInstance) {
        motivosChartInstance.destroy();
    }

    const ctx = document.getElementById('motivosChart').getContext('2d');
    
    if (!chartData || chartData.length === 0) {
        motivosChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: ['Sin datos'], datasets: [{ data: [1], backgroundColor: ['#E2E8F0'] }] }
        });
        return;
    }

    let labels = [];
    let values = [];
    let bgColors = [];

    chartData.forEach(item => {
        let label = item.motivo.replace(/_/g, ' ');
        labels.push(label);
        values.push(item.cantidad);
        
        // Asignar colores según el motivo
        if (item.motivo === 'VENTA_CERRADA') bgColors.push('#10B981');
        else if (item.motivo === 'DUDA_RESUELTA') bgColors.push('#3B82F6');
        else if (item.motivo === 'NO_INTERESADO') bgColors.push('#EF4444');
        else if (item.motivo === 'NO_ESPECIFICADO') bgColors.push('#9CA3AF');
        else bgColors.push('#F59E0B'); // Otro
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
    if (chatsChartInstance) {
        chatsChartInstance.destroy();
    }

    const ctx = document.getElementById('chatsChart').getContext('2d');
    
    if (!chartData || chartData.length === 0) {
        chatsChartInstance = new Chart(ctx, {
            type: 'bar',
            data: { labels: ['Sin datos'], datasets: [{ label: 'Volumen', data: [0] }] }
        });
        return;
    }

    let labels = [];
    let values = [];
    
    chartData.forEach(item => {
        let dateParts = item.fecha.split('-');
        let shortDate = dateParts[2] + '/' + dateParts[1];
        labels.push(shortDate);
        values.push(item.volumen);
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
    container.empty();

    if (operadores.length === 0) {
        container.append('<p class="text-muted text-center mt-4">No hay datos suficientes.</p>');
        return;
    }

    // Calculamos el maximo para los porcentajes de la barra
    let maxChats = Math.max(...operadores.map(o => o.chats_atendidos));
    if(maxChats === 0) maxChats = 1;

    let colors = ['bg-starfi-primary', 'bg-success', 'bg-starfi-dark', 'bg-info', 'bg-warning'];

    operadores.forEach((op, index) => {
        let percent = (op.chats_atendidos / maxChats) * 100;
        let colorClass = colors[index % colors.length];

        let html = `
            <div class="mb-3">
                <div class="d-flex justify-content-between mb-1">
                    <span style="font-size: 0.85rem; font-weight: 600;">${op.nombre_completo}</span>
                    <span style="font-size: 0.85rem; color: var(--text-muted);">${op.chats_atendidos} chats</span>
                </div>
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar ${colorClass}" role="progressbar" style="width: ${percent}%;"></div>
                </div>
            </div>
        `;
        container.append(html);
    });
}
