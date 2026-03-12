<?php
/**
 * Página para editar solicitudes de vacaciones
 * Permite a administradores y supervisores editar solicitudes de vacaciones
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

// Obtener ID de la vacación
$vacacion_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$vacacion_id) {
    header('Location: vacaciones.php');
    exit;
}

// Inicializar variables
$errors = [];
$form_data = [];
$vacaciones = new Vacaciones();
$trabajador = new Trabajador();

// Obtener configuración de la empresa usando método existente
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Cargar datos de la vacación
$resultado_carga = $vacaciones->cargarDatosVacacion($vacacion_id, $empresa_id);
if (!$resultado_carga['success']) {
    header('Location: vacaciones.php?error=not_found');
    exit;
}

$form_data = $resultado_carga['data'];

// Verificar permisos específicos para supervisores y empleados
if (strtolower($rol_trabajador) === 'empleado') {
    // Empleados solo pueden editar sus propias vacaciones
    if ($form_data['trabajador_id'] != $trabajador_id) {
        header('Location: vacaciones.php?error=permission_denied');
        exit;
    }
} elseif (strtolower($rol_trabajador) === 'supervisor') {
    // Verificar que la vacación pertenece a un empleado del centro del supervisor
    $centro_id_supervisor = $trabajador->obtenerCentroIdTrabajador($trabajador_id);
    
    // Obtener centro del empleado de la vacación usando método existente
    $vacacion_con_centro = $vacaciones->obtenerVacacionConCentro($vacacion_id, $empresa_id);
    
    if (!$centro_id_supervisor || !$vacacion_con_centro || 
        $centro_id_supervisor != $vacacion_con_centro['centro_id']) {
        header('Location: vacaciones.php?error=permission_denied');
        exit;
    }
}

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
// Para empleados: no necesitamos cargar datos, solo usamos el ID de la sesión
// El campo empleado estará oculto y el ID se fuerza desde la sesión

// Obtener opciones para los select
$motivos_options = $vacaciones->obtenerMotivosDisponibles();
$estados_options = $vacaciones->obtenerEstadosDisponibles();

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_data = Vacaciones::procesarFormularioVacacion($_POST, $empresa_id);
    
    // Para empleados, forzar su propio trabajador_id y lógica de estado
    if (strtolower($rol_trabajador) === 'empleado') {
        $post_data['trabajador_id'] = $trabajador_id;
        
        // Si el empleado modifica una vacación aprobada o rechazada, cambiar a pendiente
        if (in_array($form_data['estado'], ['aprobada', 'rechazada'])) {
            $post_data['estado'] = 'pendiente';
        }
    }
    
    // Validar datos y archivos
    $errors = VacacionesValidator::validarVacacionEdicion($post_data, $vacacion_id, $_FILES);
    
    if (empty($errors)) {
        // Actualizar la vacación con archivos
        $resultado = $vacaciones->actualizarVacacion($vacacion_id, $post_data, $empresa_id, $_FILES);
        
        if ($resultado['success']) {
            header('Location: vacaciones.php?success=updated');
            exit;
        } else {
            $errors['general'] = 'Error: ' . $resultado['error'];
        }
    } else {
        // Mantener los datos enviados para mostrar en el formulario
        $form_data = array_merge($form_data, $post_data);
    }
}

// Función para renderizar el contenido
function renderVacacionEditContent() {
    global $form_data, $errors, $empleados, $motivos_options, $estados_options, $vacacion_id, $rol_trabajador, $trabajador_id, $empresa_id;
    
    ob_start();
    ?>
    
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Vacaciones', 'url' => '/app/vacaciones.php'],
        ['label' => 'Editar Solicitud']
    ]);
    ?>

    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Editar Solicitud de Vacación</h1>
                <p class="text-gray-600 mt-1">
                    Modificar solicitud de <?php echo htmlspecialchars($form_data['nombre_completo'] ?? 'empleado'); ?>
                    <?php if (!empty($form_data['centro_nombre'])): ?>
                        - <?php echo htmlspecialchars($form_data['centro_nombre']); ?>
                    <?php endif; ?>
                </p>
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
    ], 'edit', $vacacion_id);
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
BaseLayout::render('Editar Solicitud de Vacación', renderVacacionEditContent(), $config_empresa, $user_data);
?> 