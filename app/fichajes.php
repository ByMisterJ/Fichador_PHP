<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir archivos necesarios
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/Fichajes.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/components/MultiSelect.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';

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

// Inicializar clase Fichajes
$fichajes = new Fichajes();

// Obtener conexión a la base de datos
$pdo = getDbConnection();

// Obtener centro del supervisor para control de acceso
$centro_id_supervisor = null;
if (strtolower($rol_trabajador) === 'supervisor') {
    $stmt = $pdo->prepare("SELECT centro_id FROM trabajador WHERE id = ?");
    $stmt->execute([$trabajador_id]);
    $supervisor_data = $stmt->fetch();
    $centro_id_supervisor = $supervisor_data['centro_id'] ?? null;
}

// Limpiar filtros de sesión si se solicita
if (isset($_GET['clear_filters']) && $_GET['clear_filters'] === '1') {
    unset($_SESSION['fichajes_filtros']);
    // Redirigir para limpiar la URL
    header('Location: fichajes.php');
    exit;
}

// Determinar si hay filtros en la URL
$hay_filtros_url = !empty($_GET);

// Si hay filtros en la URL, usarlos y guardarlos en sesión
if ($hay_filtros_url) {
    $filtros = [
        'hora_desde' => $_GET['hora_desde'] ?? '',
        'hora_hasta' => $_GET['hora_hasta'] ?? '',
        'fecha_desde' => $_GET['fecha_desde'] ?? '',
        'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
        'trabajadores' => isset($_GET['trabajadores']) && is_array($_GET['trabajadores']) ? $_GET['trabajadores'] : [],
        'solo_incidencias' => $_GET['solo_incidencias'] ?? '',
        'tipo_incidencia' => $_GET['tipo_incidencia'] ?? '',
        'mostrar_eliminados' => $_GET['mostrar_eliminados'] ?? ''
    ];

    // Guardar filtros en sesión (excepto mostrar_eliminados y modo_edicion que son estados de UI)
    $_SESSION['fichajes_filtros'] = [
        'hora_desde' => $filtros['hora_desde'],
        'hora_hasta' => $filtros['hora_hasta'],
        'fecha_desde' => $filtros['fecha_desde'],
        'fecha_hasta' => $filtros['fecha_hasta'],
        'trabajadores' => $filtros['trabajadores'],
        'solo_incidencias' => $filtros['solo_incidencias'],
        'tipo_incidencia' => $filtros['tipo_incidencia']
    ];
} else {
    // Si no hay filtros en URL, intentar restaurar desde sesión
    if (isset($_SESSION['fichajes_filtros'])) {
        $filtros = $_SESSION['fichajes_filtros'];
        // Añadir mostrar_eliminados desde GET o vacío
        $filtros['mostrar_eliminados'] = $_GET['mostrar_eliminados'] ?? '';
    } else {
        // Primera vez, usar filtros vacíos
        $filtros = [
            'hora_desde' => '',
            'hora_hasta' => '',
            'fecha_desde' => '',
            'fecha_hasta' => '',
            'trabajadores' => [],
            'solo_incidencias' => '',
            'tipo_incidencia' => '',
            'mostrar_eliminados' => ''
        ];
    }
}

// Establecer fechas por defecto (usar rango con datos reales)
if( $filtros['fecha_desde'] == '') {
    $fecha_ayer = strtotime('-1 day');
    $fecha_ayer = date('Y-m-d', date('w', $fecha_ayer) == 0 ? strtotime('-2 days', $fecha_ayer) : $fecha_ayer);
    $filtros['fecha_desde'] = $filtros['fecha_hasta'] = $fecha_ayer; // Ayer
}

// Estados de la interfaz
$mostrar_eliminados = $filtros['mostrar_eliminados'] === '1';
$modo_edicion = $_GET['modo_edicion'] ?? '';

// Obtener datos para filtros y tabla
$fichajes_list = $fichajes->obtenerFichajesPorEmpresa($empresa_id, $filtros, $centro_id_supervisor);
$empleados_options = $fichajes->obtenerEmpleadosParaFiltro($empresa_id, $centro_id_supervisor);

// Ensure arrays are not null
$fichajes_list = $fichajes_list ?? [];
$empleados_options = $empleados_options ?? [];

// Contar estadísticas
$total_registros = count($fichajes_list);
$empleados_unicos = count(array_unique(array_column($fichajes_list, 'trabajador_id')));
$fechas_unicas = count(array_unique(array_column($fichajes_list, 'fecha')));
$sesiones_abiertas = count(array_filter($fichajes_list, function($f) { return $f['sesion_abierta']; }));

