<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Verificar autenticación
require_once __DIR__ . '/../shared/models/Trabajador.php';
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Verificar permisos (solo administrador y supervisor)
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    header('Location: /app/dashboard.php');
    exit;
}

// Includes necesarios
require_once __DIR__ . '/../shared/models/Informes.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/components/MultiSelect.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';

// Datos del usuario
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$empresa_id = $_SESSION['empresa_id'] ?? null;

if (!$empresa_id) {
    header('Location: /app/login.php');
    exit;
}

// Obtener configuración de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Inicializar clase de informes
$informes = new Informes();

// Obtener datos para los filtros
$empleados = $informes->obtenerTrabajadoresParaFiltro($empresa_id);

// Establecer fechas por defecto (usar rango con datos reales)
$fecha_ayer = strtotime('-1 day');
$fecha_ayer = date('Y-m-d', date('w', $fecha_ayer) == 0 ? strtotime('-2 days', $fecha_ayer) : $fecha_ayer);
$primer_dia_mes = $ultimo_dia_mes = $fecha_ayer; // Ayer

// Procesar formulario
$errors = [];
$success_message = '';
$form_data = [
    'fecha_desde' => $primer_dia_mes,
    'fecha_hasta' => $ultimo_dia_mes
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data = procesarFormularioInforme($_POST);
    $errors = validarFormularioInforme($form_data);
    
    if (empty($errors)) {
        // Redirigir a generar PDF
        $params = http_build_query([
            'tipo' => 'trabajadores_fechas',
            'formato' => $form_data['formato'],
            'hora_desde' => $form_data['hora_desde'],
            'hora_hasta' => $form_data['hora_hasta'],
            'fecha_desde' => $form_data['fecha_desde'],
            'fecha_hasta' => $form_data['fecha_hasta'],
            'empleados' => implode(',', $form_data['empleados']),
            'incluir_dias_libres' => $form_data['incluir_dias_libres'] ? '1' : '0',
            'mostrar_horas_extra' => $form_data['mostrar_horas_extra'] ? '1' : '0',
            'mostrar_horas_nocturnas' => $form_data['mostrar_horas_nocturnas'] ? '1' : '0'
        ]);
        
        header('Location: generar_trabajadores_fechas_pdf.php?' . $params);
        exit;
    }
}

/**
 * Procesar datos del formulario
 */
function procesarFormularioInforme($post_data) {
    return [
        'hora_desde' => trim($post_data['hora_desde'] ?? ''),
        'hora_hasta' => trim($post_data['hora_hasta'] ?? ''),
        'fecha_desde' => trim($post_data['fecha_desde'] ?? ''),
        'fecha_hasta' => trim($post_data['fecha_hasta'] ?? ''),
        'empleados' => $post_data['empleados'] ?? [],
        'incluir_dias_libres' => isset($post_data['incluir_dias_libres']),
        'mostrar_horas_extra' => isset($post_data['mostrar_horas_extra']),
        'mostrar_horas_nocturnas' => isset($post_data['mostrar_horas_nocturnas']),
        'formato' => $post_data['formato'] ?? 'PDF'
    ];
}

/**
 * Validar datos del formulario
 */
function validarFormularioInforme($data) {
    $errors = [];
    
    // Validar fechas
    if (empty($data['fecha_desde'])) {
        $errors['fecha_desde'] = 'La fecha desde es obligatoria';
    }
    
    if (empty($data['fecha_hasta'])) {
        $errors['fecha_hasta'] = 'La fecha hasta es obligatoria';
    }
    
    if (!empty($data['fecha_desde']) && !empty($data['fecha_hasta'])) {
        if ($data['fecha_desde'] > $data['fecha_hasta']) {
            $errors['fecha_hasta'] = 'La fecha hasta debe ser posterior a la fecha desde';
        }
    }
    
    // Validar horas
    if (!empty($data['hora_desde']) && !empty($data['hora_hasta'])) {
        if ($data['hora_desde'] >= $data['hora_hasta']) {
            $errors['hora_hasta'] = 'La hora hasta debe ser posterior a la hora desde';
        }
    }
    
    // Validar empleados
    if (empty($data['empleados'])) {
        $errors['empleados'] = 'Debe seleccionar al menos un empleado';
    }
    
    return $errors;
}

/**
 * Renderizar contenido de la página
 */
