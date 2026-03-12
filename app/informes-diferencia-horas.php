<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir las clases necesarias
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/Informes.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/components/MultiSelect.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';
require_once __DIR__ . '/../shared/forms/InformeDiferenciaHorasForm.php';

// Verificar autenticación
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Verificar permisos (solo admin y supervisor)
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    header('Location: /app/dashboard.php');
    exit;
}

// Obtener datos de la sesión
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$empresa_id = $_SESSION['empresa_id'] ?? null;
$centro_id_supervisor = (strtolower($rol_trabajador) === 'supervisor') ? ($_SESSION['centro_id'] ?? null) : null;

// Obtener configuración de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Inicializar variables
$informes = new Informes();
$form_data = [];
$errors = [];
$resultados_informe = [];
$mostrar_resultados = false;
$informe_automatico = false;

// Inicializar datos del formulario con valores por defecto
$form_data = [
    'fecha_desde' => date('Y-m-01'), // Primer día del mes actual
    'fecha_hasta' => date('Y-m-t'),  // Último día del mes actual
    'centro_id' => null,
    'trabajadores' => [], // Se llenará con todos los trabajadores
    'grupo_horario_id' => null
];

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar_informe'])) {
    $form_data = procesarFormularioInforme($_POST);
    $errors = validarFiltrosInforme($form_data);
    
    if (empty($errors)) {
        $filtros = [
            'fecha_desde' => $form_data['fecha_desde'],
            'fecha_hasta' => $form_data['fecha_hasta'],
            'centro_id' => $form_data['centro_id'],
            'trabajadores' => $form_data['trabajadores'],
            'grupo_horario_id' => $form_data['grupo_horario_id']
        ];
        
        // Aplicar filtro de supervisor si corresponde
        if ($centro_id_supervisor !== null && empty($filtros['centro_id'])) {
            $filtros['centro_id'] = $centro_id_supervisor;
        }
        
        $resultados_informe = $informes->calcularDiferenciaHoras($empresa_id, $filtros);
        $mostrar_resultados = true;
    }
}

// Obtener opciones para los filtros
$opciones_filtros = [
    'centros' => $informes->obtenerCentrosParaFiltro($empresa_id),
    'trabajadores' => $informes->obtenerTrabajadoresParaFiltro($empresa_id),
    'grupos_horario' => $informes->obtenerGruposHorarioParaFiltro($empresa_id)
];

// Filtrar opciones según permisos de supervisor
if ($centro_id_supervisor !== null) {
    $opciones_filtros['centros'] = array_filter($opciones_filtros['centros'], function($centro) use ($centro_id_supervisor) {
        return $centro['id'] == $centro_id_supervisor;
    });
}

// Generar informe automáticamente al cargar la página (solo si no es POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Seleccionar todos los trabajadores por defecto
    $form_data['trabajadores'] = array_map(function($trabajador) {
        return (int)$trabajador['id'];
    }, $opciones_filtros['trabajadores']);
    
    // Generar informe automáticamente
    $filtros = [
        'fecha_desde' => $form_data['fecha_desde'],
        'fecha_hasta' => $form_data['fecha_hasta'],
        'centro_id' => $form_data['centro_id'],
        'trabajadores' => $form_data['trabajadores'],
        'grupo_horario_id' => $form_data['grupo_horario_id']
    ];
    
    // Aplicar filtro de supervisor si corresponde
    if ($centro_id_supervisor !== null && empty($filtros['centro_id'])) {
        $filtros['centro_id'] = $centro_id_supervisor;
    }
    
    try {
        $resultados_informe = $informes->calcularDiferenciaHoras($empresa_id, $filtros);
        $mostrar_resultados = true;
        
        // Marcar que el informe fue generado automáticamente
        $informe_automatico = true;
    } catch (Exception $e) {
        error_log("Error al generar informe automático: " . $e->getMessage());
        $errors['general'] = 'Error al generar el informe automático. Por favor, inténtelo de nuevo.';
    }
}

/**
 * Procesar datos del formulario
 */
function procesarFormularioInforme($post_data) {
    // Procesar trabajadores seleccionados
    $trabajadores = [];
    if (isset($post_data['trabajadores']) && is_array($post_data['trabajadores'])) {
        $trabajadores = array_map('intval', array_filter($post_data['trabajadores']));
    }
    
    return [
        'fecha_desde' => trim($post_data['fecha_desde'] ?? ''),
        'fecha_hasta' => trim($post_data['fecha_hasta'] ?? ''),
        'centro_id' => !empty($post_data['centro_id']) ? (int)$post_data['centro_id'] : null,
        'trabajadores' => $trabajadores, // Empty array means all employees
        'grupo_horario_id' => !empty($post_data['grupo_horario_id']) ? (int)$post_data['grupo_horario_id'] : null
    ];
}

