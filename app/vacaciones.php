<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir los modelos, componentes y utilidades necesarios para esta vista.
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/Vacaciones.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/components/MultiSelect.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';

// Verificar que el usuario dispone de una sesión autenticada válida; de lo contrario, redirigir al login.
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Obtener el rol del trabajador autenticado para determinar el nivel de acceso al módulo de vacaciones.
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';

// Recuperar los datos identificativos del usuario autenticado desde la superglobal $_SESSION.
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

// Obtener la configuración de la empresa (colores, logo, nombre de app, etc.) desde la sesión.
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Instanciar el modelo Vacaciones para acceder a los métodos de gestión de periodos vacacionales.
$vacaciones = new Vacaciones();

// Obtener la conexión PDO a la base de datos para consultas directas fuera del modelo.
$pdo = getDbConnection();

// Obtener el centro asignado al supervisor para filtrar las solicitudes de su ámbito.
$centro_id_supervisor = null;
if (strtolower($rol_trabajador) === 'supervisor') {
    $stmt = $pdo->prepare("SELECT centro_id FROM trabajador WHERE id = ?");
    $stmt->execute([$trabajador_id]);
    $supervisor_data = $stmt->fetch();
    $centro_id_supervisor = $supervisor_data['centro_id'] ?? null;
}

// Leer los parámetros de filtrado enviados por GET para restringir el listado de vacaciones.
$filtros = [
    'fecha_inicio' => $_GET['fecha_inicio'] ?? '',
    'fecha_fin' => $_GET['fecha_fin'] ?? '',
    'trabajadores' => isset($_GET['trabajadores']) && is_array($_GET['trabajadores']) ? $_GET['trabajadores'] : [],
    'centro_id' => $_GET['centro_id'] ?? '',
    'estado' => isset($_GET['estado']) ? $_GET['estado'] : 'pendiente', // Default a pendiente solo si no se ha enviado el parámetro
    'motivo' => $_GET['motivo'] ?? ''
];

// En la primera carga sin parámetros, aplicar el filtro por defecto (año actual).
if (empty($_GET)) {
    $filtros['estado'] = 'pendiente';
}

// Para usuarios con rol empleado, restringir la vista a sus propias solicitudes de vacaciones.
if (strtolower($rol_trabajador) === 'empleado') {
    $filtros['trabajador_id'] = $trabajador_id;
}

// Obtener los datos necesarios para los selectores de filtros y la tabla principal de vacaciones.
$vacaciones_list = $vacaciones->obtenerVacacionesPorEmpresa($empresa_id, $filtros, $centro_id_supervisor);
$empleados_options = $vacaciones->obtenerEmpleadosParaFiltro($empresa_id, $centro_id_supervisor);
$centros_options = $vacaciones->obtenerCentrosParaFiltro($empresa_id);
$motivos_options = $vacaciones->obtenerMotivosDisponibles();
$estados_options = $vacaciones->obtenerEstadosDisponibles();

// Asegurar que los arrays de datos no sean null para evitar errores en iteraciones posteriores.
$vacaciones_list = $vacaciones_list ?? [];
$empleados_options = $empleados_options ?? [];
$centros_options = $centros_options ?? [];
$motivos_options = $motivos_options ?? [];
$estados_options = $estados_options ?? [];

// Calcular las estadísticas agregadas (aprobadas, pendientes, rechazadas) para el encabezado.
$total_vacaciones = count($vacaciones_list);
$pendientes = count(array_filter($vacaciones_list, function($v) { return $v['estado'] === 'pendiente'; }));
$aprobadas = count(array_filter($vacaciones_list, function($v) { return $v['estado'] === 'aprobada'; }));
$rechazadas = count(array_filter($vacaciones_list, function($v) { return $v['estado'] === 'rechazada'; }));

// Procesar acciones pendientes o notificaciones de operaciones anteriores (éxito, error, etc.).

