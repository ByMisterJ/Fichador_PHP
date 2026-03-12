<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir archivos necesarios
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';

// Verificar autenticación
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Verificar que el usuario tenga permisos (solo administradores y supervisores)
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    header('Location: /app/dashboard.php');
    exit;
}

$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

// Preparar datos de usuario para el layout
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función para renderizar el contenido de grupos horarios
function renderInformesContent() {
    ob_start();
    ?>
    <?php 
        Breadcrumb::render([
            ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
            ['label' => 'Informes', 'url' => '/app/informes.php', 'icon' => 'fas fa-chart-bar'],
        ]); 
    ?>
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Informes</h1>
                <p class="text-gray-600 mt-1">Aquí puedes generar informes sobre los fichajes de los empleados.</p>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8 grid grid-cols-1 md:grid-cols-2 gap-4 lg:w-1/2">
        <a href="informe-trabajadores-fechas.php" class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?> w-full text-center">
            <i class="fas fa-file-excel mr-2"></i>
            Trabajadores y fechas
        </a>
        <a href="informes-diferencia-horas.php" class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?> w-full text-center">
            <i class="fas fa-file-excel mr-2"></i>
            Diferencia de horas
        </a>
    </div>

    <?php
    return ob_get_clean();
}

// Renderizar el contenido
$content = renderInformesContent();

// Usar el BaseLayout para renderizar la página completa
BaseLayout::render('Informes', $content, $config_empresa, $user_data);
?> 