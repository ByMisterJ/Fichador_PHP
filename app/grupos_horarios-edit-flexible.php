<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir archivos necesarios
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/GruposHorarios.php';
require_once __DIR__ . '/../shared/validators/GrupoHorarioValidator.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/components/MultiSelect.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';
require_once __DIR__ . '/../shared/forms/GrupoHorarioFlexibleForm.php';

// Verificar que el usuario dispone de una sesión autenticada válida; de lo contrario, redirigir al login.
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Verificar que el rol del usuario autoriza el acceso: solo administradores y supervisores pueden continuar.
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    header('Location: /app/dashboard.php');
    exit;
}

// Recuperar los datos identificativos del usuario autenticado desde la superglobal $_SESSION.
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

// Obtener la configuración de la empresa (colores, logo, nombre de app, etc.) desde la sesión.
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Leer y validar el identificador del grupo horario recibido por GET (parámetro id).
$grupo_id = intval($_GET['id'] ?? 0);
if (!$grupo_id) {
    header('Location: grupos_horarios.php');
    exit;
}

// Inicializar las variables del formulario con valores por defecto antes de procesar la petición.
$errors = [];
$form_data = [];

// Instanciar el modelo GruposHorarios para acceder a los métodos de gestión de grupos horarios.
$gruposHorarios = new GruposHorarios();

// Cargar los datos del grupo horario desde la base de datos para pre-rellenar el formulario de edición.
$form_data = $gruposHorarios->cargarDatosGrupoHorarioFlexible($grupo_id, $empresa_id);
if (!$form_data) {
    header('Location: grupos_horarios.php');
    exit;
}

// Obtener la lista de empleados activos de la empresa para el selector de asignación del grupo.
$empleados = obtenerEmpleadosEmpresa($empresa_id);

// Procesar el envío del formulario (método HTTP POST) validando y persistiendo los datos.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_data = procesarFormularioFlexible($_POST);
    
    // Validar los datos del formulario usando el validador centralizado antes de persistir.
    $errors = GrupoHorarioValidator::validarHorarioFlexible($post_data);
    
    if (empty($errors)) {
        // Persistir los cambios del grupo horario en la base de datos usando el método del modelo.
        $resultado = $gruposHorarios->actualizarGrupoHorarioFlexible($grupo_id, $post_data, $empresa_id);
        
        if ($resultado['success']) {
            header('Location: grupos_horarios.php?success=grupo_actualizado');
            exit;
        } else {
            $errors['general'] = 'Error al actualizar el grupo horario: ' . $resultado['error'];
        }
    } else {
        // Actualizar el array de datos del formulario con los valores enviados en el POST para repopular los campos tras errores de validación.
        $form_data = array_merge($form_data, $post_data);
    }
}

/**
 * Obtener empleados de la empresa
 */
function obtenerEmpleadosEmpresa($empresa_id) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT id, nombre_completo, rol 
            FROM trabajador 
            WHERE empresa_id = ? AND activo = 1 AND sistema = 0 
            ORDER BY nombre_completo ASC
        ");
        $stmt->execute([$empresa_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error al obtener empleados: " . $e->getMessage());
        return [];
    }
}

/**
 * Procesar datos del formulario
 */
function procesarFormularioFlexible($post_data) {
    $data = [
        'nombre' => trim($post_data['nombre'] ?? ''),
        'descripcion' => trim($post_data['descripcion'] ?? ''),
        'periodo' => $post_data['periodo'] ?? 'mensual',
        'empleados' => $post_data['empleados'] ?? [],
        'horas_totales' => trim($post_data['horas_totales'] ?? ''),
        'horas_por_dia_vacacion' => trim($post_data['horas_por_dia_vacacion'] ?? ''),
        'lunes_horas' => trim($post_data['lunes_horas'] ?? ''),
        'martes_horas' => trim($post_data['martes_horas'] ?? ''),
        'miercoles_horas' => trim($post_data['miercoles_horas'] ?? ''),
        'jueves_horas' => trim($post_data['jueves_horas'] ?? ''),
        'viernes_horas' => trim($post_data['viernes_horas'] ?? ''),
        'sabado_horas' => trim($post_data['sabado_horas'] ?? ''),
        'domingo_horas' => trim($post_data['domingo_horas'] ?? '')
    ];
    
    return $data;
}

// Preparar el array de datos del usuario que se pasará al layout base para la cabecera de navegación.
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función encapsuladora que genera el HTML del contenido principal usando output buffering.
function renderEditGrupoHorarioFlexibleContent($form_data, $errors, $empleados, $grupo_id) {
    ob_start();
    ?>
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Grupos Horarios', 'url' => '/app/grupos_horarios.php'],
        ['label' => 'Editar Grupo Horario Flexible']
    ]); 
    ?>

    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Editar Grupo de Horario Flexible</h1>
        <p class="text-gray-600 mt-1">Modifique la configuración del grupo de horarios flexibles</p>
    </div>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('error'); ?>">
            <div class="flex items-start">
                <i class="fas fa-exclamation-triangle text-red-500 mr-3 mt-0.5"></i>
                <div class="flex-1">
                    <h3 class="text-red-800 font-medium mb-2">Se encontraron errores en el formulario:</h3>
                    <ul class="text-red-700 text-sm space-y-1">
                        <?php foreach ($errors as $field => $error): ?>
                            <li>• <?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Form -->
    <?php echo GrupoHorarioFlexibleForm::render($form_data, $errors, $empleados, 'edit', $grupo_id); ?>

    <!-- JavaScript -->
    <?php MultiSelect::renderScript(); ?>
    
    <script>
        // Inicializar la validación del formulario y las funcionalidades dinámicas del lado cliente.
        document.addEventListener('DOMContentLoaded', function() {
            initializeFormValidation();
        });

        function initializeFormValidation() {
            // El componente de formulario gestiona su propia validación en el cliente.
            // Aquí se puede añadir validación adicional específica de esta vista si fuera necesario.
        }
    </script>
    <?php
    return ob_get_clean();
}

// Capturar el HTML generado mediante output buffering e invocarlo con los datos preparados.
$content = renderEditGrupoHorarioFlexibleContent($form_data, $errors, $empleados, $grupo_id);

// Invocar el layout base para construir y enviar la respuesta HTML completa al cliente.
BaseLayout::render('Editar Grupo Horario Flexible', $content, $config_empresa, $user_data);
?> 