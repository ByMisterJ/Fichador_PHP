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
require_once __DIR__ . '/../shared/forms/GrupoHorarioFijoForm.php';

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
$form_data = $gruposHorarios->cargarDatosGrupoHorarioFijo($grupo_id, $empresa_id);
if (!$form_data) {
    header('Location: grupos_horarios.php');
    exit;
}

// Obtener la lista de empleados activos de la empresa para el selector de asignación del grupo.
$empleados = obtenerEmpleadosEmpresa($empresa_id);

// Procesar el envío del formulario (método HTTP POST) validando y persistiendo los datos.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_data = procesarFormularioFijo($_POST);
    
    // Validar los datos del formulario usando el validador centralizado antes de persistir.
    $errors = GrupoHorarioValidator::validarHorarioFijo($post_data);
    
    if (empty($errors)) {
        // Persistir los cambios del grupo horario en la base de datos usando el método del modelo.
        $resultado = $gruposHorarios->actualizarGrupoHorarioFijo($grupo_id, $post_data, $empresa_id);
        
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
function procesarFormularioFijo($post_data) {
    $data = [
        'nombre' => trim($post_data['nombre'] ?? ''),
        'descripcion' => trim($post_data['descripcion'] ?? ''),
        'empleados' => $post_data['empleados'] ?? [],
        'horarios' => []
    ];
    
    // Iterar sobre las líneas de horario del POST y normalizar sus valores para persistencia.
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
    
    // Garantizar que siempre exista al menos una línea de horario vacía para facilitar la UI.
    if (empty($data['horarios'])) {
        $data['horarios'] = [['meses' => [], 'dia' => '', 'hora_entrada' => '', 'hora_salida' => '']];
    }
    
    return $data;
}

// Preparar el array de datos del usuario que se pasará al layout base para la cabecera de navegación.
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función encapsuladora que genera el HTML del contenido principal usando output buffering.
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
        // Inicializar la validación del formulario y las funcionalidades dinámicas del lado cliente.
        document.addEventListener('DOMContentLoaded', function() {
            initializeFormValidation();
            initializeDynamicHorarios();
        });

        function initializeFormValidation() {
            const form = document.getElementById('grupoHorarioForm');
            if (!form) return;

            form.addEventListener('submit', function(e) {
                const errors = [];
                
                // Validar los campos básicos del formulario (nombre del grupo).
                const nombre = document.getElementById('nombre').value.trim();
                if (!nombre) {
                    errors.push('El nombre del grupo es obligatorio');
                }
                
                // Validar que cada línea de horario tenga todos los campos obligatorios correctamente cumplimentados.
                const horarioRows = document.querySelectorAll('.horario-row');
                if (horarioRows.length === 0) {
                    errors.push('Debe configurar al menos una línea de horario');
                } else {
                    horarioRows.forEach((row, index) => {
                        const mesesSelect = row.querySelector('[name*="[meses]"]');
                        const diaSelect = row.querySelector('[name*="[dia]"]');
                        const horaEntrada = row.querySelector('[name*="[hora_entrada]"]');
                        const horaSalida = row.querySelector('[name*="[hora_salida]"]');
                        
                        if (mesesSelect && mesesSelect.selectedOptions.length === 0) {
                            errors.push(`Línea ${index + 1}: Debe seleccionar al menos un mes`);
                        }
                        
                        if (diaSelect && !diaSelect.value) {
                            errors.push(`Línea ${index + 1}: Debe seleccionar un día`);
                        }
                        
                        if (horaEntrada && !horaEntrada.value) {
                            errors.push(`Línea ${index + 1}: Debe especificar hora de entrada`);
                        }
                        
                        if (horaSalida && !horaSalida.value) {
                            errors.push(`Línea ${index + 1}: Debe especificar hora de salida`);
                        }
                        
                        if (horaEntrada && horaSalida && horaEntrada.value && horaSalida.value) {
                            if (horaEntrada.value >= horaSalida.value) {
                                errors.push(`Línea ${index + 1}: La hora de salida debe ser posterior a la hora de entrada`);
                            }
                        }
                    });
                }
                
                if (errors.length > 0) {
                    e.preventDefault();
                    alert('Errores encontrados:\n\n' + errors.join('\n'));
                }
            });
        }

        function initializeDynamicHorarios() {
            // Gestionar la adición de nuevas líneas de horario mediante delegación de eventos en el contenedor.
            document.addEventListener('click', function(e) {
                if (e.target.matches('.add-horario-btn')) {
                    e.preventDefault();
                    addHorarioLine();
                }
                
                if (e.target.matches('.remove-horario-btn')) {
                    e.preventDefault();
                    removeHorarioLine(e.target);
                }
            });
        }

        function addHorarioLine() {
            const container = document.getElementById('horarios-container');
            if (!container) return;
            
            const existingRows = container.querySelectorAll('.horario-row');
            const newIndex = existingRows.length;
            
            const template = getHorarioRowTemplate(newIndex);
            container.insertAdjacentHTML('beforeend', template);
            
            // Inicializar el componente MultiSelect en la nueva fila recién insertada en el DOM.
            const newRow = container.lastElementChild;
            const multiselect = newRow.querySelector('.multiselect-container');
            if (multiselect && window.MultiSelect) {
                window.MultiSelect.init(multiselect);
            }
        }

        function removeHorarioLine(button) {
            const row = button.closest('.horario-row');
            if (row) {
                const container = document.getElementById('horarios-container');
                const remainingRows = container.querySelectorAll('.horario-row');
                
                if (remainingRows.length > 1) {
                    row.remove();
                    updateHorarioIndices();
                } else {
                    alert('Debe mantener al menos una línea de horario');
                }
            }
        }

        function updateHorarioIndices() {
            const container = document.getElementById('horarios-container');
            const rows = container.querySelectorAll('.horario-row');
            
            rows.forEach((row, index) => {
                // Actualizar los atributos name de los campos para mantener el índice correcto en el array POST.
                const fields = row.querySelectorAll('input, select');
                fields.forEach(field => {
                    if (field.name) {
                        field.name = field.name.replace(/\[\d+\]/, `[${index}]`);
                    }
                });
                
                // Actualizar las etiquetas visibles con el número de línea correcto tras reordenar las filas.
                const label = row.querySelector('.horario-label');
                if (label) {
                    label.textContent = `Línea de Horario ${index + 1}`;
                }
            });
        }

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

// Capturar el HTML generado mediante output buffering e invocarlo con los datos preparados.
$content = renderEditGrupoHorarioFijoContent($form_data, $errors, $empleados, $grupo_id);

// Invocar el layout base para construir y enviar la respuesta HTML completa al cliente.
BaseLayout::render('Editar Grupo Horario Fijo', $content, $config_empresa, $user_data);
?> 