// Preparar el array de datos del usuario que se pasará al layout base para la cabecera de navegación.
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función encapsuladora que genera el HTML del módulo de vacaciones usando output buffering.
function renderVacacionesContent($vacaciones_list, $filtros, $empleados_options, $centros_options, $motivos_options, $estados_options, $total_vacaciones, $pendientes, $aprobadas, $rechazadas, $rol_trabajador, $config_empresa) {
    
    ob_start();
    ?>
    
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Vacaciones / Ausencias']
    ]); 
    ?>

    <!-- Error Messages -->
    <?php if (isset($_GET['error'])): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('error'); ?>">
            <div class="flex items-start">
                <i class="fas fa-exclamation-triangle text-red-500 mr-3 mt-0.5"></i>
                <div class="flex-1">
                    <?php if ($_GET['error'] === 'sin_datos'): ?>
                        <h3 class="text-red-800 font-medium mb-1">Sin datos</h3>
                        <p class="text-red-700 text-sm">No se encontraron vacaciones para exportar con los filtros aplicados.</p>
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

    <!-- Success Message -->
    <?php if (isset($_GET['success'])): ?>
        <?php if ($_GET['success'] === 'vacacion_creada'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Solicitud de vacaciones creada exitosamente!</h3>
                    <p class="text-green-700 text-sm mt-1">La nueva solicitud ha sido añadida al sistema.</p>
                </div>
            </div>
        </div>
        <?php elseif ($_GET['success'] === 'vacacion_actualizada'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Solicitud de vacaciones actualizada exitosamente!</h3>
                    <p class="text-green-700 text-sm mt-1">Los datos de la solicitud han sido modificados.</p>
                </div>
            </div>
        </div>
        <?php elseif ($_GET['success'] === 'vacacion_eliminada'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Solicitud de vacaciones eliminada exitosamente!</h3>
                    <p class="text-green-700 text-sm mt-1">La solicitud ha sido eliminada del sistema.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    <?php echo strtolower($rol_trabajador) === 'empleado' ? 'Mis Vacaciones / Días Libres / Ausencias' : 'Vacaciones / Días Libres / Ausencias'; ?>
                </h1>
                <p class="text-gray-600 mt-1">
                    <?php echo strtolower($rol_trabajador) === 'empleado' ? 'Gestiona tus solicitudes de vacaciones y ausencias' : 'Gestiona las solicitudes de vacaciones y ausencias de los empleados'; ?>
                </p>
            </div>
            <div class="mt-4 sm:mt-0 flex flex-col sm:flex-row gap-3">
                <?php if (strtolower($rol_trabajador) === 'administrador'): ?>
                    <a href="festivos.php" class="<?php echo CSSComponents::getButtonClasses('secondary', 'md'); ?>">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        Calendario Festivo
                    </a>
                <?php endif; ?>
                <a href="vacaciones-add.php" class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?>">
                    <i class="fas fa-plus mr-2"></i>
                    Nueva Solicitud
                </a>
            </div>
        </div>
    </div>

    <!-- Filtros Avanzados -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6 mb-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Filtros</h3>
        
        <form method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <!-- Fecha Inicio -->
                <div>
                    <label for="fecha_inicio" class="<?php echo CSSComponents::getLabelClasses(); ?>">Fecha Inicio</label>
                    <input 
                        type="date" 
                        name="fecha_inicio" 
                        value="<?php echo htmlspecialchars($filtros['fecha_inicio']); ?>"
                        class="<?php echo CSSComponents::getInputClasses(); ?>"
                    >
                </div>

                <!-- Fecha Fin -->
                <div>
                    <label for="fecha_fin" class="<?php echo CSSComponents::getLabelClasses(); ?>">Fecha Fin</label>
                    <input 
                        type="date" 
                        name="fecha_fin" 
                        value="<?php echo htmlspecialchars($filtros['fecha_fin']); ?>"
                        class="<?php echo CSSComponents::getInputClasses(); ?>"
                    >
                </div>

                <!-- Trabajadores (solo para admin/supervisor) -->
                <?php if (strtolower($rol_trabajador) !== 'empleado'): ?>
                    <div>
                        <label class="<?php echo CSSComponents::getLabelClasses(); ?>">Trabajadores</label>
                        <?php
                        // Preparar opciones para MultiSelect
                        $empleadosOptions = [];
                        foreach ($empleados_options as $empleado) {
                            $empleadosOptions[] = [
                                'value' => $empleado['id'],
                                'label' => $empleado['nombre_completo']
                            ];
                        }
                        
                        echo MultiSelect::render([
                            'name' => 'trabajadores[]',
                            'id' => 'trabajadores',
                            'options' => $empleadosOptions,
                            'selected' => $filtros['trabajadores'],
                            'placeholder' => 'Seleccionar trabajadores',
                            'required' => false,
                            'searchable' => true,
                            'selectAll' => true,
                            'maxHeight' => '200px'
                        ]);
                        ?>
                    </div>

                    <!-- Departamento -->
                    <div>
                        <label class="<?php echo CSSComponents::getLabelClasses(); ?>">Departamento</label>
                        <select name="centro_id" class="<?php echo CSSComponents::getSelectClasses(); ?>">
                            <option value="">Todos los departamentos</option>
                            <?php foreach ($centros_options as $centro): ?>
                                <option value="<?php echo $centro['id']; ?>" 
                                        <?php echo $filtros['centro_id'] == $centro['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($centro['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Estado -->
                <div>
                    <label class="<?php echo CSSComponents::getLabelClasses(); ?>">Estado</label>
                    <select name="estado" class="<?php echo CSSComponents::getSelectClasses(); ?>">
                        <option value="">Todos los estados</option>
                        <?php foreach ($estados_options as $key => $label): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>" 
                                    <?php echo $filtros['estado'] === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Motivo -->
                <div>
                    <label class="<?php echo CSSComponents::getLabelClasses(); ?>">Motivo</label>
                    <select name="motivo" class="<?php echo CSSComponents::getSelectClasses(); ?>">
                        <option value="">Todos los motivos</option>
                        <?php foreach ($motivos_options as $key => $label): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>" 
                                    <?php echo $filtros['motivo'] === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                <button type="submit" class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?>">
                    <i class="fas fa-filter mr-2"></i>
                    Aplicar Filtros
                </button>
                 <a href="vacaciones.php?estado=" class="<?php echo CSSComponents::getButtonClasses('outline', 'md'); ?>">
                    <i class="fas fa-times mr-2"></i>
                    Limpiar Filtros
                </a>
                <button type="button" onclick="exportarVacaciones()" class="<?php echo CSSComponents::getButtonClasses('secondary', 'md'); ?>">
                    <i class="fas fa-file-excel mr-2"></i>
                    Exportar a Excel
                </button>
            </div>
        </form>
    </div>

    <!-- Vacaciones Table -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> overflow-hidden">
        <div class="overflow-x-auto">
            <table class="<?php echo CSSComponents::getTableClasses(); ?>">
                <thead class="<?php echo CSSComponents::getTableHeaderClasses(); ?>">
                    <tr>
                        <?php if (strtolower($rol_trabajador) !== 'empleado'): ?>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?>">Empleado</th>
                        <?php endif; ?>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?>">Fecha Inicio</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?>">Fecha Fin</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?>">Días</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?>">Motivo</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?>">Estado</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?>">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($vacaciones_list)): ?>
                        <tr>
                            <td colspan="<?php echo strtolower($rol_trabajador) === 'empleado' ? '6' : '7'; ?>" class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center text-gray-500 py-8">
                                <i class="fas fa-calendar-times text-gray-300 text-3xl mb-3"></i>
                                <p>No hay vacaciones registradas</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vacaciones_list as $vacacion): ?>
                            <tr class="hover:bg-gray-50">
                                <?php if (strtolower($rol_trabajador) !== 'empleado'): ?>
                                <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                    <div class="flex items-center">
                                        <div class="h-8 w-8 rounded-full bg-primary-500 flex items-center justify-center text-white text-sm font-medium mr-3">
                                            <?php echo strtoupper(substr($vacacion['nombre_completo'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($vacacion['nombre_completo']); ?>
                                            </div>
                                            <?php if (!empty($vacacion['centro_nombre'])): ?>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($vacacion['centro_nombre']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <?php endif; ?>
                                <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                    <?php echo !empty($vacacion['fecha_inicio']) ? date('d/m/Y', strtotime($vacacion['fecha_inicio'])) : '-'; ?>
                                </td>
                                <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                    <?php echo !empty($vacacion['fecha_fin']) ? date('d/m/Y', strtotime($vacacion['fecha_fin'])) : '-'; ?>
                                </td>
                                <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                    <span class="text-sm font-medium">
                                        <?php echo $vacacion['dias_solicitados']; ?> días
                                    </span>
                                </td>
                                <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                    <span class="text-sm text-gray-600">
                                        <?php echo htmlspecialchars($motivos_options[$vacacion['motivo']] ?? $vacacion['motivo']); ?>
                                    </span>
                                </td>
                                <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                    <?php
                                        $estadoInfo = $estados_options[$vacacion['estado']] ?? $vacacion['estado'];
                                        
                                        $badgeVariant = 'default';
                                        switch ($vacacion['estado']) {
                                            case 'aprobada':
                                                $badgeVariant = 'success';
                                                break;
                                            case 'rechazada':
                                                $badgeVariant = 'error';
                                                break;
                                            case 'cancelada':
                                                $badgeVariant = 'error';
                                                break;
                                            case 'pendiente':
                                                $badgeVariant = 'warning';
                                                break;
                                            default:
                                                $badgeVariant = 'default';
                                        }
                                    ?>
                                    <span class="<?php echo CSSComponents::getBadgeClasses($badgeVariant, 'sm'); ?>">
                                        <?php echo htmlspecialchars($estadoInfo); ?>
                                    </span>
                                </td>
                                <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                    <div class="flex items-center space-x-2">
                                        <a href="vacaciones-edit.php?id=<?php echo $vacacion['id']; ?>" 
                                           class="<?php echo CSSComponents::getButtonClasses('outline', 'sm'); ?>" 
                                           title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button onclick="eliminarVacacion(<?php echo $vacacion['id']; ?>)" 
                                                class="<?php echo CSSComponents::getButtonClasses('danger', 'sm'); ?>" 
                                                title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Footer con estadísticas -->
    <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-4 text-center">
            <div class="text-2xl font-bold text-blue-600"><?php echo $total_vacaciones; ?></div>
            <div class="text-sm text-gray-600">Total Vacaciones</div>
        </div>
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-4 text-center">
            <div class="text-2xl font-bold text-yellow-600"><?php echo $pendientes; ?></div>
            <div class="text-sm text-gray-600">Pendientes</div>
        </div>
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-4 text-center">
            <div class="text-2xl font-bold text-green-600"><?php echo $aprobadas; ?></div>
            <div class="text-sm text-gray-600">Aprobadas</div>
        </div>
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-4 text-center">
            <div class="text-2xl font-bold text-red-600"><?php echo $rechazadas; ?></div>
            <div class="text-sm text-gray-600">Rechazadas</div>
        </div>
    </div>
    
    <script>
        function eliminarVacacion(id) {
            if (confirm('¿Estás seguro de que quieres eliminar esta solicitud de vacaciones?')) {
                fetch('ajax/delete_vacacion.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: id })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error al eliminar: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al eliminar la solicitud');
                });
            }
        }

        function exportarVacaciones() {
            // Obtener parámetros actuales de la URL
            const urlParams = new URLSearchParams(window.location.search);

            // Construir URL de exportación
            const exportUrl = 'generar_vacaciones_export.php?' + urlParams.toString();

            // Abrir en nueva ventana/pestaña para descarga
            window.open(exportUrl, '_blank');
        }
    </script>

    <?php echo MultiSelect::renderScript(); ?>
    
    <?php
    return ob_get_clean();
}

// Capturar el HTML generado mediante output buffering e invocarlo con los datos preparados.
try {
    $content = renderVacacionesContent($vacaciones_list, $filtros, $empleados_options, $centros_options, $motivos_options, $estados_options, $total_vacaciones, $pendientes, $aprobadas, $rechazadas, $rol_trabajador, $config_empresa);

    // Invocar el layout base para construir y enviar la respuesta HTML completa al cliente.
    $page_title = strtolower($rol_trabajador) === 'empleado' ? 'Mis Vacaciones' : 'Vacaciones / Ausencias';
    BaseLayout::render($page_title, $content, $config_empresa, $user_data);
} catch (Exception $e) {
    error_log("Error rendering vacaciones page: " . $e->getMessage());
    echo "Error loading page. Please check the logs.";
}
?> 