// Preparar datos de usuario para el layout
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función para formatear sesiones usando datos limpios con layout combinado (grid)
function formatearSesionesLimpias($sesiones, $sesiones_incidencias = [], $mostrar_eliminados = false, $modo_edicion = false, $rol_trabajador = 'empleado') {
    if (empty($sesiones)) {
        return '<div class="grid grid-cols-2 gap-4"><span class="text-gray-400 col-span-2">Sin sesiones</span></div>';
    }

    $html_combined = '<div class="grid grid-cols-2 gap-4">';
    $puede_eliminar = in_array(strtolower($rol_trabajador), ['administrador', 'supervisor']);

    foreach ($sesiones as $index => $sesion) {
        if ($index > 0) {
            $html_combined .= '<div class="col-span-2 my-3 border-t border-gray-300"></div>';
        }

        $hora_inicio = $sesion['hora_inicio_sesion'];
        $hora_fin = $sesion['hora_fin_sesion'] ?: '--:--';
        $estado = $sesion['estado'];
        $segundos = (int)($sesion['segundos_sesion'] ?? 0);
        $fichaje_id = $sesion['id'];
        $eliminado = isset($sesion['eliminado']) && $sesion['eliminado'];
        $tiene_incidencia = isset($sesiones_incidencias[$index]);
        $anotacion = $sesion['anotacion_trabajador'] ?? '';

        // LEFT SIDE: Horarios

        if ($tiene_incidencia) {
            // Card naranja para sesión con incidencia
            $card_classes = $eliminado ? 'bg-gray-50 border-gray-200' : 'bg-orange-50 border-orange-200';
            $icon_classes = $eliminado ? 'text-gray-500' : 'text-orange-500';
            $text_classes = $eliminado ? 'text-gray-600' : 'text-orange-700';

            $html_combined .= '<div class="' . $card_classes . ' border rounded-lg p-3">';
            $html_combined .= '<div class="flex items-start gap-2">';
            $html_combined .= '<i class="fas fa-exclamation-triangle ' . $icon_classes . ' mr-2 mt-0.5 flex-shrink-0"></i>';
            $html_combined .= '<div class="flex-1 min-w-0">';

            if ($eliminado) {
                $html_combined .= '<div class="text-xs text-red-500 mb-1 font-medium">• FICHAJE ELIMINADO</div>';
            }

            // Mostrar tipos de incidencia
            $incidencias_sesion = $sesiones_incidencias[$index]['incidencias'] ?? [];
            foreach ($incidencias_sesion as $incidencia) {
                switch ($incidencia) {
                    case 'incidencia_zona_gps':
                        $html_combined .= '<div class="text-xs ' . $text_classes . ' mb-1">• Fichaje fuera de zona GPS</div>';
                        break;
                    case 'incidencia_gps_desactivado':
                        $html_combined .= '<div class="text-xs ' . $text_classes . ' mb-1">• GPS desactivado (requerido)</div>';
                        break;
                    case 'incidencia_horario_fijo':
                        $html_combined .= '<div class="text-xs ' . $text_classes . ' mb-1">• Fichaje fuera del horario fijo</div>';
                        break;
                }
            }

            // Mostrar horarios
            $html_combined .= '<div class="mt-2">';
            $entrada_class = $eliminado ? 'text-gray-600' : 'text-green-600';
            $salida_class = $eliminado ? 'text-gray-600' : 'text-red-600';
            $html_combined .= '<span class="' . $entrada_class . ' text-sm">Entrada: ' . $hora_inicio . '</span><br>';
            $html_combined .= '<span class="' . $salida_class . ' text-sm">Salida: ' . $hora_fin . '</span>';

            if ($estado === 'iniciada') {
                $estado_class = $eliminado ? 'text-gray-500' : 'text-blue-500';
                $html_combined .= '<br><span class="' . $estado_class . ' text-xs font-medium">(Sesión activa)</span>';
            } elseif ($estado === 'finalizada' && $segundos > 0) {
                $duracion = formatearTiempoHorasMinutos($segundos);
                $html_combined .= '<br><span class="text-gray-500 text-xs">Duración: ' . $duracion . '</span>';
            }

            $html_combined .= '</div>';
            $html_combined .= '</div>';

            // Action icons
            $eye_class = $eliminado ? 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' : 'text-orange-600 hover:text-orange-800 hover:bg-orange-100';
            $html_combined .= '<a href="fichaje-view.php?id=' . htmlspecialchars($fichaje_id) . '" ';
            $html_combined .= 'class="inline-flex items-center justify-center w-6 h-6 ' . $eye_class . ' rounded-full transition-colors duration-200" ';
            $html_combined .= 'title="Ver detalles de la sesión ' . ($index + 1) . '">';
            $html_combined .= '<i class="fas fa-eye text-xs"></i>';
            $html_combined .= '</a>';

            if ($puede_eliminar && !$eliminado && $modo_edicion) {
                $html_combined .= '<a href="fichaje-edit.php?id=' . htmlspecialchars($fichaje_id) . '" ';
                $html_combined .= 'class="inline-flex items-center justify-center w-6 h-6 text-blue-600 hover:text-blue-800 hover:bg-blue-100 rounded-full transition-colors duration-200" ';
                $html_combined .= 'title="Editar sesión ' . ($index + 1) . '">';
                $html_combined .= '<i class="fas fa-pen text-xs"></i>';
                $html_combined .= '</a>';
            }

            if ($puede_eliminar && !$eliminado && $modo_edicion) {
                $html_combined .= '<button onclick="confirmarEliminacion(' . $fichaje_id . ')" ';
                $html_combined .= 'class="inline-flex items-center justify-center w-6 h-6 text-red-600 hover:text-red-800 hover:bg-red-100 rounded-full transition-colors duration-200" ';
                $html_combined .= 'title="Eliminar sesión ' . ($index + 1) . '">';
                $html_combined .= '<i class="fas fa-trash text-xs"></i>';
                $html_combined .= '</button>';
            }

            $html_combined .= '</div>';
            $html_combined .= '</div>';
        } else {
            // Sesión normal sin incidencias
            $card_classes = $eliminado ? 'bg-gray-50 border-gray-200' : 'bg-white border-gray-100';
            $html_combined .= '<div class="' . $card_classes . ' border rounded-lg p-3">';
            $html_combined .= '<div class="flex items-start gap-3">';
            $html_combined .= '<div class="flex-1 min-w-0">';

            if ($eliminado) {
                $html_combined .= '<div class="text-xs text-red-500 mb-1 font-medium">• FICHAJE ELIMINADO</div>';
            }

            $entrada_class = $eliminado ? 'text-gray-600' : 'text-green-600';
            $salida_class = $eliminado ? 'text-gray-600' : 'text-red-600';
            $html_combined .= '<span class="' . $entrada_class . ' text-sm">Entrada: ' . $hora_inicio . '</span><br>';
            $html_combined .= '<span class="' . $salida_class . ' text-sm">Salida: ' . $hora_fin . '</span>';

            if ($estado === 'iniciada') {
                $estado_class = $eliminado ? 'text-gray-500' : 'text-blue-500';
                $html_combined .= '<br><span class="' . $estado_class . ' text-xs font-medium">(Sesión activa)</span>';
            } elseif ($estado === 'finalizada' && $segundos > 0) {
                $duracion = formatearTiempoHorasMinutos($segundos);
                $html_combined .= '<br><span class="text-gray-500 text-xs">Duración: ' . $duracion . '</span>';
            }

            $html_combined .= '</div>';

            // Action icons
            $html_combined .= '<div class="flex-shrink-0 flex flex-col gap-1">';
            $eye_class = $eliminado ? 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' : 'text-blue-600 hover:text-blue-800 hover:bg-blue-50';
            $html_combined .= '<a href="fichaje-view.php?id=' . htmlspecialchars($fichaje_id) . '" ';
            $html_combined .= 'class="inline-flex items-center justify-center w-6 h-6 ' . $eye_class . ' rounded-full transition-colors duration-200" ';
            $html_combined .= 'title="Ver detalles de la sesión ' . ($index + 1) . '">';
            $html_combined .= '<i class="fas fa-eye text-xs"></i>';
            $html_combined .= '</a>';

            if ($puede_eliminar && !$eliminado && $modo_edicion) {
                $html_combined .= '<a href="fichaje-edit.php?id=' . htmlspecialchars($fichaje_id) . '" ';
                $html_combined .= 'class="inline-flex items-center justify-center w-6 h-6 text-blue-600 hover:text-blue-800 hover:bg-blue-100 rounded-full transition-colors duration-200" ';
                $html_combined .= 'title="Editar sesión ' . ($index + 1) . '">';
                $html_combined .= '<i class="fas fa-pen text-xs"></i>';
                $html_combined .= '</a>';
            }

            if ($puede_eliminar && !$eliminado && $modo_edicion) {
                $html_combined .= '<button onclick="confirmarEliminacion(' . $fichaje_id . ')" ';
                $html_combined .= 'class="inline-flex items-center justify-center w-6 h-6 text-red-600 hover:text-red-800 hover:bg-red-100 rounded-full transition-colors duration-200" ';
                $html_combined .= 'title="Eliminar sesión ' . ($index + 1) . '">';
                $html_combined .= '<i class="fas fa-trash text-xs"></i>';
                $html_combined .= '</button>';
            }

            $html_combined .= '</div>';
            $html_combined .= '</div>';
            $html_combined .= '</div>';
        }

        // RIGHT SIDE: Anotaciones
        $border_color = $eliminado ? 'border-gray-300' : 'border-blue-200';
        $bg_color = $eliminado ? 'bg-gray-50' : 'bg-blue-50';
        $anotacion_class = $eliminado ? 'text-gray-600' : 'text-gray-800';

        $html_combined .= '<div class="flex items-center gap-2 min-w-[220px] border ' . $border_color . ' rounded-lg p-2 ' . $bg_color . '">';
        $html_combined .= '<div class="flex-1" data-anotacion-container="' . $fichaje_id . '">';
        $html_combined .= '<textarea ';
        $html_combined .= 'id="anotacion-' . $fichaje_id . '" ';
        $html_combined .= 'data-fichaje-id="' . $fichaje_id . '" ';
        $html_combined .= 'data-valor-original="' . htmlspecialchars($anotacion, ENT_QUOTES) . '" ';
        $html_combined .= 'disabled ';
        $html_combined .= 'class="w-full text-xs ' . $anotacion_class . ' bg-white border border-gray-200 rounded p-2 resize-none focus:outline-none focus:ring-0 h-8" ';
        $html_combined .= 'placeholder="Sin anotación">';
        $html_combined .= htmlspecialchars($anotacion);
        $html_combined .= '</textarea>';

        // Botones de guardar/cancelar (ocultos inicialmente)
        $html_combined .= '<div id="anotacion-actions-' . $fichaje_id . '" class="hidden mt-2 flex gap-2 justify-end">';
        $html_combined .= '<button onclick="cancelarEdicionAnotacion(' . $fichaje_id . ')" class="text-xs px-3 py-1.5 bg-gray-500 text-white rounded hover:bg-gray-600 transition-colors flex items-center gap-1">';
        $html_combined .= '<i class="fas fa-times"></i>Cancelar';
        $html_combined .= '</button>';
        $html_combined .= '<button onclick="guardarAnotacion(' . $fichaje_id . ')" class="text-xs px-3 py-1.5 bg-green-600 text-white rounded hover:bg-green-700 transition-colors flex items-center gap-1">';
        $html_combined .= '<i class="fas fa-check"></i>Guardar';
        $html_combined .= '</button>';
        $html_combined .= '</div>';
        $html_combined .= '</div>';

        // Botón de editar anotación
        if ($puede_eliminar && !$eliminado) {
            $html_combined .= '<button ';
            $html_combined .= 'id="btn-edit-anotacion-' . $fichaje_id . '" ';
            $html_combined .= 'onclick="editarAnotacion(' . $fichaje_id . ')" ';
            $html_combined .= 'class="inline-flex items-center justify-center w-6 h-6 text-blue-600 hover:text-blue-800 hover:bg-blue-200 rounded transition-colors duration-200 flex-shrink-0" ';
            $html_combined .= 'title="Editar anotación">';
            $html_combined .= '<i class="fas fa-pen text-xs"></i>';
            $html_combined .= '</button>';
        }

        $html_combined .= '</div>'; // End anotacion card
    }

    $html_combined .= '</div>'; // End grid
    return $html_combined;
}


