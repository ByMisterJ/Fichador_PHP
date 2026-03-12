<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir modelos, validadores, componentes de formulario y utilidades necesarias para este controlador.
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/GruposHorarios.php';
require_once __DIR__ . '/../shared/validators/EmpleadoValidator.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/forms/EmpleadoForm.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';
require_once __DIR__ . '/../config/database.php';

// Verificar que el usuario dispone de una sesión autenticada activa; de lo contrario, redirigir al login.
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Verificar que el rol del usuario autoriza la acción: solo administradores y supervisores pueden crear empleados.
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    header('Location: /app/dashboard.php');
    exit;
}

// Instanciar los modelos necesarios para la gestión de empleados y grupos horarios.
$trabajador = new Trabajador();
$gruposHorarios = new GruposHorarios();

// Obtener el identificador de empresa y la configuración de la empresa desde la sesión.
$empresa_id = $_SESSION['empresa_id'];
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

$errores = [];
$datos = [];

// Gestionar la generación de PIN único vía petición AJAX (GET con action=generar_pin).
if (isset($_GET['action']) && $_GET['action'] === 'generar_pin') {
    header('Content-Type: application/json');
    $pin_generado = $trabajador->generarPinUnico($empresa_id);
    echo json_encode(['pin' => $pin_generado]);
    exit;
}

// Procesar el envío del formulario de alta de empleado (método HTTP POST).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Normalizar y estructurar los datos del formulario usando el método centralizado del modelo.
    $datos = Trabajador::procesarFormularioEmpleado($_POST, $empresa_id);
    
    // Validar los datos con el validador centralizado antes de persistir en la base de datos.
    $errores = EmpleadoValidator::validarEmpleadoCreacion($datos, $trabajador, $empresa_id);
    
    if (empty($errores)) {
        // Insertar el nuevo trabajador en la base de datos mediante el método del modelo.
        $resultado = $trabajador->crearTrabajador($datos);
        
        if ($resultado) {
            header('Location: /app/empleados.php?success=empleado_creado');
            exit;
        } else {
            $errores['general'] = 'Error al crear el empleado. Inténtalo de nuevo.';
        }
    }
}

// Preparar el array de opciones para los selects del formulario (grupos horarios, centros, rol).
$opciones = [
    'grupos_horario' => $gruposHorarios->obtenerGruposHorarioParaSelect($empresa_id),
    'centros' => $trabajador->obtenerCentrosEmpresa($empresa_id),
    'rol_trabajador' => $rol_trabajador
];

// Recuperar los datos del usuario autenticado desde la superglobal $_SESSION.
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';

// Preparar el array de datos del usuario que se pasará al layout base para la cabecera.
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función encapsuladora que genera el HTML del contenido principal usando output buffering.
function renderContent($datos, $errores, $opciones) {
    ob_start();
    ?>
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Empleados', 'url' => '/app/empleados.php'],
        ['label' => 'Añadir nuevo empleado']
    ]); 
    ?>

    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">Añadir nuevo empleado</h1>
        <p class="mt-2 text-gray-600">Complete todos los campos requeridos para registrar un nuevo empleado en el sistema.</p>
    </div>

    <!-- Form Card -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?>">
        <?php EmpleadoForm::render($datos, $errores, $opciones, 'create'); ?>
    </div>

    <?php EmpleadoForm::renderScript('create'); ?>
    <?php
    return ob_get_clean();
}

// Capturar el HTML generado por la función renderContent mediante output buffering.
$content = renderContent($datos, $errores, $opciones);

// Invocar el layout base para construir y enviar la respuesta HTML completa al cliente.
BaseLayout::render('Añadir nuevo empleado', $content, $config_empresa, $user_data);
?> 