function renderContent() {
    global $errors, $success_message, $empleados, $form_data;
    
    ob_start();
    ?>
    
    <!-- Breadcrumb -->
    <?php
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Informes', 'url' => '/app/informes.php'],
        ['label' => 'Trabajadores y Fechas']
    ]);
    ?>

    <!-- Encabezado principal del informe -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 flex items-center">
                    Informe de Trabajadores y Fechas
                </h1>
                <p class="text-gray-600 mt-1">
                    Genere informes detallados de fichajes por empleado y período de tiempo
                </p>
            </div>
            <div class="mt-4 sm:mt-0">
                <a href="informes.php" class="<?php echo CSSComponents::getButtonClasses('outline', 'md'); ?>">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Volver a Informes
                </a>
            </div>
        </div>
    </div>

    <!-- Información del informe -->
    <div class="mt-4 p-4 bg-blue-50 rounded-lg border border-blue-200 mb-6">
        <h3 class="font-semibold text-blue-900 mb-2">¿Cómo funciona este informe?</h3>
        <div class="text-sm text-blue-800 space-y-1">
            <p><strong>📊 Fichajes:</strong> Se muestran todos los fichajes del período seleccionado, organizados por empleado y fecha.</p>
            <p><strong>📈 Horas Extra:</strong> Se calculan las horas que exceden la jornada normal según el grupo horario asignado.</p>
            <p><strong>🌙 Horas Nocturnas:</strong> Se identifican las horas trabajadas entre las 22:00 y las 06:00.</p>
            <p><strong>📅 Vacaciones:</strong> Se pueden incluir los días de vacaciones y ausencias justificadas en el informe.</p>
        </div>
    </div>

    <!-- Mensajes de error -->
    <?php if (!empty($errors)): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('error'); ?>">
            <div class="flex items-start">
                <i class="fas fa-exclamation-triangle text-red-500 mr-3 mt-0.5"></i>
                <div class="flex-1">
                    <h3 class="text-red-800 font-medium mb-2">Se encontraron errores:</h3>
                    <ul class="text-red-700 text-sm space-y-1">
                        <?php foreach ($errors as $field => $error): ?>
                            <li>• <?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Mensajes de error de exportación -->
    <?php if (isset($_GET['error'])): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('error'); ?>">
            <div class="flex items-start">
                <i class="fas fa-exclamation-triangle text-red-500 mr-3 mt-0.5"></i>
                <div class="flex-1">
                    <?php if ($_GET['error'] === 'parametros_invalidos'): ?>
                        <h3 class="text-red-800 font-medium mb-1">Parámetros inválidos</h3>
                        <p class="text-red-700 text-sm">Faltan parámetros requeridos para generar el informe. Verifique las fechas y empleados seleccionados.</p>
                    <?php elseif ($_GET['error'] === 'sin_datos'): ?>
                        <h3 class="text-red-800 font-medium mb-1">Sin datos</h3>
                        <p class="text-red-700 text-sm">No se encontraron datos para el período y filtros seleccionados.</p>
                    <?php elseif ($_GET['error'] === 'error_pdf'): ?>
                        <h3 class="text-red-800 font-medium mb-1">Error generando PDF</h3>
                        <p class="text-red-700 text-sm">Ocurrió un error al generar el archivo PDF. Inténtelo de nuevo.</p>
                    <?php elseif ($_GET['error'] === 'error_csv'): ?>
                        <h3 class="text-red-800 font-medium mb-1">Error generando CSV</h3>
                        <p class="text-red-700 text-sm">Ocurrió un error al generar el archivo CSV. Inténtelo de nuevo.</p>
                    <?php elseif ($_GET['error'] === 'error_excel'): ?>
                        <h3 class="text-red-800 font-medium mb-1">Error generando Excel</h3>
                        <p class="text-red-700 text-sm">Ocurrió un error al generar el archivo Excel. Inténtelo de nuevo.</p>
                    <?php else: ?>
                        <h3 class="text-red-800 font-medium mb-1">Error</h3>
                        <p class="text-red-700 text-sm">Ocurrió un error inesperado. Inténtelo de nuevo.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Formulario -->
    <form method="POST" class="space-y-6" onsubmit="return validateForm()">
            
        <!-- Filtros de tiempo -->
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">
                <i class="fas fa-clock mr-2"></i>
                Filtros de Tiempo
            </h3>
            
            <div class="<?php echo CSSComponents::getFormGridClasses(4); ?>">
                <!-- Hora desde -->
                <div class="<?php echo CSSComponents::getFieldWrapperClasses(); ?>">
                    <label class="<?php echo CSSComponents::getLabelClasses(); ?>">
                        Hora desde
                    </label>
                    <input 
                        type="time" 
                        name="hora_desde" 
                        id="hora_desde"
                        value="<?php echo htmlspecialchars($form_data['hora_desde'] ?? ''); ?>"
                        class="<?php echo CSSComponents::getInputClasses(); ?>"
                    >
                    <?php if (isset($errors['hora_desde'])): ?>
                        <p class="<?php echo CSSComponents::getErrorTextClasses(); ?>">
                            <?php echo htmlspecialchars($errors['hora_desde']); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Hora hasta -->
                <div class="<?php echo CSSComponents::getFieldWrapperClasses(); ?>">
                    <label class="<?php echo CSSComponents::getLabelClasses(); ?>">
                        Hora hasta
                    </label>
                    <input 
                        type="time" 
                        name="hora_hasta" 
                        id="hora_hasta"
                        value="<?php echo htmlspecialchars($form_data['hora_hasta'] ?? ''); ?>"
                        class="<?php echo CSSComponents::getInputClasses(); ?>"
                    >
                    <?php if (isset($errors['hora_hasta'])): ?>
                        <p class="<?php echo CSSComponents::getErrorTextClasses(); ?>">
                            <?php echo htmlspecialchars($errors['hora_hasta']); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Fecha desde -->
                <div class="<?php echo CSSComponents::getFieldWrapperClasses(); ?>">
                    <label class="<?php echo CSSComponents::getLabelClasses(); ?>">
                        Desde <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="date" 
                        name="fecha_desde" 
                        id="fecha_desde"
                        value="<?php echo htmlspecialchars($form_data['fecha_desde'] ?? ''); ?>"
                        class="<?php echo CSSComponents::getInputClasses(isset($errors['fecha_desde']) ? 'error' : ''); ?>"
                        required
                    >
                    <?php if (isset($errors['fecha_desde'])): ?>
                        <p class="<?php echo CSSComponents::getErrorTextClasses(); ?>">
                            <?php echo htmlspecialchars($errors['fecha_desde']); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Fecha hasta -->
                <div class="<?php echo CSSComponents::getFieldWrapperClasses(); ?>">
                    <label class="<?php echo CSSComponents::getLabelClasses(); ?>">
                        Hasta <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="date" 
                        name="fecha_hasta" 
                        id="fecha_hasta"
                        value="<?php echo htmlspecialchars($form_data['fecha_hasta'] ?? ''); ?>"
                        class="<?php echo CSSComponents::getInputClasses(isset($errors['fecha_hasta']) ? 'error' : ''); ?>"
                        required
                    >
                    <?php if (isset($errors['fecha_hasta'])): ?>
                        <p class="<?php echo CSSComponents::getErrorTextClasses(); ?>">
                            <?php echo htmlspecialchars($errors['fecha_hasta']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Selección de empleados -->
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">
                <i class="fas fa-users mr-2"></i>
                Selección de Empleados
            </h3>
            
            <div class="<?php echo CSSComponents::getFieldWrapperClasses(); ?>">
                <label class="<?php echo CSSComponents::getLabelClasses(); ?>">
                    Empleados <span class="text-red-500">*</span>
                </label>
                
                <?php
                $options = [];
                foreach ($empleados as $empleado) {
                    $options[] = [
                        'value' => $empleado['id'],
                        'label' => $empleado['nombre'] . ' - ' . $empleado['dni']
                    ];
                }
                
                echo MultiSelect::render([
                    'name' => 'empleados[]',
                    'id' => 'empleados',
                    'options' => $options,
                    'selected' => $form_data['empleados'] ?? [],
                    'placeholder' => 'Seleccione empleados...',
                    'required' => true,
                    'searchable' => true,
                    'selectAll' => true,
                    'maxHeight' => '250px',
                    'error' => $errors['empleados'] ?? null
                ]);
                ?>
            </div>
        </div>

        <!-- Opciones del informe -->
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">
                <i class="fas fa-cog mr-2"></i>
                Opciones del Informe
            </h3>
            
            <div class="space-y-4">
                <!-- Incluir días libres -->
                <div class="flex items-center">
                    <input 
                        type="checkbox" 
                        name="incluir_dias_libres" 
                        id="incluir_dias_libres"
                        value="1"
                        <?php echo (isset($form_data['incluir_dias_libres']) && $form_data['incluir_dias_libres']) ? 'checked' : ''; ?>
                        class="w-4 h-4 text-primary-600 bg-gray-100 border-gray-300 rounded focus:ring-primary-500 focus:ring-2"
                    >
                    <label for="incluir_dias_libres" class="ml-2 text-sm font-medium text-gray-900">
                        Incluir días libres/vacaciones/ausencias
                    </label>
                </div>

                <!-- Mostrar horas extra -->
                <div class="flex items-center">
                    <input 
                        type="checkbox" 
                        name="mostrar_horas_extra" 
                        id="mostrar_horas_extra"
                        value="1"
                        <?php echo (isset($form_data['mostrar_horas_extra']) && $form_data['mostrar_horas_extra']) ? 'checked' : ''; ?>
                        class="w-4 h-4 text-primary-600 bg-gray-100 border-gray-300 rounded focus:ring-primary-500 focus:ring-2"
                    >
                    <label for="mostrar_horas_extra" class="ml-2 text-sm font-medium text-gray-900">
                        Mostrar horas extra
                    </label>
                </div>

                <!-- Mostrar horas nocturnas -->
                <div class="flex items-center">
                    <input 
                        type="checkbox" 
                        name="mostrar_horas_nocturnas" 
                        id="mostrar_horas_nocturnas"
                        value="1"
                        <?php echo (isset($form_data['mostrar_horas_nocturnas']) && $form_data['mostrar_horas_nocturnas']) ? 'checked' : ''; ?>
                        class="w-4 h-4 text-primary-600 bg-gray-100 border-gray-300 rounded focus:ring-primary-500 focus:ring-2"
                    >
                    <label for="mostrar_horas_nocturnas" class="ml-2 text-sm font-medium text-gray-900">
                        Mostrar horas nocturnas (22:00 - 06:00)
                    </label>
                </div>
            </div>
        </div>

        <!-- Botones de acción -->
        <div class="<?php echo CSSComponents::getActionButtonGroupClasses(); ?> pb-28">
            <div class="relative w-full sm:inline-block sm:w-auto">
                <!-- Botón dropdown -->
                <button 
                    type="button" 
                    onclick="toggleDropdown()"
                    class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?> w-full sm:w-auto inline-flex items-center justify-center"
                >
                    <i class="fas fa-download mr-2"></i>
                    Descargar Informe Fichajes
                    <i class="fas fa-chevron-down ml-2"></i>
                </button>
                
                <!-- Dropdown menu -->
                <div id="formatDropdown" class="absolute left-0 sm:left-0 mt-2 w-full sm:w-48 bg-white rounded-md shadow-lg z-10 hidden">
                    <div class="py-1">
                        <button 
                            type="submit" 
                            name="formato" 
                            value="PDF"
                            class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center"
                        >
                            <i class="fas fa-file-pdf mr-2 text-red-500"></i>
                            PDF
                        </button>
                        <button 
                            type="submit" 
                            name="formato" 
                            value="EXCEL"
                            class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center"
                        >
                            <i class="fas fa-file-excel mr-2 text-green-500"></i>
                            EXCEL
                        </button>
                        <button 
                            type="submit" 
                            name="formato" 
                            value="CSV"
                            class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center"
                        >
                            <i class="fas fa-file-csv mr-2 text-blue-500"></i>
                            CSV
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- JavaScript -->
    <script>
        // Validación del formulario
        function validateForm() {
            const errors = [];
            
            // Validar fechas requeridas
            const fechaDesde = document.getElementById('fecha_desde').value;
            const fechaHasta = document.getElementById('fecha_hasta').value;
            
            if (!fechaDesde) {
                errors.push('La fecha desde es obligatoria');
            }
            
            if (!fechaHasta) {
                errors.push('La fecha hasta es obligatoria');
            }
            
            if (fechaDesde && fechaHasta && fechaDesde > fechaHasta) {
                errors.push('La fecha hasta debe ser posterior a la fecha desde');
            }
            
            // Validar horas
            const horaDesde = document.getElementById('hora_desde').value;
            const horaHasta = document.getElementById('hora_hasta').value;
            
            if (horaDesde && horaHasta && horaDesde >= horaHasta) {
                errors.push('La hora hasta debe ser posterior a la hora desde');
            }
            
            // Validar empleados
            const empleadosSelect = document.getElementById('empleados');
            let hasSelection = false;
            
            if (empleadosSelect) {
                for (let option of empleadosSelect.options) {
                    if (option.selected) {
                        hasSelection = true;
                        break;
                    }
                }
            }
            
            if (!hasSelection) {
                errors.push('Debe seleccionar al menos un empleado');
            }
            
            if (errors.length > 0) {
                alert('Errores encontrados:\n\n' + errors.join('\n'));
                return false;
            }
            
            return true;
        }
        
        // Manejar dropdown
        function toggleDropdown() {
            const dropdown = document.getElementById('formatDropdown');
            dropdown.classList.toggle('hidden');
        }
        
        // Cerrar dropdown al hacer clic fuera
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('formatDropdown');
            const button = event.target.closest('button');
            
            if (!button || !button.onclick || button.onclick.toString().indexOf('toggleDropdown') === -1) {
                dropdown.classList.add('hidden');
            }
        });
        
        // Inicializar MultiSelect
        document.addEventListener('DOMContentLoaded', function() {
            if (window.MultiSelect) {
                const multiselects = document.querySelectorAll('.multiselect-container');
                multiselects.forEach(ms => {
                    window.MultiSelect.init(ms);
                });
            }
        });
    </script>
    
    <?php
    // Incluir script de MultiSelect
    echo MultiSelect::renderScript();
    ?>
    
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
BaseLayout::render('Informe de Trabajadores y Fechas', renderContent(), $config_empresa, $user_data);
?> 