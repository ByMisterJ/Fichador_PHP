<?php
// Initialize app (session, subdomain routing, etc.)
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
require_once __DIR__ . '/../shared/forms/GrupoHorarioRotativoForm.php';

// Verificar autenticación
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}



// Obtener datos del trabajador de la sesión
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

// Obtener configuración de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Inicializar variables
$errors = [];
$form_data = [
    'nombre' => '',
    'descripcion' => '',
    'fecha_inicio_ciclo' => '',
    'empleados' => [],
    'patterns' => [getDefaultPattern()]
];

// Inicializar clase GruposHorarios
$gruposHorarios = new GruposHorarios();

// Obtener empleados de la empresa
$empleados = obtenerEmpleadosEmpresa($empresa_id);

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data = procesarFormularioRotativo($_POST);
    
    // Validar datos
    $errors = GrupoHorarioValidator::validarHorarioRotativo($form_data);
    
    if (empty($errors)) {
        // Crear grupo horario usando la clase centralizada
        $resultado = $gruposHorarios->crearGrupoHorarioRotativo($form_data, $empresa_id);
        
        if ($resultado['success']) {
            header('Location: grupos_horarios.php?success=grupo_creado');
            exit;
        } else {
            $errors['general'] = 'Error al crear el grupo horario: ' . $resultado['error'];
        }
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
function procesarFormularioRotativo($post_data) {
    $data = [
        'nombre' => trim($post_data['nombre'] ?? ''),
        'descripcion' => trim($post_data['descripcion'] ?? ''),
        'fecha_inicio_ciclo' => $post_data['fecha_inicio_ciclo'] ?? '',
        'empleados' => $post_data['empleados'] ?? [],
        'patterns' => []
    ];
    
    // Procesar patrones
    if (isset($post_data['patterns']) && is_array($post_data['patterns'])) {
        foreach ($post_data['patterns'] as $pattern) {
            $data['patterns'][] = [
                'descripcion' => trim($pattern['descripcion'] ?? ''),
                'dias_trabajo' => intval($pattern['dias_trabajo'] ?? 0),
                'dias_descanso' => intval($pattern['dias_descanso'] ?? 0),
                'hora_inicio' => $pattern['hora_inicio'] ?? '',
                'hora_fin' => $pattern['hora_fin'] ?? ''
            ];
        }
    }
    
    // Si no hay patrones, agregar uno por defecto
    if (empty($data['patterns'])) {
        $data['patterns'] = [getDefaultPattern()];
    }
    
    return $data;
}

/**
 * Obtener patrón por defecto
 */
function getDefaultPattern() {
    return [
        'descripcion' => '',
        'dias_trabajo' => '',
        'dias_descanso' => '',
        'hora_inicio' => '',
        'hora_fin' => ''
    ];
}

// Preparar datos de usuario para el layout
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función para renderizar el contenido
function renderAddGrupoHorarioRotativoContent($form_data, $errors, $empleados) {
    ob_start();
    ?>
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Grupos Horarios', 'url' => '/app/grupos_horarios.php'],
        ['label' => 'Seleccionar Tipo', 'url' => 'grupos_horarios-select.php'],
        ['label' => 'Añadir Grupo Horario Rotativo']
    ]); 
    ?>

    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Añadir Nuevo Grupo de Horario Rotativo</h1>
        <p class="text-gray-600 mt-1">Configure un nuevo grupo de horarios rotativos para su empresa</p>
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
    <?php echo GrupoHorarioRotativoForm::render($form_data, $errors, $empleados, 'create'); ?>

    <!-- JavaScript -->
    <?php MultiSelect::renderScript(); ?>
    
    <script>
        // Form validation and dynamic functionality
        document.addEventListener('DOMContentLoaded', function() {
            initializeFormValidation();
        });

        function initializeFormValidation() {
            // The form component already handles its own validation
            // Just add any additional validation if needed
        }
    </script>
    <?php
    return ob_get_clean();
}

// Renderizar el contenido
$content = renderAddGrupoHorarioRotativoContent($form_data, $errors, $empleados);

// Usar el BaseLayout para renderizar la página completa
BaseLayout::render('Añadir Grupo Horario Rotativo', $content, $config_empresa, $user_data);
?> 