/**
 * Validar filtros del informe
 */
function validarFiltrosInforme($data) {
    $errors = [];
    
    // Validar fechas
    if (empty($data['fecha_desde'])) {
        $errors['fecha_desde'] = 'La fecha de inicio es obligatoria';
    } elseif (!DateTime::createFromFormat('Y-m-d', $data['fecha_desde'])) {
        $errors['fecha_desde'] = 'Formato de fecha inválido';
    }
    
    if (empty($data['fecha_hasta'])) {
        $errors['fecha_hasta'] = 'La fecha de fin es obligatoria';
    } elseif (!DateTime::createFromFormat('Y-m-d', $data['fecha_hasta'])) {
        $errors['fecha_hasta'] = 'Formato de fecha inválido';
    }
    
    // Validar rango de fechas
    if (!empty($data['fecha_desde']) && !empty($data['fecha_hasta'])) {
        if ($data['fecha_desde'] > $data['fecha_hasta']) {
            $errors['fecha_hasta'] = 'La fecha de fin debe ser posterior a la fecha de inicio';
        }
        
        // Validar que el período no sea excesivamente largo
        $inicio = new DateTime($data['fecha_desde']);
        $fin = new DateTime($data['fecha_hasta']);
        $diferencia = $inicio->diff($fin);
        if ($diferencia->days > 365) {
            $errors['general'] = 'El período seleccionado no puede exceder 1 año';
        }
    }
    
    return $errors;
}

/**
 * Renderizar contenido principal
 */
