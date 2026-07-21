<?php
// index.php
require_once __DIR__ . '/core/auth.php';
requireAuth();
$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Operador';
$rol_agente = $agente['rol'] ?? 'AGENTE';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Principal | STARFI CRM</title>
    <link rel="icon" href="docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <!-- Starfi CSS Theme -->
    <link href="assets/css/starfi_theme.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-orange: #E85B14;
            --primary-dark: #0F172A;
            --bg-light: #F8FAFC;
            --border-light: #E2E8F0;
        }

        body {
            background-color: var(--bg-light);
            font-family: 'Inter', sans-serif;
            color: #1E293B;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navbar Glassmorphism */
        .navbar-custom {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-light);
            padding: 15px 30px;
        }

        .navbar-brand img {
            height: 40px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .avatar-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-orange), #FF8A4D);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(232, 91, 20, 0.2);
        }

        /* Welcome Banner */
        .welcome-section {
            padding: 50px 0 30px 0;
        }

        .welcome-title {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--primary-dark);
        }

        .welcome-subtitle {
            font-size: 1.1rem;
            color: #64748B;
        }

        /* Grid and Module Cards */
        .module-card {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 20px;
            padding: 30px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
        }

        .module-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-orange), #FF8A4D);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .module-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(15, 23, 42, 0.08);
            border-color: rgba(232, 91, 20, 0.2);
        }

        .module-card:hover::before {
            opacity: 1;
        }

        .icon-container {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 25px;
            transition: all 0.3s;
        }

        /* Module specific colors */
        .icon-bandeja {
            background-color: rgba(232, 91, 20, 0.1);
            color: var(--primary-orange);
        }

        .icon-bots {
            background-color: rgba(13, 148, 136, 0.1);
            color: #0d9488;
        }

        .icon-directorio {
            background-color: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .icon-dashboard {
            background-color: rgba(147, 51, 234, 0.1);
            color: #9333ea;
        }

        .icon-config {
            background-color: rgba(100, 116, 139, 0.1);
            color: #64748b;
        }

        .module-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 12px;
        }

        .module-desc {
            font-size: 0.95rem;
            color: #64748B;
            line-height: 1.5;
            margin-bottom: 25px;
        }

        .action-link {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--primary-orange);
            transition: gap 0.2s;
        }

        .module-card:hover .action-link {
            gap: 12px;
        }

        .footer {
            margin-top: auto;
            border-top: 1px solid var(--border-light);
            background-color: #ffffff;
            padding: 20px 0;
            font-size: 0.9rem;
            color: #64748B;
        }
    </style>
</head>