// Función auxiliar para formatear tiempo HH:MM
function formatearTiempoHorasMinutos($segundos) {
    if ($segundos < 0) $segundos = 0;
    
    $horas = floor($segundos / 3600);
    $minutos = floor(($segundos % 3600) / 60);
    
    return sprintf('%02d:%02d', $horas, $minutos);
}

// Función para formatear sesiones sin ojos (fallback)


// Función para renderizar el contenido de fichajes
function renderFichajesContent($fichajes_list, $filtros, $empleados_options, $opciones_incidencias, $total_registros, $empleados_unicos, $fechas_unicas, $sesiones_abiertas, $rol_trabajador, $config_empresa, $pdo, $mostrar_eliminados = false, $modo_edicion = false) {
    
    ob_start();
    ?>
    
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Búsqueda de Fichajes']
    ]); 
    ?>

    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Búsqueda de Fichajes</h1>
                <p class="text-gray-600 mt-1">Consulta y filtra los registros de entrada y salida de los empleados</p>
            </div>
            <div class="mt-4 sm:mt-0">
                <a href="incidencias-motivos.php" class="<?php echo CSSComponents::getButtonClasses('secondary', 'md'); ?>">
                    <i class="fas fa-cog mr-2"></i>
                    Gestionar Motivos
                </a>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6 mb-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Filtros</h3>
        
        <form method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                <!-- Hora Desde -->
                <div>
                    <label for="hora_desde" class="<?php echo CSSComponents::getLabelClasses(); ?>">Hora Desde</label>
                    <input 
                        type="time" 
                        name="hora_desde" 
                        id="hora_desde"
                        value="<?php echo htmlspecialchars($filtros['hora_desde']); ?>"
                        class="<?php echo CSSComponents::getInputClasses(); ?>"
                    >
                </div>

                <!-- Hora Hasta -->
                <div>
                    <label for="hora_hasta" class="<?php echo CSSComponents::getLabelClasses(); ?>">Hora Hasta</label>
                    <input 
                        type="time" 
                        name="hora_hasta" 
                        id="hora_hasta"
                        value="<?php echo htmlspecialchars($filtros['hora_hasta']); ?>"
                        class="<?php echo CSSComponents::getInputClasses(); ?>"
                    >
                </div>

                <!-- Fecha Desde -->
                <div>
                    <label for="fecha_desde" class="<?php echo CSSComponents::getLabelClasses(); ?>">Desde</label>
                    <input 
                        type="date" 
                        name="fecha_desde" 
                        id="fecha_desde"
                        value="<?php echo htmlspecialchars($filtros['fecha_desde']); ?>"
                        class="<?php echo CSSComponents::getInputClasses(); ?>"
                    >
                </div>

                <!-- Fecha Hasta -->
                <div>
                    <label for="fecha_hasta" class="<?php echo CSSComponents::getLabelClasses(); ?>">Hasta</label>
                    <input 
                        type="date" 
                        name="fecha_hasta" 
                        id="fecha_hasta"
                        value="<?php echo htmlspecialchars($filtros['fecha_hasta']); ?>"
                        class="<?php echo CSSComponents::getInputClasses(); ?>"
                    >
                </div>

                <!-- Seleccionar Empleados -->
                <div>
                    <label class="<?php echo CSSComponents::getLabelClasses(); ?>">Seleccionar Empleados</label>
                    <?php
                    // Preparar opciones para MultiSelect
                    $empleadosOptions = [];
                    foreach ($empleados_options as $empleado) {
                        $empleadosOptions[] = [
                            'value' => $empleado['id'],
                            'label' => $empleado['nombre_completo'] . ' (' . ($empleado['centro_nombre'] ?? 'Sin centro') . ')'
                        ];
                    }
                    
                    echo MultiSelect::render([
                        'name' => 'trabajadores[]',
                        'id' => 'trabajadores',
                        'options' => $empleadosOptions,
                        'selected' => $filtros['trabajadores'],
                        'placeholder' => 'Nada seleccionado',
                        'required' => false,
                        'searchable' => true,
                        'selectAll' => true,
                        'maxHeight' => '200px'
                    ]);
                    ?>
                </div>
            </div>

            <!-- Filtros de Incidencias -->
            <?php if (!empty($opciones_incidencias)): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-gray-200">
                <!-- Solo Incidencias -->
                <div class="flex items-center">
                    <input 
                        type="checkbox" 
                        name="solo_incidencias" 
                        id="solo_incidencias"
                        value="1"
                        <?php echo $filtros['solo_incidencias'] === '1' ? 'checked' : ''; ?>
                        class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
                        onchange="toggleIncidenciaFilter()"
                    >
                    <label for="solo_incidencias" class="ml-2 block text-sm text-gray-900">
                        <i class="fas fa-exclamation-triangle text-orange-500 mr-1"></i>
                        Solo mostrar fichajes con incidencias
                    </label>
                </div>

                <!-- Tipo de Incidencia -->
                <div id="tipo_incidencia_container" style="<?php echo $filtros['solo_incidencias'] === '1' ? '' : 'display: none;'; ?>">
                    <label for="tipo_incidencia" class="<?php echo CSSComponents::getLabelClasses(); ?>">Tipo de Incidencia</label>
                    <select 
                        name="tipo_incidencia" 
                        id="tipo_incidencia"
                        class="<?php echo CSSComponents::getSelectClasses(); ?>"
                    >
                        <option value="">Todas las incidencias</option>
                        <?php foreach ($opciones_incidencias as $opcion): ?>
                            <option 
                                value="<?php echo htmlspecialchars($opcion['value']); ?>"
                                <?php echo $filtros['tipo_incidencia'] === $opcion['value'] ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($opcion['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <div class="flex flex-col lg:flex-row lg:justify-between lg:items-center gap-4 pt-4 border-t border-gray-200">
                <!-- Controles de eliminación (solo para supervisores y administradores) -->
                <?php if (in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])): ?>
                <div class="flex flex-col sm:flex-row gap-3">
                    <!-- Botón mostrar eliminados -->
                    <button 
                        type="button" 
                        onclick="toggleMostrarEliminados()"
                        class="<?php echo $mostrar_eliminados ? CSSComponents::getButtonClasses('primary', 'md') : CSSComponents::getButtonClasses('outline', 'md'); ?>"
                        id="btnMostrarEliminados"
                    >
                        <i class="fas fa-eye<?php echo $mostrar_eliminados ? '' : '-slash'; ?> mr-2"></i>
                        <?php echo $mostrar_eliminados ? 'Ocultar Eliminados' : 'Mostrar Eliminados'; ?>
                    </button>
                    
                    <!-- Botón modo edición (3 puntos) -->
                    <button 
                        type="button" 
                        onclick="toggleModoEdicion()"
                        class="<?php echo $modo_edicion ? CSSComponents::getButtonClasses('primary', 'md') : CSSComponents::getButtonClasses('outline', 'md'); ?>"
                        id="btnModoEdicion"
                        title="Activar/desactivar modo edición"
                    >
                        <i class="fas fa-ellipsis-v mr-2"></i>
                        <?php echo $modo_edicion ? 'Desactivar Edición' : 'Activar Edición'; ?>
                    </button>
                </div>
                <?php endif; ?>
                
                <!-- Botones de filtros -->
                <div class="flex flex-col sm:flex-row gap-3">
                    <button type="submit" class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?>">
                        <i class="fas fa-filter mr-2"></i>
                        Aplicar Filtros
                    </button>
                    <a href="fichajes.php?clear_filters=1" class="<?php echo CSSComponents::getButtonClasses('outline', 'md'); ?>">
                        <i class="fas fa-times mr-2"></i>
                        Borrar Filtros
                    </a>
                
                <!-- Botón de exportación -->
                <div class="relative w-full sm:w-auto">
                    <!-- Botón dropdown -->
                    <button 
                        type="button" 
                        onclick="toggleExportDropdown()"
                        class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?> w-full sm:w-auto inline-flex items-center justify-center"
                    >
                        <i class="fas fa-download mr-2"></i>
                        Exportar
                        <i class="fas fa-chevron-down ml-2"></i>
                    </button>
                    
                    <!-- Dropdown menu -->
                    <div id="exportDropdown" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg z-10 hidden">
                        <div class="py-1">
                            <button 
                                type="button" 
                                onclick="exportarFichajes('PDF')"
                                class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center"
                            >
                                <i class="fas fa-file-pdf mr-2 text-red-500"></i>
                                PDF
                            </button>
                            <button 
                                type="button" 
                                onclick="exportarFichajes('EXCEL')"
                                class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center"
                            >
                                <i class="fas fa-file-excel mr-2 text-green-500"></i>
                                EXCEL
                            </button>
                            <button 
                                type="button" 
                                onclick="exportarFichajes('CSV')"
                                class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center"
                            >
                                <i class="fas fa-file-csv mr-2 text-blue-500"></i>
                                CSV
                            </button>
                        </div>
                    </div>
                </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Mensajes de error de exportación -->
    <?php if (isset($_GET['error'])): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('error'); ?>">
            <div class="flex items-start">
                <i class="fas fa-exclamation-triangle text-red-500 mr-3 mt-0.5"></i>
                <div class="flex-1">
                    <?php if ($_GET['error'] === 'parametros_invalidos'): ?>
                        <h3 class="text-red-800 font-medium mb-1">Parámetros inválidos</h3>
                        <p class="text-red-700 text-sm">No se pueden exportar los datos sin aplicar filtros. Aplique al menos un filtro antes de exportar.</p>
                    <?php elseif ($_GET['error'] === 'sin_datos'): ?>
                        <h3 class="text-red-800 font-medium mb-1">Sin datos</h3>
                        <p class="text-red-700 text-sm">No se encontraron datos para exportar con los filtros aplicados.</p>
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

    <!-- Results Message -->
    <?php if (!empty($_GET) && empty($fichajes_list) && !isset($_GET['error'])): ?>
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-8 mb-6 text-center">
            <i class="fas fa-search text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No se encontraron resultados</h3>
            <p class="text-gray-600">
                No hay fichajes que coincidan con los filtros seleccionados. 
                Intenta ajustar los criterios de búsqueda.
            </p>
        </div>
    <?php endif; ?>

    <!-- Fichajes Table -->
    <?php if (!empty($fichajes_list)): ?>
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> overflow-hidden">
        <div class="overflow-x-auto">
            <table class="<?php echo CSSComponents::getTableClasses(); ?>">
                <thead class="<?php echo CSSComponents::getTableHeaderClasses(); ?>">
                    <tr>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> cursor-pointer hover:bg-gray-100">
                            <div class="flex items-center">
                                Nombre Empleado y DNI
                                <i class="fas fa-sort ml-2 text-gray-400"></i>
                            </div>
                        </th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> cursor-pointer hover:bg-gray-100">
                            <div class="flex items-center">
                                Fecha
                                <i class="fas fa-sort ml-2 text-gray-400"></i>
                            </div>
                        </th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> w-auto max-w-xs">
                            Horarios
                        </th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> w-auto">
                            Anotaciones
                        </th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">
                            Total Horas
                        </th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-info-circle text-gray-400 mr-1" title="Horas trabajadas por encima de la jornada estándar"></i>
                                Horas Extra
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($fichajes_list as $fichaje): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> align-top py-6">
                            <div class="flex items-start">
                                <div class="flex-shrink-0 h-8 w-8">
                                    <div class="h-8 w-8 rounded-full bg-gray-300 flex items-center justify-center">
                                        <span class="text-xs font-medium text-gray-700">
                                            <?php echo strtoupper(substr($fichaje['nombre_completo'], 0, 2)); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="ml-3">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($fichaje['nombre_completo']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($fichaje['nombre_trabajador']); ?><br/>
										(<?php echo htmlspecialchars($fichaje['dni']); ?>)
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> align-top py-6">
                            <span class="text-sm text-gray-900">
                                <?php echo $fichaje['fecha_formateada']; ?>
                            </span>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> align-top py-6 w-auto" colspan="2">
                            <div class="text-sm">
                                <?php
                                // Mostrar incidencia general si la hay (solo "sin horario")
                                $incidencias_generales = array_intersect($fichaje['incidencias'], ['incidencia_sin_horario', 'incidencia_horas_extra_ventana']);
                                if (!empty($incidencias_generales)): ?>
                                    <div class="bg-orange-50 border border-orange-200 rounded-lg p-3 mb-3">
                                        <div class="flex items-start">
                                            <i class="fas fa-exclamation-triangle text-orange-500 mr-2 mt-0.5 flex-shrink-0"></i>
                                            <div class="flex-1">
                                                <div class="text-xs text-orange-700">
                                                    <?php if (in_array('incidencia_sin_horario', $incidencias_generales)): ?>
                                                        • Sin horario establecido
                                                    <?php endif; ?>
                                                    <?php if (in_array('incidencia_horas_extra_ventana', $incidencias_generales)): ?>
                                                        • Horas extra fuera de ventana permitida
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php
                                // Obtener horarios y anotaciones en formato combinado
                                $sesiones_incidencias = $fichaje['sesiones_incidencias'] ?? [];
                                $sesiones_formateadas = formatearSesionesLimpias($fichaje['sesiones'], $sesiones_incidencias, $mostrar_eliminados, $modo_edicion, $rol_trabajador);
                                echo $sesiones_formateadas;
                                ?>
                            </div>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center align-top py-6">
                            <span class="text-sm font-medium text-gray-900">
                                <?php echo $fichaje['total_horas']; ?>
                            </span>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center align-top py-6">
                            <span class="text-sm font-medium <?php echo $fichaje['horas_extra'] !== '00:00' ? 'text-orange-600' : 'text-gray-500'; ?>">
                            <i class="fas fa-exclamation-triangle"></i>
                                <?php echo $fichaje['horas_extra']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Table Footer with Summary -->
        <div class="bg-gray-50 px-6 py-3 border-t border-gray-200">
            <div class="flex items-center justify-between text-sm text-gray-600">
                <div>
                    Mostrando <?php echo number_format($total_registros); ?> registro(s) 
                    de <?php echo number_format($empleados_unicos); ?> empleado(s) 
                    en <?php echo number_format($fechas_unicas); ?> día(s)
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MultiSelect Script -->
    <?php echo MultiSelect::renderScript(); ?>

    <!-- JavaScript para manejo de filtros de incidencias y exportación -->
    <script>
        function toggleIncidenciaFilter() {
            const checkbox = document.getElementById('solo_incidencias');
            const container = document.getElementById('tipo_incidencia_container');
            const select = document.getElementById('tipo_incidencia');
            
            if (checkbox.checked) {
                container.style.display = '';
            } else {
                container.style.display = 'none';
                select.value = ''; // Limpiar selección cuando se desactiva
            }
        }
        
        // Manejar dropdown de exportación
        function toggleExportDropdown() {
            const dropdown = document.getElementById('exportDropdown');
            dropdown.classList.toggle('hidden');
        }
        
        // Función para exportar fichajes
        function exportarFichajes(formato) {
            // Cerrar dropdown
            document.getElementById('exportDropdown').classList.add('hidden');
            
            // Obtener parámetros actuales de la URL
            const urlParams = new URLSearchParams(window.location.search);
            
            // Agregar formato
            urlParams.set('formato', formato);
            
            // Construir URL de exportación
            const exportUrl = 'generar_fichajes_export.php?' + urlParams.toString();
            
            // Abrir en nueva ventana/pestaña para descarga
            window.open(exportUrl, '_blank');
        }
        
        // Cerrar dropdown al hacer clic fuera
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('exportDropdown');
            const button = event.target.closest('button');
            
            if (!button || !button.onclick || button.onclick.toString().indexOf('toggleExportDropdown') === -1) {
                dropdown.classList.add('hidden');
            }
        });
        
        // Función para toggle mostrar eliminados
        function toggleMostrarEliminados() {
            const urlParams = new URLSearchParams(window.location.search);
            const mostrarEliminados = urlParams.get('mostrar_eliminados') === '1';
            
            if (mostrarEliminados) {
                urlParams.delete('mostrar_eliminados');
            } else {
                urlParams.set('mostrar_eliminados', '1');
            }
            
            // Mantener otros parámetros y recargar
            window.location.search = urlParams.toString();
        }
        
        // Función para toggle modo edición
        function toggleModoEdicion() {
            const urlParams = new URLSearchParams(window.location.search);
            const modoEdicion = urlParams.get('modo_edicion') === '1';
            
            if (modoEdicion) {
                urlParams.delete('modo_edicion');
            } else {
                urlParams.set('modo_edicion', '1');
            }
            
            // Mantener otros parámetros y recargar
            window.location.search = urlParams.toString();
        }
        
        // Función para confirmar eliminación de fichaje
        function confirmarEliminacion(fichajeId) {
            if (confirm('¿Está seguro de que desea eliminar este fichaje? Esta acción no se puede deshacer.')) {
                eliminarFichaje(fichajeId);
            }
        }
        
        // Función para eliminar fichaje via AJAX
        function eliminarFichaje(fichajeId) {
            fetch('ajax/eliminar_fichaje.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    fichaje_id: fichajeId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mostrar mensaje de éxito
                    alert('Fichaje eliminado correctamente');
                    // Recargar la página para mostrar los cambios
                    window.location.reload();
                } else {
                    // Mostrar mensaje de error
                    alert('Error al eliminar el fichaje: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error de conexión al eliminar el fichaje');
            });
        }

        // Función para activar edición de anotación
        function editarAnotacion(fichajeId) {
            const textarea = document.getElementById('anotacion-' + fichajeId);
            const btnEdit = document.getElementById('btn-edit-anotacion-' + fichajeId);
            const actions = document.getElementById('anotacion-actions-' + fichajeId);

            // Habilitar el textarea y cambiar estilos
            textarea.removeAttribute('disabled');
            textarea.classList.remove('border-gray-200');
            textarea.classList.add('border-blue-500', 'ring-2', 'ring-blue-200', 'shadow-sm');
            textarea.focus();

            // Ocultar botón de editar y mostrar acciones
            if (btnEdit) {
                btnEdit.classList.add('hidden');
            }
            actions.classList.remove('hidden');
        }

        // Función para cancelar edición de anotación
        function cancelarEdicionAnotacion(fichajeId) {
            const textarea = document.getElementById('anotacion-' + fichajeId);
            const btnEdit = document.getElementById('btn-edit-anotacion-' + fichajeId);
            const actions = document.getElementById('anotacion-actions-' + fichajeId);

            // Restaurar valor original desde data attribute
            const valorOriginal = textarea.getAttribute('data-valor-original');
            textarea.value = valorOriginal;

            // Deshabilitar el textarea y restaurar estilos
            textarea.setAttribute('disabled', true);
            textarea.classList.remove('border-blue-500', 'ring-2', 'ring-blue-200', 'shadow-sm');
            textarea.classList.add('border-gray-200');

            // Mostrar botón de editar y ocultar acciones
            if (btnEdit) {
                btnEdit.classList.remove('hidden');
            }
            actions.classList.add('hidden');
        }

        // Función para guardar anotación
        function guardarAnotacion(fichajeId) {
            const textarea = document.getElementById('anotacion-' + fichajeId);
            const nuevaAnotacion = textarea.value;
            const btnEdit = document.getElementById('btn-edit-anotacion-' + fichajeId);
            const actions = document.getElementById('anotacion-actions-' + fichajeId);

            // Realizar llamada AJAX para guardar la anotación
            fetch('ajax/actualizar_anotacion.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    fichaje_id: fichajeId,
                    anotacion: nuevaAnotacion
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Actualizar el valor original en el data attribute
                    textarea.setAttribute('data-valor-original', nuevaAnotacion);

                    // Deshabilitar modo edición y restaurar estilos
                    textarea.setAttribute('disabled', true);
                    textarea.classList.remove('border-blue-500', 'ring-2', 'ring-blue-200', 'shadow-sm');
                    textarea.classList.add('border-gray-200');

                    // Mostrar botón de editar y ocultar acciones
                    if (btnEdit) {
                        btnEdit.classList.remove('hidden');
                    }
                    actions.classList.add('hidden');

                    // Opcional: Mostrar mensaje de éxito
                    console.log('Anotación guardada correctamente');
                } else {
                    alert('Error al guardar la anotación: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error de conexión al guardar la anotación');
            });
        }

        // Inicializar estado al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            toggleIncidenciaFilter();
        });
    </script>

    <?php
    return ob_get_clean();
}