function renderContent() {
    global $form_data, $errors, $opciones_filtros, $resultados_informe, $mostrar_resultados;
    
    ob_start();
    ?>
    
    <!-- Breadcrumb -->
    <?php
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Informes', 'url' => '/app/informes.php', 'icon' => 'fas fa-chart-bar'],
        ['label' => 'Diferencia de Horas']
    ]);
    ?>
    
    <!-- Encabezado principal del informe -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 flex items-center">
                    Diferencia de Horas
                </h1>
                <p class="text-gray-600 mt-1">
                    Compara las horas trabajadas con las horas esperadas según el grupo horario asignado.
                </p>
            </div>
        </div>
    </div>
        
    <!-- Información del informe -->
    <div class="mt-4 p-4 bg-blue-50 rounded-lg border border-blue-200 mb-6">
        <h3 class="font-semibold text-blue-900 mb-2">¿Cómo funciona este informe?</h3>
        <div class="text-sm text-blue-800 space-y-1">
            <p><strong>📊 Horas Totales:</strong> Se calculan sumando todos los fichajes del período seleccionado, incluyendo las horas justificadas por ausencias aprobadas.</p>
            <p><strong>📈 Diferencia:</strong> Es la diferencia entre las horas trabajadas y las esperadas según el grupo horario. Si es negativa, significa que faltan horas; si es positiva, hay exceso.</p>
            <p><strong>📅 Período:</strong> El cálculo se realiza desde la fecha de inicio hasta la fecha fin seleccionada.</p>
            <p><strong>🔄 Horarios Flexibles:</strong> Para horarios flexibles mensuales/anuales, la diferencia se calcula proporcionalmente al período seleccionado.</p>
        </div>
    </div>
    
    <!-- Mostrar errores generales -->
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
    
    <!-- Formulario de filtros -->
    <?php echo InformeDiferenciaHorasForm::render($form_data, $errors, $opciones_filtros); ?>
    
    <!-- Resultados del informe -->
    <?php if ($mostrar_resultados): ?>
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6 mt-6">
            <?php if (isset($informe_automatico) && $informe_automatico): ?>
                <!-- Notificación de informe automático -->
                <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                        <span class="text-sm text-blue-800">
                            Informe generado automáticamente para el mes actual con todos los empleados seleccionados.
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-table text-green-600 mr-2"></i>
                    Resultados del Informe
                </h2>
                <span class="text-sm text-gray-600">
                    <?php echo count($resultados_informe); ?> trabajador<?php echo count($resultados_informe) !== 1 ? 'es' : ''; ?> encontrado<?php echo count($resultados_informe) !== 1 ? 's' : ''; ?>
                </span>
            </div>
            
            <?php if (empty($resultados_informe)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-search text-gray-400 text-4xl mb-4"></i>
                    <p class="text-gray-600">No se encontraron resultados para los filtros seleccionados.</p>
                </div>
            <?php else: ?>
                <!-- Estadísticas resumen -->
                <?php
                $total_trabajadores = count($resultados_informe);
                $trabajadores_deficit = array_filter($resultados_informe, function($r) { return $r['tiene_deficit']; });
                $trabajadores_exceso = array_filter($resultados_informe, function($r) { return $r['tiene_exceso']; });
                $trabajadores_exacto = $total_trabajadores - count($trabajadores_deficit) - count($trabajadores_exceso);
                ?>
                
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-gray-50 p-4 rounded-lg text-center">
                        <div class="text-2xl font-bold text-gray-900"><?php echo $total_trabajadores; ?></div>
                        <div class="text-sm text-gray-600">Total Trabajadores</div>
                    </div>
                    <div class="bg-red-50 p-4 rounded-lg text-center">
                        <div class="text-2xl font-bold text-red-600"><?php echo count($trabajadores_deficit); ?></div>
                        <div class="text-sm text-red-600">Con Déficit</div>
                    </div>
                    <div class="bg-green-50 p-4 rounded-lg text-center">
                        <div class="text-2xl font-bold text-green-600"><?php echo count($trabajadores_exceso); ?></div>
                        <div class="text-sm text-green-600">Con Exceso</div>
                    </div>
                    <div class="bg-blue-50 p-4 rounded-lg text-center">
                        <div class="text-2xl font-bold text-blue-600"><?php echo $trabajadores_exacto; ?></div>
                        <div class="text-sm text-blue-600">Exactos</div>
                    </div>
                </div>
                
                <!-- Tabla de resultados -->
                <div class="overflow-x-auto">
                    <table class="<?php echo CSSComponents::getTableClasses(); ?>">
                        <thead class="<?php echo CSSComponents::getTableHeaderClasses(); ?>">
                            <tr>
                                <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">Empleado</th>
                                <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">DNI</th>
                                <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">Horas Totales</th>
                                <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">Horas Nocturnas</th>
                                <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">Diferencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados_informe as $resultado): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                        <div class="font-medium text-gray-900">
                                            <?php echo htmlspecialchars($resultado['nombre_completo']); ?>
                                        </div>
                                    </td>
                                    <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                        <span class="text-sm text-gray-600">
                                            <?php echo htmlspecialchars($resultado['dni']); ?>
                                        </span>
                                    </td>
                                    <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">
                                        <span class="text-sm font-semibold">
                                            <?php echo $resultado['total_horas']; ?>
                                        </span>
                                    </td>
                                    <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">
                                        <span class="text-sm text-purple-600">
                                            <?php echo $resultado['horas_nocturnas'] ?? '0:00'; ?>
                                        </span>
                                    </td>
                                    <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">
                                        <?php
                                        $clase_diferencia = '';
                                        if ($resultado['tiene_deficit']) {
                                            $clase_diferencia = 'text-red-600 font-semibold';
                                        } elseif ($resultado['tiene_exceso']) {
                                            $clase_diferencia = 'text-green-600 font-semibold';
                                        } else {
                                            $clase_diferencia = 'text-gray-600';
                                        }
                                        ?>
                                        <span class="text-sm <?php echo $clase_diferencia; ?>">
                                            <?php echo $resultado['diferencia']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <script>
        function descargarPDF() {
            // Crear formulario dinámico para enviar datos al generador de PDF
            const form = document.createElement('form');
            form.method = 'get';
            form.action = 'generar_diferencia_horas_pdf.php';
            form.target = '_blank';
            
            // Obtener datos del formulario actual
            const fechaDesde = document.getElementById('fecha_desde').value;
            const fechaHasta = document.getElementById('fecha_hasta').value;
            const centroId = document.getElementById('centro_id').value;
            const grupoHorarioId = document.getElementById('grupo_horario_id').value;
            
            // Agregar campos ocultos
            form.appendChild(createHiddenInput('fecha_desde', fechaDesde));
            form.appendChild(createHiddenInput('fecha_hasta', fechaHasta));
            form.appendChild(createHiddenInput('centro', centroId));
            form.appendChild(createHiddenInput('grupo_horario', grupoHorarioId));
            
            // Agregar trabajadores seleccionados
            const trabajadoresSelect = document.getElementById('trabajadores');
            if (trabajadoresSelect) {
                const selectedOptions = Array.from(trabajadoresSelect.selectedOptions);
                selectedOptions.forEach(option => {
                    form.appendChild(createHiddenInput('trabajadores[]', option.value));
                });
            }
            
            // Agregar formulario al DOM y enviarlo
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        
        function createHiddenInput(name, value) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            return input;
        }
    </script>
    
    <?php
    return ob_get_clean();
}

// Preparar datos del usuario para el layout
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Renderizar página
BaseLayout::render('Informe - Diferencia de Horas', renderContent(), $config_empresa, $user_data);
?>
