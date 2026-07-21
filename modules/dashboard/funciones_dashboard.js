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
                $('#kpiAvgFrt').text(data.avg_frt);
                $('#kpiAvgRes').text(data.avg_res);

                // Actualizar Lista de Operadores
                renderOperatorPerformance(data.operadores);
                
                // Actualizar Gráfico
                renderChart(data.chart_data);
            }
        }
    });
}

let chatsChartInstance = null;

function renderChart(chartData) {
    if (chatsChartInstance) {
        chatsChartInstance.destroy();
    }

    const ctx = document.getElementById('chatsChart').getContext('2d');
    
    if (!chartData || chartData.length === 0) {
        // Grafico vacio
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
