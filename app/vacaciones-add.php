<?php
/**
 * Página para agregar nuevas solicitudes de vacaciones
 * Permite a administradores y supervisores crear solicitudes de vacaciones para empleados
 */

// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Verificar autenticación
require_once __DIR__ . '/../shared/models/Trabajador.php';
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Obtener rol del trabajador (permitir empleados, administradores y supervisores)
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';

// Includes requeridos
require_once __DIR__ . '/../shared/models/Vacaciones.php';
require_once __DIR__ . '/../shared/validators/VacacionesValidator.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';
require_once __DIR__ . '/../shared/forms/VacacionForm.php';

// Obtener datos de sesión
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

if (!$empresa_id) {
    header('Location: /app/login.php');
    exit;
}

// Inicializar variables
$errors = [];
$form_data = [];
$vacaciones = new Vacaciones();
$trabajador = new Trabajador();

// Obtener configuración de la empresa usando método existente
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Obtener empleados disponibles usando métodos existentes del modelo
$empleados = [];
if (strtolower($rol_trabajador) === 'administrador') {
    // Administradores pueden ver todos los empleados activos
    $empleados = $trabajador->obtenerTodosTrabajadoresEmpresa($empresa_id);
    // Transformar campo 'centro' a 'centro_nombre' para compatibilidad con el formulario
    $empleados = array_map(function($emp) {
        if (isset($emp['centro']) && !isset($emp['centro_nombre'])) {
            $emp['centro_nombre'] = $emp['centro'];
        }
        return $emp;
    }, $empleados);
} elseif (strtolower($rol_trabajador) === 'supervisor') {
    // Supervisores solo pueden ver empleados de su centro
    $centro_id = $trabajador->obtenerCentroIdTrabajador($trabajador_id);
    if ($centro_id) {
        $empleados = $trabajador->obtenerEmpleadosPorCentro($empresa_id, $centro_id);
    }
}

// Obtener opciones para los select
$motivos_options = $vacaciones->obtenerMotivosDisponibles();
$estados_options = $vacaciones->obtenerEstadosDisponibles();

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data = Vacaciones::procesarFormularioVacacion($_POST, $empresa_id);
    
    // Para empleados, forzar su propio trabajador_id
    if (strtolower($rol_trabajador) === 'empleado') {
        $form_data['trabajador_id'] = $trabajador_id;
    }
    
    // Validar datos y archivos
    $errors = VacacionesValidator::validarVacacionCreacion($form_data, $_FILES);
    
    if (empty($errors)) {
        // Crear la vacación con archivos
        $resultado = $vacaciones->crearVacacion($form_data, $empresa_id, $_FILES);
        
        if ($resultado['success']) {
            header('Location: vacaciones.php?success=created');
            exit;
        } else {
            $errors['general'] = 'Error: ' . $resultado['error'];
        }
    }
}

// Función para renderizar el contenido
function renderVacacionAddContent() {
    global $form_data, $errors, $empleados, $motivos_options, $estados_options, $rol_trabajador, $trabajador_id, $empresa_id;
    
    ob_start();
    ?>
    
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Vacaciones', 'url' => '/app/vacaciones.php'],
        ['label' => 'Nueva Solicitud']
    ]);
    ?>

    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Nueva Solicitud de Vacación</h1>
                <p class="text-gray-600 mt-1">Crear una nueva solicitud de vacación, día libre o ausencia</p>
            </div>
            <a href="vacaciones.php" class="<?php echo CSSComponents::getButtonClasses('outline', 'md'); ?>">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver a Vacaciones
            </a>
        </div>
    </div>

    <!-- Form -->
    <?php 
    echo VacacionForm::render($form_data, $errors, [
        'empleados' => $empleados,
        'motivos' => $motivos_options,
        'estados' => $estados_options,
        'rol_usuario' => $rol_trabajador,
        'trabajador_id' => $trabajador_id,
        'empresa_id' => $empresa_id
    ], 'create');
    ?>

    <!-- JavaScript -->
    <?php echo VacacionForm::renderScript(); ?>
    
    <?php
    return ob_get_clean();
}

// Preparar datos del usuario
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Renderizar página
BaseLayout::render('Nueva Solicitud de Vacación', renderVacacionAddContent(), $config_empresa, $user_data);
?> 