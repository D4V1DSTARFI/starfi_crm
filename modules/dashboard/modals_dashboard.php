<?php if (!defined('STARFI_MODALS_ALLOWED')) { http_response_code(403); exit('403 Forbidden'); } ?>
<!-- Modal Resumen Ejecutivo y Opciones de Exportación Excel | STARFI CRM -->
<div class="modal fade" id="modalDetalleExportDashboard" tabindex="-1" aria-labelledby="modalDetalleExportDashboardLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form id="formExportDashboard" autocomplete="off">
                <div class="modal-header bg-starfi-dark text-white">
                    <h5 class="modal-title fw-bold" id="modalDetalleExportDashboardLabel">
                        <i class="fa-solid fa-file-excel text-success me-2"></i>Reporte Ejecutivo de Métricas y KPIs (.xlsx)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border d-flex justify-content-between align-items-center py-2 mb-3">
                        <div>
                            <small class="text-muted d-block">Alcance del Reporte:</small>
                            <strong id="modalExportSedeLabel">Todas las sedes</strong>
                        </div>
                        <div class="text-end">
                            <small class="text-muted d-block">Periodo Activo:</small>
                            <strong class="text-starfi-primary" id="modalExportRangoLabel">-</strong>
                        </div>
                    </div>

                    <h6 class="fw-bold text-starfi-dark mb-2">Vista Previa Consolidada</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Indicador Clave (KPI)</th>
                                    <th class="text-end">Valor Consolidado</th>
                                </tr>
                            </thead>
                            <tbody id="modalExportResumenBody">
                                <tr>
                                    <td colspan="2" class="text-center text-muted py-3">Sin datos cargados aún.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h6 class="fw-bold text-starfi-dark mb-2">Hojas Incluidas en el Libro Excel</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="form-check p-2 border rounded bg-light">
                                <input class="form-check-input ms-1 me-2" type="checkbox" id="chkSheetResumen" checked disabled>
                                <label class="form-check-label fw-semibold small" for="chkSheetResumen">
                                    1. Resumen_KPIs (Obligatoria)
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check p-2 border rounded">
                                <input class="form-check-input ms-1 me-2" type="checkbox" id="chkSheetAgentes" checked>
                                <label class="form-check-label fw-semibold small" for="chkSheetAgentes">
                                    2. Rendimiento_Agentes
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check p-2 border rounded">
                                <input class="form-check-input ms-1 me-2" type="checkbox" id="chkSheetVolumen" checked>
                                <label class="form-check-label fw-semibold small" for="chkSheetVolumen">
                                    3. Volumen_Diario
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check p-2 border rounded">
                                <input class="form-check-input ms-1 me-2" type="checkbox" id="chkSheetMotivos" checked>
                                <label class="form-check-label fw-semibold small" for="chkSheetMotivos">
                                    4. Motivos_Cierre
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" id="btnConfirmModalExport" class="btn btn-starfi-primary fw-semibold">
                        <i class="fa-solid fa-download me-1"></i> Generar y Descargar Excel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
