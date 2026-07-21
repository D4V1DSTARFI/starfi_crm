<?php
/**
 * Módulo Perfil de Empresa - STARFI CRM
 * Vista Principal: Tabla de Empresas Registradas + Ficha de Gestión de 4 Tablas Corporativas:
 * 1. empresa_perfil
 * 2. empresa_firmantes
 * 3. empresa_registro
 * 4. empresa_expediente
 */
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
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
    <!-- CSS Local de Bootstrap -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- Iconos de Bootstrap -->
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Tema Global STARFI & Styles -->
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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

    <!-- Encabezado de la app -->
    <?php renderHeader('Perfil de Empresa'); ?>

    <div class="app-container">
        <!-- Main Content -->
        <main class="main-content w-100">
            <div class="config-container container-fluid">

                <!-- Header de Sección -->
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
                    <div>
                        <h2 class="brand-font mb-1" style="font-weight: 700; color: var(--starfi-dark);">Perfil de Empresa</h2>
                        <p class="text-muted mb-0" style="font-size: 0.95rem;">Administración corporativa: Registro de empresas, firmantes legales, registro mercantil y expediente digital.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2 me-2">
                            <label class="fw-semibold small text-muted text-nowrap mb-0">Sede:</label>
                            <select id="selectSede" class="form-select form-select-sm py-1.5" style="border-radius: 8px; min-width: 160px;" onchange="loadPerfiles()">
                                <option value="0">Cargando...</option>
                            </select>
                        </div>
                        <button class="btn btn-outline-info rounded-circle p-0 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;" onclick="openManualModal()" title="Guía / Manual de Usuario">
                            <i class="fa-solid fa-circle-question fs-5"></i>
                        </button>
                        <button class="btn btn-starfi-primary d-flex align-items-center gap-2" onclick="openPerfilModal()">
                            <i class="fa-solid fa-plus"></i> Registrar Empresa
                        </button>
                    </div>
                </div>

                <!-- VISTA 1: TABLA PRINCIPAL DE EMPRESAS REGISTRADAS -->
                <div id="vistaPrincipalListado">
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

                        <!-- Tabla Principal -->
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

                <!-- VISTA 2: DETALLES Y GESTIÓN DE LAS 4 TABLAS (Oculto por defecto) -->
                <div id="vistaGestionTablas" style="display: none;">

                    <div class="mb-3">
                        <button class="btn btn-outline-secondary btn-sm px-3 fw-semibold rounded-2" onclick="volverAEstaLista()">
                            <i class="fa-solid fa-arrow-left me-1.5"></i> Volver a la Lista de Empresas
                        </button>
                    </div>

                    <!-- Ficha resumen de empresa seleccionada -->
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
                            <div>
                                <button class="btn btn-outline-primary btn-sm px-3 py-2 fw-semibold rounded-2" onclick="editPerfilActual()">
                                    <i class="fa-solid fa-pen me-1"></i> Editar Perfil Principal
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Pestañas de las 4 Tablas Corporativas -->
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

    <!-- MODAL MANUAL / GUÍA -->
    <div class="modal fade" id="modalManual" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-book-open text-info me-2"></i>Guía de Uso: Perfil de Empresa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="rounded-circle bg-light p-2 text-primary fw-bold">1</div>
                        <div>
                            <h6 class="fw-bold mb-1">Registrar Empresa</h6>
                            <p class="text-muted small mb-0">Haz clic en <strong>"Registrar Empresa"</strong> para ingresar el RIF, Razón Social, teléfono y logo corporativo.</p>
                        </div>
                    </div>
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="rounded-circle bg-light p-2 text-primary fw-bold">2</div>
                        <div>
                            <h6 class="fw-bold mb-1">Gestionar las 4 Tablas Legales</h6>
                            <p class="text-muted small mb-0">Haz clic en <strong>"Gestionar Tablas"</strong> sobre la empresa creada para navegar por las pestañas de Datos Generales, Representantes, Registro Mercantil y Expedientes.</p>
                        </div>
                    </div>
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="rounded-circle bg-light p-2 text-primary fw-bold">3</div>
                        <div>
                            <h6 class="fw-bold mb-1">Adjuntar Expedientes Digitales</h6>
                            <p class="text-muted small mb-0">Sube copias en PDF o imagen de RIF, Actas Constitutivas y Solvencias para disponer de descargas rápidas en cualquier momento.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" data-bs-dismiss="modal">Entendido</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 1: PERFIL EMPRESA -->
    <div class="modal fade" id="modalPerfilEmpresa" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="modalPerfilTitle">Registrar Perfil Corporativo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <form id="formPerfilEmpresa" enctype="multipart/form-data">
                        <input type="hidden" id="perfilId" name="id" value="0">
                        <input type="hidden" id="perfilSede" name="id_sede" value="1">
                        
                        <div class="row g-3 mb-3">
                            <div class="col-8">
                                <label class="form-label fw-semibold small">Razón Social</label>
                                <input type="text" class="form-control" name="razon_social" id="inputRazonSocial" placeholder="Ej: Inversiones Starfi C.A." required>
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-semibold small">Letra RIF</label>
                                <select class="form-select" name="letra" id="inputLetra">
                                    <option value="J" selected>J (Jurídico)</option>
                                    <option value="V">V (Venezolano)</option>
                                    <option value="G">G (Gubernamental)</option>
                                    <option value="E">E (Extranjero)</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">RIF (Sin Letra)</label>
                                <input type="text" class="form-control" name="rif" id="inputRif" placeholder="Ej: 12345678-9" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Condición Fiscal</label>
                                <select class="form-select" name="condicion" id="inputCondicion">
                                    <option value="[ORDINARIO]" selected>Ordinario</option>
                                    <option value="[ESPECIAL]">Especial (Contribuyente Especial)</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Correo Electrónico</label>
                                <input type="email" class="form-control" name="email" id="inputEmail" placeholder="contacto@empresa.com">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Teléfono</label>
                                <input type="text" class="form-control" name="telefono" id="inputTelefonoPerfil" placeholder="+58 412 0000000">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Sitio Web</label>
                                <input type="text" class="form-control" name="web" id="inputWeb" placeholder="https://miempresa.com">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Instagram</label>
                                <input type="text" class="form-control" name="instagram" id="inputInstagram" placeholder="@miempresa">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Dirección Fiscal</label>
                            <textarea class="form-control" name="direccion" id="inputDireccionPerfil" rows="2" placeholder="Dirección física completa..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Logo de la Empresa</label>
                            <input type="file" class="form-control" name="logo" accept="image/*">
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="savePerfilEmpresa()">Guardar Perfil</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 2: FIRMANTE -->
    <div class="modal fade" id="modalFirmante" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="modalFirmanteTitle">Agregar Firmante / Representante Legal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <form id="formFirmante">
                        <input type="hidden" id="firmanteId" name="id" value="0">
                        <input type="hidden" id="firmanteEmpresaId" name="id_empresa" value="0">
                        <input type="hidden" id="firmanteSedeId" name="id_sede" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Nombre Completo</label>
                            <input type="text" class="form-control" name="nombre" id="inputFirmanteNombre" required placeholder="Ej: Carlos Mendoza">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Cédula</label>
                                <input type="text" class="form-control" name="cedula" id="inputFirmanteCedula" required placeholder="Ej: V-15678901">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Cargo</label>
                                <input type="text" class="form-control" name="cargo" id="inputFirmanteCargo" placeholder="Ej: Director General">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Teléfono</label>
                                <input type="text" class="form-control" name="telefono" id="inputFirmanteTelefono" placeholder="+58 414 1112233">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Correo Electrónico</label>
                                <input type="email" class="form-control" name="email" id="inputFirmanteEmail" placeholder="firmante@correo.com">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Dirección Habitación / Trabajo</label>
                            <textarea class="form-control" name="direccion" id="inputFirmanteDireccion" rows="2" placeholder="Dirección..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="saveFirmante()">Guardar Firmante</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 3: REGISTRO MERCANTIL -->
    <div class="modal fade" id="modalRegistro" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="modalRegistroTitle">Agregar Inscripción Mercantil</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <form id="formRegistro">
                        <input type="hidden" id="registroId" name="id" value="0">
                        <input type="hidden" id="registroEmpresaId" name="id_empresa" value="0">
                        <input type="hidden" id="registroSedeId" name="id_sede" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Fecha de Inscripción</label>
                            <input type="date" class="form-control" name="fecha" id="inputRegistroFecha" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">N° de Registro</label>
                                <input type="text" class="form-control" name="n_registro" id="inputRegistroNum" required placeholder="Ej: 1452">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">N° de Tomo</label>
                                <input type="text" class="form-control" name="n_tomo" id="inputRegistroTomo" required placeholder="Ej: 42-A">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Notaría / Registro Mercantil / Descripción</label>
                            <textarea class="form-control" name="descripcion" id="inputRegistroDesc" rows="2" placeholder="Ej: Registro Mercantil Primero del Distrito Capital..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="saveRegistro()">Guardar Registro</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 4: EXPEDIENTE DIGITAL -->
    <div class="modal fade" id="modalExpediente" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Adjuntar Documento al Expediente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <form id="formExpediente" enctype="multipart/form-data">
                        <input type="hidden" id="expedienteEmpresaId" name="id_empresa" value="0">
                        <input type="hidden" id="expedienteSedeId" name="id_sede" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Fecha del Documento</label>
                            <input type="date" class="form-control" name="fecha" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Nombre / Descripción del Documento</label>
                            <input type="text" class="form-control" name="descripcion" required placeholder="Ej: Acta Constitutiva, RIF Actualizado, Solvencia...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Archivo (PDF / Imagen)</label>
                            <input type="file" class="form-control" name="archivo" required accept=".pdf,image/*">
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="saveExpediente()">Adjuntar Archivo</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle JS -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>

    <script>
        let currentSede = 1;
        let currentPerfiles = [];
        let selectedEmpresaData = null;

        document.addEventListener('DOMContentLoaded', () => {
            loadSedes();
        });

        function loadSedes() {
            fetch('back_empresa.php?action=get_sedes')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        const sel = document.getElementById('selectSede');
                        sel.innerHTML = '';
                        data.data.forEach(s => {
                            sel.innerHTML += `<option value="${s.id}">${escapeHtml(s.razon_social)}</option>`;
                        });
                        currentSede = data.data[0].id;
                    }
                    loadPerfiles();
                });
        }

        function loadPerfiles() {
            const sedeId = document.getElementById('selectSede').value || 1;
            currentSede = sedeId;

            fetch(`back_empresa.php?action=get_perfiles&id_sede=${sedeId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        currentPerfiles = data.data;
                        renderTablaPrincipal();
                    } else {
                        currentPerfiles = [];
                        renderTablaPrincipal();
                    }
                });
        }

        function renderTablaPrincipal() {
            const tbody = document.getElementById('tablaPerfilesBody');
            const search = document.getElementById('searchEmpresa').value.toLowerCase().trim();

            let filtered = currentPerfiles.filter(p => {
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
                const rifCompleto = `${p.letra}-${p.rif}`;
                const condicionText = (p.condicion === '[ESPECIAL]') ? 'ESPECIAL' : 'ORDINARIO';

                html += `
                    <tr>
                        <td class="fw-bold text-muted">${idx + 1}</td>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                ${p.logo_empresa ? `<img src="../../uploads/empresa/${p.logo_empresa}" class="rounded border" style="width:40px; height:40px; object-fit:contain;">` : `<div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-primary" style="width:40px; height:40px;"><i class="fa-solid fa-building fs-5"></i></div>`}
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
                                <button class="btn btn-starfi-primary btn-sm px-3 py-1.5 fw-semibold d-flex align-items-center gap-1.5" onclick="verGestionTablas(${p.id})">
                                    <i class="fa-solid fa-layer-group"></i> Gestionar Tablas
                                </button>
                                <button class="btn btn-light btn-sm text-secondary border px-2.5 py-1.5 rounded-2" onclick="editPerfilEmpresaDirecto(${p.id})" title="Editar Datos">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm px-2.5 py-1.5 rounded-2" onclick="deletePerfilEmpresa(${p.id})" title="Eliminar Empresa">
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

        function verGestionTablas(idEmpresa) {
            fetch(`back_empresa.php?action=get_detalle_empresa&id_empresa=${idEmpresa}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        selectedEmpresaData = data;
                        const p = data.perfil;

                        // Rellenar cabecera corporativa
                        document.getElementById('headerRazonSocial').innerText = p.razon_social;
                        document.getElementById('headerRif').innerText = `RIF: ${p.letra}-${p.rif}`;
                        document.getElementById('headerCondicion').innerText = (p.condicion === '[ESPECIAL]') ? 'CONTRIBUYENTE ESPECIAL' : 'ORDINARIO';
                        document.getElementById('headerCorreo').innerText = p.email || 'Sin Correo';
                        document.getElementById('headerTelefono').innerText = p.telefono || 'Sin Teléfono';

                        const logoBox = document.getElementById('headerLogoBox');
                        if (p.logo_empresa) {
                            logoBox.innerHTML = `<img src="../../uploads/empresa/${p.logo_empresa}" alt="Logo">`;
                        } else {
                            logoBox.innerHTML = `<i class="fa-solid fa-building fs-2 text-secondary"></i>`;
                        }

                        // Tab 1: Datos Generales
                        document.getElementById('tdRazonSocial').innerText = p.razon_social;
                        document.getElementById('tdRif').innerText = `${p.letra}-${p.rif}`;
                        document.getElementById('tdCondicion').innerText = (p.condicion === '[ESPECIAL]') ? 'CONTRIBUYENTE ESPECIAL' : 'ORDINARIO';
                        document.getElementById('tdCorreo').innerText = p.email || '-';
                        document.getElementById('tdTelefono').innerText = p.telefono || '-';
                        document.getElementById('tdWeb').innerText = p.web || '-';
                        document.getElementById('tdInstagram').innerText = p.instagram || '-';
                        document.getElementById('tdDireccion').innerText = p.direccion || '-';
                        document.getElementById('tdLogoContainer').innerHTML = p.logo_empresa ? `<img src="../../uploads/empresa/${p.logo_empresa}" style="max-height: 50px;" class="rounded border p-1">` : 'Sin Logo';

                        // Tab 2: Tabla Firmantes (empresa_firmantes)
                        renderTablaFirmantes(data.firmantes);

                        // Tab 3: Tabla Registro Mercantil (empresa_registro)
                        renderTablaRegistro(data.registros);

                        // Tab 4: Tabla Expediente (empresa_expediente)
                        renderTablaExpediente(data.expedientes);

                        // Cambiar vista
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
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteFirmante(${f.id})" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
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
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteRegistro(${r.id})" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
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
                            <a href="../../${e.ruta}" target="_blank" class="btn btn-sm btn-outline-primary px-3 rounded-2 fw-semibold">
                                <i class="fa-solid fa-download me-1"></i> Ver / Descargar
                            </a>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteExpediente(${e.id})" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>`;
            });
            tbody.innerHTML = html;
        }

        // --- ACCIONES MODALES Y BOTONES ---

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
                        bootstrap.Modal.getInstance(document.getElementById('modalPerfilEmpresa')).hide();
                        loadPerfiles();
                    } else {
                        alert(data.message || 'Error al guardar perfil');
                    }
                });
        }

        function deletePerfilEmpresa(id) {
            if (!confirm('¿Desea eliminar permanentemente esta empresa y todas sus tablas asociadas?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_perfil');
            formData.append('id', id);
            fetch('back_empresa.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        volverAEstaLista();
                    }
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
                        bootstrap.Modal.getInstance(document.getElementById('modalFirmante')).hide();
                        loadPerfilDetalle(selectedEmpresaData.perfil.id);
                    } else { alert(data.message); }
                });
        }

        function deleteFirmante(id) {
            if (!confirm('¿Desea eliminar este firmante?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_firmante');
            formData.append('id', id);
            fetch('back_empresa.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => { if (data.success) loadPerfilDetalle(selectedEmpresaData.perfil.id); });
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
                        bootstrap.Modal.getInstance(document.getElementById('modalRegistro')).hide();
                        loadPerfilDetalle(selectedEmpresaData.perfil.id);
                    } else { alert(data.message); }
                });
        }

        function deleteRegistro(id) {
            if (!confirm('¿Desea eliminar esta inscripción de registro mercantil?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_registro');
            formData.append('id', id);
            fetch('back_empresa.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => { if (data.success) loadPerfilDetalle(selectedEmpresaData.perfil.id); });
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
                        bootstrap.Modal.getInstance(document.getElementById('modalExpediente')).hide();
                        loadPerfilDetalle(selectedEmpresaData.perfil.id);
                    } else { alert(data.message); }
                });
        }

        function deleteExpediente(id) {
            if (!confirm('¿Desea eliminar este documento del expediente?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_expediente');
            formData.append('id', id);
            fetch('back_empresa.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => { if (data.success) loadPerfilDetalle(selectedEmpresaData.perfil.id); });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }
    </script>
</body>
</html>
