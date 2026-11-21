<?php
/**
 * Módulo Perfil de Empresa - STARFI CRM
 * Vista Principal MVC desacoplada en cápsula de 4 archivos.
 */
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('perfil_empresa');
$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil de Empresa | STARFI CRM</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/vendor/fontawesome/css/all.min.css">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/styles.css">

    <style>
        .config-container {
            flex: 1;
            padding: 30px;
            background-color: var(--bg-main);
            overflow-y: auto;
            min-height: calc(100vh - 60px);
        }
        .empresa-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            padding: 24px;
            margin-bottom: 24px;
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
        .nav-tabs .nav-link {
            border: none;
            color: #64748B;
            font-weight: 600;
            padding: 12px 20px;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }
        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: transparent;
        }
        .table > :not(caption) > * > * {
            padding: 1rem 0.85rem;
            vertical-align: middle;
        }
        .logo-preview-box {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            border: 2px dashed #E2E8F0;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #F8FAFC;
            overflow: hidden;
        }
        .logo-preview-box img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .badge-condicion {
            background-color: rgba(79, 70, 229, 0.1);
            color: #4F46E5;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
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

    <?php renderHeader('Perfil de Empresa'); ?>

    <div class="app-container" id="empresaAppRoot" data-agente="<?= htmlspecialchars($nombre_agente, ENT_QUOTES, 'UTF-8') ?>">
        <main class="main-content w-100">
            <div class="config-container container-fluid">

                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
                    <div>
                        <h2 class="brand-font mb-1" style="font-weight: 700; color: var(--starfi-dark);">Perfil de Empresa</h2>
                        <p class="text-muted mb-0" style="font-size: 0.95rem;">Administración corporativa: Registro de empresas, firmantes legales, registro mercantil y expediente digital.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-outline-info rounded-circle p-0 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;" onclick="openManualModal()" title="Guía / Manual de Usuario">
                            <i class="fa-solid fa-circle-question fs-5"></i>
                        </button>
                    </div>
                </div>

                <!-- VISTA 1: TABLA PRINCIPAL DE EMPRESAS REGISTRADAS -->
                <div id="vistaPrincipalListado" style="display: none;">
                    <div class="empresa-card">
                        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
                            <div>
                                <h5 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-building text-primary me-2"></i>Empresas Registradas</h5>
                                <span class="text-muted small">Selecciona una empresa para gestionar sus 4 tablas legales y expediente digital.</span>
                            </div>
                            <div class="search-bar-modern">
                                <i class="fa-solid fa-search text-muted"></i>
                                <input type="text" id="searchEmpresa" placeholder="Buscar empresa o RIF..." onkeyup="filterTablaPrincipal()">
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle table-hover mb-0">
                                <thead class="table-light">
                                    <tr style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748B;">
                                        <th>#</th>
                                        <th>Razón Social / Empresa</th>
                                        <th>RIF</th>
                                        <th>Condición Fiscal</th>
                                        <th>Firmante Principal</th>
                                        <th class="text-end">Acciones / Gestión</th>
                                    </tr>
                                </thead>
                                <tbody id="tablaPerfilesBody">
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                            Cargando empresas registradas...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- VISTA 2: DETALLES Y GESTIÓN DE LAS 4 TABLAS -->
                <div id="vistaGestionTablas" style="display: block;">

                    <div class="empresa-card mb-4" id="cardEmpresaHeader">
                        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="logo-preview-box" id="headerLogoBox">
                                    <i class="fa-solid fa-building fs-2 text-secondary"></i>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <h3 class="fw-bold mb-0 text-dark" id="headerRazonSocial">Razón Social</h3>
                                        <span class="badge-condicion" id="headerCondicion">ORDINARIO</span>
                                    </div>
                                    <div class="text-muted small">
                                        <span class="fw-bold text-dark" id="headerRif">RIF: J-00000000-0</span> |
                                        <i class="fa-solid fa-envelope ms-2 me-1"></i><span id="headerCorreo">correo@empresa.com</span> |
                                        <i class="fa-solid fa-phone ms-2 me-1"></i><span id="headerTelefono">+58 000 0000000</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="empresa-card p-0">
                        <ul class="nav nav-tabs px-3 pt-2 border-bottom" id="empresaTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tab-datos-btn" data-bs-toggle="tab" data-bs-target="#tab-datos" type="button" role="tab">
                                    <i class="fa-solid fa-circle-info me-1.5"></i> 1. Datos Generales
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-firmantes-btn" data-bs-toggle="tab" data-bs-target="#tab-firmantes" type="button" role="tab">
                                    <i class="fa-solid fa-file-signature me-1.5"></i> 2. Representantes / Firmantes
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-registro-btn" data-bs-toggle="tab" data-bs-target="#tab-registro" type="button" role="tab">
                                    <i class="fa-solid fa-book-bookmark me-1.5"></i> 3. Registro Mercantil
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-expediente-btn" data-bs-toggle="tab" data-bs-target="#tab-expediente" type="button" role="tab">
                                    <i class="fa-solid fa-folder-open me-1.5"></i> 4. Expediente Digital
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content p-4">

                            <!-- TAB 1: DATOS GENERALES (empresa_perfil) -->
                            <div class="tab-pane fade show active" id="tab-datos" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-circle-info text-primary me-2"></i>Ficha de Datos Corporativos</h6>
                                    <button class="btn btn-starfi-primary btn-sm px-3 fw-semibold" onclick="editPerfilActual()">
                                        <i class="fa-solid fa-pen-to-square me-1"></i> Editar Datos
                                    </button>
                                </div>
                                <div class="row g-4">
                                    <div class="col-12 col-md-6">
                                        <table class="table table-bordered align-middle">
                                            <tbody>
                                                <tr>
                                                    <th class="bg-light w-35 text-muted small">Razón Social</th>
                                                    <td class="fw-bold text-dark" id="tdRazonSocial">-</td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light text-muted small">RIF / Documento</th>
                                                    <td class="fw-semibold text-primary" id="tdRif">-</td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light text-muted small">Condición Fiscal</th>
                                                    <td><span class="badge-condicion" id="tdCondicion">ORDINARIO</span></td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light text-muted small">Correo Electrónico</th>
                                                    <td id="tdCorreo">-</td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light text-muted small">Teléfono de Contacto</th>
                                                    <td id="tdTelefono">-</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <table class="table table-bordered align-middle">
                                            <tbody>
                                                <tr>
                                                    <th class="bg-light w-35 text-muted small">Sitio Web</th>
                                                    <td id="tdWeb">-</td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light text-muted small">Instagram</th>
                                                    <td id="tdInstagram">-</td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light text-muted small">Dirección Fiscal</th>
                                                    <td id="tdDireccion">-</td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light text-muted small">Logo de Empresa</th>
                                                    <td>
                                                        <div id="tdLogoContainer">Sin Logo</div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 2: FIRMANTES Y REPRESENTANTES (empresa_firmantes) -->
                            <div class="tab-pane fade" id="tab-firmantes" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-users-gear text-primary me-2"></i>Tabla de Representantes Legales / Firmantes</h6>
                                    <button class="btn btn-primary btn-sm px-3 fw-semibold" style="border-radius: 8px; background-color: var(--primary); border-color: var(--primary);" onclick="openFirmanteModal()">
                                        <i class="fa-solid fa-plus me-1"></i> Agregar Firmante
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr class="small text-uppercase text-muted">
                                                <th>Nombre Completo</th>
                                                <th>Cédula</th>
                                                <th>Cargo</th>
                                                <th>Contacto</th>
                                                <th>Dirección</th>
                                                <th class="text-end">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tablaFirmantesBody">
                                            <tr><td colspan="6" class="text-center py-4 text-muted">Cargando firmantes...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- TAB 3: REGISTRO MERCANTIL (empresa_registro) -->
                            <div class="tab-pane fade" id="tab-registro" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-scale-balanced text-primary me-2"></i>Tabla de Inscripciones de Registro Mercantil</h6>
                                    <button class="btn btn-primary btn-sm px-3 fw-semibold" style="border-radius: 8px; background-color: var(--primary); border-color: var(--primary);" onclick="openRegistroModal()">
                                        <i class="fa-solid fa-plus me-1"></i> Agregar Registro
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr class="small text-uppercase text-muted">
                                                <th>Fecha Inscripción</th>
                                                <th>N° Registro</th>
                                                <th>N° Tomo</th>
                                                <th>Notaría / Descripción</th>
                                                <th class="text-end">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tablaRegistroBody">
                                            <tr><td colspan="5" class="text-center py-4 text-muted">Cargando registros...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- TAB 4: EXPEDIENTE DIGITAL (empresa_expediente) -->
                            <div class="tab-pane fade" id="tab-expediente" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-folder-closed text-primary me-2"></i>Tabla de Expediente y Documentación Adjunta</h6>
                                    <button class="btn btn-primary btn-sm px-3 fw-semibold" style="border-radius: 8px; background-color: var(--primary); border-color: var(--primary);" onclick="openExpedienteModal()">
                                        <i class="fa-solid fa-file-upload me-1"></i> Adjuntar Documento
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr class="small text-uppercase text-muted">
                                                <th>Fecha Documento</th>
                                                <th>Descripción / Documento</th>
                                                <th>Archivo</th>
                                                <th class="text-end">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tablaExpedienteBody">
                                            <tr><td colspan="4" class="text-center py-4 text-muted">Cargando expedientes...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>

            </div>
        </main>
    </div>

    <?php require __DIR__ . '/modals_perfil_empresa.php'; ?>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="funciones_perfil_empresa.js"></script>
</body>
</html>