<body>

    <!-- Cabecera / Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light navbar-custom">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <img src="docs/identidad_visual/logos/logo_starfi.png" alt="STARFI CRM">
            </a>
            <div class="d-flex align-items-center gap-4 ms-auto">
                <div class="user-profile">
                    <div class="avatar-circle">
                        <?= strtoupper(substr($nombre_agente, 0, 1)) ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="fw-bold mb-0 lh-1" style="font-size: 0.95rem;">
                            <?= htmlspecialchars($nombre_agente) ?></div>
                        <span class="badge bg-secondary mt-1"
                            style="font-size: 0.75rem;"><?= htmlspecialchars($rol_agente) ?></span>
                    </div>
                </div>

                <a href="logout.php"
                    class="btn btn-outline-danger btn-sm px-3 py-2 fw-semibold d-flex align-items-center gap-2"
                    style="border-radius: 8px;">
                    <i class="bi bi-box-arrow-right"></i> Salir
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mb-5">
        <div class="welcome-section text-center text-md-start">
            <h1 class="welcome-title">¡Hola, <?= htmlspecialchars(explode(' ', $nombre_agente)[0]) ?>!</h1>
            <p class="welcome-subtitle">Bienvenido a STARFI CRM. Selecciona el módulo al que deseas acceder:</p>
        </div>

        <!-- Módulos Grid -->
        <div class="row g-4 justify-content-center">
            <!-- 1. Centro de Mensajes -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="modules/bandeja/bandeja.php" class="module-card">
                    <div class="module-content">
                        <div class="icon-container icon-bandeja">
                            <i class="fa-solid fa-comments"></i>
                        </div>
                        <h4 class="module-title">Centro de Mensajes</h4>
                        <p class="module-desc">Bandeja de entrada unificada para gestionar y responder a todos los chats
                            de WhatsApp de tus sedes.</p>
                    </div>
                    <div class="module-footer text-primary action-link">
                        Ingresar al Centro <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            </div>

            <!-- 2. Gestor de Bots -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="modules/gestor_bots/gestor_bots.php" class="module-card">
                    <div>
                        <div class="icon-container icon-bots">
                            <i class="bi bi-robot"></i>
                        </div>
                        <h4 class="module-title">Gestor de Bots</h4>
                        <p class="module-desc">Configura flujos de conversación inteligentes, respuestas automáticas y
                            comportamiento general del asistente virtual.</p>
                    </div>
                    <div class="action-link">
                        Configurar Bots <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            </div>

            <!-- 3. Directorio de Clientes -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="modules/directorio/directorio.php" class="module-card">
                    <div>
                        <div class="icon-container icon-directorio">
                            <i class="bi bi-person-lines-fill"></i>
                        </div>
                        <h4 class="module-title">Directorio de Clientes</h4>
                        <p class="module-desc">Visualiza y administra contactos de clientes, asigna etiquetas
                            personalizadas y añade notas de seguimiento.</p>
                    </div>
                    <div class="action-link">
                        Ver Directorio <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            </div>

            <!-- 4. Estadísticas y Reportes -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="modules/dashboard/dashboard.php" class="module-card">
                    <div>
                        <div class="icon-container icon-dashboard">
                            <i class="bi bi-bar-chart-line-fill"></i>
                        </div>
                        <h4 class="module-title">Dashboard y Reportes</h4>
                        <p class="module-desc">Analiza estadísticas de rendimiento de agentes, reportes de volumen de
                            chats y tiempos de respuesta (SLA).</p>
                    </div>
                    <div class="action-link">
                        Ver Reportes <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            </div>

            <!-- 5. Configuración del Sistema -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="modules/configuracion/configuracion.php" class="module-card">
                    <div>
                        <div class="icon-container icon-config">
                            <i class="bi bi-gear-fill"></i>
                        </div>
                        <h4 class="module-title">Configuración del Sistema</h4>
                        <p class="module-desc">Administra sucursales (sedes), asigna líneas y tokens oficiales de
                            WhatsApp y ajusta parámetros del CRM.</p>
                    </div>
                    <div class="action-link">
                        Ir a Ajustes <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            </div>

            <!-- 6. Métricas y Facturación WhatsApp -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="modules/dashboard/whatsapp_analytics.php" class="module-card">
                    <div>
                        <div class="icon-container"
                            style="background-color: rgba(16, 185, 129, 0.1); color: var(--sla-green);">
                            <i class="bi bi-whatsapp"></i>
                        </div>
                        <h4 class="module-title">Facturación WhatsApp</h4>
                        <p class="module-desc">Audita el consumo financiero de la API de Meta, consulta el volumen de
                            mensajes de marketing, utilidad y costos estimados.</p>
                    </div>
                    <div class="action-link" style="color: var(--sla-green);">
                        Ver Métricas <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            </div>

            <!-- 7. Gestión de Usuarios -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="modules/gestion_usuarios/index.php" class="module-card">
                    <div>
                        <div class="icon-container" style="background-color: rgba(79, 70, 229, 0.1); color: #4F46E5;">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <h4 class="module-title">Gestión de Usuarios</h4>
                        <p class="module-desc">Administra cuentas de operadores y supervisores, controla el estado
                            activo/inactivo y habilita permisos de acceso al CRM.</p>
                    </div>
                    <div class="action-link" style="color: #4F46E5;">
                        Gestionar Usuarios <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            </div>

            <!-- 8. Perfil de Empresa -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="modules/perfil_empresa/index.php" class="module-card">
                    <div>
                        <div class="icon-container" style="background-color: rgba(234, 88, 12, 0.1); color: #EA580C;">
                            <i class="bi bi-building"></i>
                        </div>
                        <h4 class="module-title">Perfil de Empresa</h4>
                        <p class="module-desc">Información corporativa, datos fiscales RIF, representantes legales/firmantes, registro mercantil y expedientes digitales.</p>
                    </div>
                    <div class="action-link" style="color: #EA580C;">
                        Ver Perfil Empresa <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            </div>
        </div>
        
        <?php if ($rol_agente === 'MASTER'): ?>
        <div class="row g-4 mt-1">
            <!-- 7. Panel de Órdenes de Cobro (Solo MASTER) -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="modules/dashboard/waba_ordenes.php" class="module-card">
                    <div>
                        <div class="icon-container" style="background-color: rgba(220, 53, 69, 0.1); color: var(--danger-color, #dc3545);">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <h4 class="module-title">Panel de Órdenes WABA</h4>
                        <p class="module-desc">Gestión administrativa de los estados de cuenta, facturas generadas a las sedes y reenvío de notificaciones.</p>
                    </div>
                    <div class="action-link" style="color: var(--danger-color, #dc3545);">
                        Ir al Panel <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            </div>
            
            <!-- 8. Buzón de Salida SMTP (Solo MASTER) -->
            <div class="col-12 col-md-6 col-lg-4">
                <a href="modules/dashboard/buzon_correos.php" class="module-card">
                    <div>
                        <div class="icon-container" style="background-color: rgba(13, 110, 253, 0.1); color: var(--primary-color);">
                            <i class="bi bi-envelope-paper"></i>
                        </div>
                        <h4 class="module-title">Buzón de Salida SMTP</h4>
                        <p class="module-desc">Registro histórico de notificaciones por correo electrónico y estado de entrega (Logs de Envíos).</p>
                    </div>
                    <div class="action-link" style="color: var(--primary-color);">
                        Ver Buzón <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="footer text-center">
        <div class="container">
            <span>© <?= date('Y') ?> STARFI CRM. Todos los derechos reservados.</span>
        </div>
    </footer>

    <!-- Bootstrap Bundle JS -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>

</html>