// Función para obtener las opciones de incidencias basadas en la configuración de la empresa
function obtenerOpcionesIncidencias($empresa_id) {
    require_once __DIR__ . '/../shared/models/Empresa.php';
    $empresa = new Empresa();
    $config_incidencias = $empresa->getIncidenciaConfiguration($empresa_id);
    
    $opciones = [];
    
    if ($config_incidencias['incidencia_sin_horario']) {
        $opciones[] = [
            'value' => 'incidencia_sin_horario',
            'label' => 'Sin horario establecido'
        ];
    }
    
    if ($config_incidencias['incidencia_zona_gps']) {
        $opciones[] = [
            'value' => 'incidencia_zona_gps',
            'label' => 'Fuera de zona GPS'
        ];
    }
    
    if ($config_incidencias['incidencia_gps_desactivado']) {
        $opciones[] = [
            'value' => 'incidencia_gps_desactivado',
            'label' => 'GPS desactivado'
        ];
    }
    
    if ($config_incidencias['incidencia_horario_fijo']) {
        $opciones[] = [
            'value' => 'incidencia_horario_fijo',
            'label' => 'Fuera de horario fijo'
        ];
    }
    
    if ($config_incidencias['incidencia_horas_extra_ventana']) {
        $opciones[] = [
            'value' => 'incidencia_horas_extra_ventana',
            'label' => 'Exceso horas extra'
        ];
    }
    
    return $opciones;
}

// Obtener opciones de incidencias
$opciones_incidencias = obtenerOpcionesIncidencias($empresa_id);

// Renderizar la página
$content = renderFichajesContent($fichajes_list, $filtros, $empleados_options, $opciones_incidencias, $total_registros, $empleados_unicos, $fechas_unicas, $sesiones_abiertas, $rol_trabajador, $config_empresa, $pdo, $mostrar_eliminados, $modo_edicion === '1');

BaseLayout::render('Búsqueda de Fichajes', $content, $config_empresa, $user_data);
?>
