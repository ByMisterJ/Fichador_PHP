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
require_once __DIR__ . '/../shared/forms/GrupoHorarioFijoForm.php';

// Verificar autenticación
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Verificar que el usuario tenga permisos (solo administradores y supervisores)
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    header('Location: /app/dashboard.php');
    exit;
}

// Obtener datos del trabajador de la sesión
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

// Obtener configuración de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Obtener ID del grupo horario
$grupo_id = intval($_GET['id'] ?? 0);
if (!$grupo_id) {
    header('Location: grupos_horarios.php');
    exit;
}

// Inicializar variables
$errors = [];
$form_data = [];

// Inicializar clase GruposHorarios
$gruposHorarios = new GruposHorarios();

// Cargar datos del grupo horario
$form_data = $gruposHorarios->cargarDatosGrupoHorarioFijo($grupo_id, $empresa_id);
if (!$form_data) {
    header('Location: grupos_horarios.php');
    exit;
}

// Obtener empleados de la empresa
$empleados = obtenerEmpleadosEmpresa($empresa_id);

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_data = procesarFormularioFijo($_POST);
    
    // Validar datos
    $errors = GrupoHorarioValidator::validarHorarioFijo($post_data);
    
    if (empty($errors)) {
        // Actualizar grupo horario usando la clase centralizada
        $resultado = $gruposHorarios->actualizarGrupoHorarioFijo($grupo_id, $post_data, $empresa_id);
        
        if ($resultado['success']) {
            header('Location: grupos_horarios.php?success=grupo_actualizado');
            exit;
        } else {
            $errors['general'] = 'Error al actualizar el grupo horario: ' . $resultado['error'];
        }
    } else {
        // Update form data with posted values
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
function procesarFormularioFijo($post_data) {
    $data = [
        'nombre' => trim($post_data['nombre'] ?? ''),
        'descripcion' => trim($post_data['descripcion'] ?? ''),
        'empleados' => $post_data['empleados'] ?? [],
        'horarios' => []
    ];
    
    // Procesar horarios
    if (isset($post_data['horarios']) && is_array($post_data['horarios'])) {
        foreach ($post_data['horarios'] as $horario) {
            $data['horarios'][] = [
                'meses' => is_array($horario['meses'] ?? []) ? $horario['meses'] : [],
                'dia' => trim($horario['dia'] ?? ''),
                'hora_entrada' => trim($horario['hora_entrada'] ?? ''),
                'hora_salida' => trim($horario['hora_salida'] ?? '')
            ];
        }
    }
    
    // Asegurar al menos un horario vacío si no hay ninguno
    if (empty($data['horarios'])) {
        $data['horarios'] = [['meses' => [], 'dia' => '', 'hora_entrada' => '', 'hora_salida' => '']];
    }
    
    return $data;
}

// Preparar datos de usuario para el layout
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función para renderizar el contenido
function renderEditGrupoHorarioFijoContent($form_data, $errors, $empleados, $grupo_id) {
    ob_start();
    ?>
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Grupos Horarios', 'url' => '/app/grupos_horarios.php'],
        ['label' => 'Editar Grupo Horario Fijo']
    ]); 
    ?>

    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Editar Grupo de Horario Fijo</h1>
        <p class="text-gray-600 mt-1">Modifique la configuración del grupo de horarios fijos</p>
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

    <?php echo GrupoHorarioFijoForm::render($form_data, $errors, $empleados, 'edit', $grupo_id); ?>


    <!-- JavaScript -->
    <?php MultiSelect::renderScript(); ?>
    
    <script>
        // Form validation and dynamic functionality
        document.addEventListener('DOMContentLoaded', function() {
            initializeFormValidation();
            initializeDynamicHorarios();
        });

        

        function getHorarioRowTemplate(index) {
            return `
                <div class="horario-row p-4 border border-gray-200 rounded-lg bg-gray-50">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="horario-label font-medium text-gray-900">Línea de Horario ${index + 1}</h4>
                        <button type="button" class="remove-horario-btn text-red-600 hover:text-red-800 transition-colors">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    
                    <div class="<?php echo CSSComponents::getFormGridClasses(2); ?>">
                        <div class="<?php echo CSSComponents::getFieldWrapperClasses(); ?>">
                            <label class="<?php echo CSSComponents::getLabelClasses(); ?>">Meses *</label>
                            <div class="multiselect-container" 
                                data-name="horarios[${index}][meses]" 
                                data-placeholder="Seleccionar meses..."
                                data-searchable="false"
                                data-select-all="true">
                                <select multiple style="display: none;">
                                    <option value="1">Enero</option>
                                    <option value="2">Febrero</option>
                                    <option value="3">Marzo</option>
                                    <option value="4">Abril</option>
                                    <option value="5">Mayo</option>
                                    <option value="6">Junio</option>
                                    <option value="7">Julio</option>
                                    <option value="8">Agosto</option>
                                    <option value="9">Septiembre</option>
                                    <option value="10">Octubre</option>
                                    <option value="11">Noviembre</option>
                                    <option value="12">Diciembre</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="<?php echo CSSComponents::getFieldWrapperClasses(); ?>">
                            <label class="<?php echo CSSComponents::getLabelClasses(); ?>">Día *</label>
                            <select name="horarios[${index}][dia]" class="<?php echo CSSComponents::getSelectClasses(); ?>">
                                <option value="">Seleccionar día</option>
                                <option value="lunes">Lunes</option>
                                <option value="martes">Martes</option>
                                <option value="miercoles">Miércoles</option>
                                <option value="jueves">Jueves</option>
                                <option value="viernes">Viernes</option>
                                <option value="sabado">Sábado</option>
                                <option value="domingo">Domingo</option>
                            </select>
                        </div>
                        
                        <div class="<?php echo CSSComponents::getFieldWrapperClasses(); ?>">
                            <label class="<?php echo CSSComponents::getLabelClasses(); ?>">Hora de Entrada *</label>
                            <input type="time" name="horarios[${index}][hora_entrada]" class="<?php echo CSSComponents::getInputClasses(); ?>">
                        </div>
                        
                        <div class="<?php echo CSSComponents::getFieldWrapperClasses(); ?>">
                            <label class="<?php echo CSSComponents::getLabelClasses(); ?>">Hora de Salida *</label>
                            <input type="time" name="horarios[${index}][hora_salida]" class="<?php echo CSSComponents::getInputClasses(); ?>">
                        </div>
                    </div>
                </div>
            `;
        }
    </script>
    <?php
    return ob_get_clean();
}

// Renderizar el contenido
$content = renderEditGrupoHorarioFijoContent($form_data, $errors, $empleados, $grupo_id);

// Usar el BaseLayout para renderizar la página completa
BaseLayout::render('Editar Grupo Horario Fijo', $content, $config_empresa, $user_data);
?> 