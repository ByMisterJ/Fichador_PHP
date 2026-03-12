<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir las clases necesarias
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/models/Fichajes.php';
require_once __DIR__ . '/../shared/models/Vacaciones.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/StatusIndicator.php';
require_once __DIR__ . '/../assets/css/components.php';

// Verificar si el trabajador está logueado
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit();
}

// Obtener datos del trabajador de la sesión
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$trabajador_login = $_SESSION['trabajador_login'] ?? 'N/A';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
$centro_trabajador = $_SESSION['centro_trabajador'] ?? 'N/A';
$grupo_horario_trabajador = $_SESSION['grupo_horario_trabajador'] ?? 'N/A';
$tiempo_login = $_SESSION['tiempo_login'] ?? date('Y-m-d H:i:s');
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

// Obtener configuración de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Obtener estado real de fichaje desde la base de datos
$fichajes = new Fichajes();
$estado_fichaje = $fichajes->getEstadoActual($trabajador_id, $empresa_id);
$horas_hoy = $fichajes->calcularHorasTrabajadas($trabajador_id, $empresa_id, date('Y-m-d'));

$ultimo_fichaje = $estado_fichaje['ultimo_fichaje'];
$estado_actual = $estado_fichaje['estado_actual'];
$es_entrada = $estado_fichaje['es_entrada'];
$tiene_sesion_activa = $estado_fichaje['tiene_sesion_activa'];
$horas_generadas = $horas_hoy['total_formateado'];

// Obtener datos para el gráfico (solo para admin/supervisor)
$datos_grafico = [];
$empleados_por_estado_sesion = [];
$vacaciones_pendientes_count = 0;
$incidencias_por_empleado = [];
$total_incidencias = 0;
if (strtolower($rol_trabajador) !== 'empleado') {
    // Determinar centro_id para supervisores
    $centro_id_supervisor = null;
    if (strtolower($rol_trabajador) === 'supervisor') {
        $trabajador_model = new Trabajador();
        $centro_id_supervisor = $trabajador_model->obtenerCentroIdTrabajador($trabajador_id);

    }
    $vacaciones = new Vacaciones();
    $vacaciones_pendientes_count = $vacaciones->contarVacacionesPendientes($empresa_id, $centro_id_supervisor);
    $datos_grafico = $fichajes->obtenerHorasSemanaTrabajo($empresa_id, $centro_id_supervisor);
    $empleados_por_estado_sesion = $fichajes->contarEmpleadosPorEstadoSesion();

    // Obtener incidencias por empleado (última semana)
    $incidencias_por_empleado = $fichajes->obtenerConteoIncidenciasPorEmpleado($empresa_id, $centro_id_supervisor);

    // Calcular total de incidencias
    $total_incidencias = array_sum(array_column($incidencias_por_empleado, 'total_incidencias'));
}

// Preparar datos de usuario para el layout
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Determinar el título de la página
$page_title = strtolower($rol_trabajador) === 'empleado' ? 'Fichar' : 'Dashboard';

// Función para renderizar el contenido del dashboard
function renderDashboardContent($rol_trabajador, $estado_fichaje, $horas_hoy, $fichajes, $trabajador_id, $empresa_id, $config_empresa, $datos_grafico = [], $vacaciones_pendientes_count = 0, $empleados_por_estado_sesion = [], $incidencias_por_empleado = [], $total_incidencias = 0)
{
    ob_start();
    ?>
	<?php
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$host = preg_replace('/:\d+$/', '', strtolower(trim($host)));
$parts = explode('.', $host);
$subdomain = count($parts) >= 3 ? $parts[0] : (count($parts) === 2 ? '' : $parts[0]);

if (($config_empresa["empleados_ver_fichajes"] == 0) && (strtolower($rol_trabajador) === 'empleado')) {
    // Incluye un archivo HTML (asegúrate de la ruta)
    echo "<style>
	.text-center.mb-6.p-4.rounded-xl.shadow-sm.border.bg-green-50.border-green-200 {  display: none;  }
	.rounded-xl.shadow-sm.border.bg-white.border-gray-200.p-6 {  display: none; }
	</style>";
}
	?>
    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    <?php echo strtolower($rol_trabajador) === 'empleado' ? 'Fichar' : 'Dashboard'; ?>
                </h1>
                <p class="text-gray-600">Bienvenido,
                    <?php echo htmlspecialchars($_SESSION['nombre_trabajador'] ?? 'Trabajador'); ?></p>
            </div>
            <div class="text-right">
                <div id="current-time" class="text-2xl font-bold text-gray-900"></div>
                <div class="text-gray-600"><?php echo date('d/m/Y'); ?></div>
            </div>
        </div>
    </div>

    <?php if (strtolower($rol_trabajador) === 'empleado'): ?>
        <!-- Employee View - Time Tracking -->
        <div class="max-w-2xl mx-auto">
            <!-- Current Status Card -->
            <div>
                <?php if ($estado_fichaje['tiene_sesion_activa']): ?>
                    <!-- Session Timer - Prominente en la parte superior -->
                    <div class="text-center mb-6 p-4 <?php echo CSSComponents::getCardClasses('primary'); ?>">
                        <div class="text-sm text-red-600 font-medium mb-2">TIEMPO DE SESIÓN ACTIVA</div>
                        <div id="session-timer" class="text-5xl font-bold text-red-600 tracking-wider"
                            data-start-time="<?php echo date('c', strtotime($estado_fichaje['fecha_inicio_sesion'])); ?>">
                            <?php echo $fichajes->formatearTiempo($estado_fichaje['tiempo_sesion']); ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Total Time Today - Cuando no hay sesión activa -->
                    <?php $colorBotonFichar = $config_empresa['color_boton_fichar'] ?? '#43a047'; ?>
                    <div class="text-center mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
                        <div class="text-sm font-medium mb-2" style="color: <?php echo htmlspecialchars($colorBotonFichar); ?>;">TIEMPO TOTAL TRABAJADO HOY</div>
                        <div id="total-time-display" class="text-4xl font-bold tracking-wider" style="color: <?php echo htmlspecialchars($colorBotonFichar); ?>;">
                            <?php echo $horas_hoy['total_formateado']; ?>
                        </div>
                        <?php if (isset($horas_hoy['numero_sesiones']) && $horas_hoy['numero_sesiones'] > 0): ?>
                            <div class="text-sm text-green-500 mt-2">
                                <?php echo $horas_hoy['numero_sesiones']; ?>
                                sesión<?php echo $horas_hoy['numero_sesiones'] > 1 ? 'es' : ''; ?>
                                completada<?php echo $horas_hoy['numero_sesiones'] > 1 ? 's' : ''; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
			
			
			
			<!-- Cliente fichaje -->
			<div class="<?php echo CSSComponents::getFieldWrapperClasses(); ?> md:col-span-2">
				<textarea 
						  id="anotacion_trabajador" 
						  name="anotacion_trabajador"
						  rows="3"
						  class="<?php echo CSSComponents::getTextareaClasses(isset($errors['anotacion_trabajador']) ? 'error' : ''); ?>"
						  placeholder="Anotación del trabajador"
						  ></textarea>
				<?php if (isset($errors['anotacion_trabajador'])): ?>
				<p class="<?php echo CSSComponents::getErrorTextClasses(); ?>">
					<?php echo htmlspecialchars($errors['anotacion_trabajador']); ?>
				</p>
				<?php endif; ?>
			</div>

			
			
			<!-- Main Clock-in Button -->
            <div class="mb-8">
                <?php
                $buttonVariant = $estado_fichaje['es_entrada'] ? 'success' : 'danger';
                $buttonText = $estado_fichaje['es_entrada'] ? 'Entrar' : 'Salir';
                $buttonIcon = $estado_fichaje['es_entrada'] ? 'sign-in-alt' : 'sign-out-alt';
                $colorBotonFichar = $config_empresa['color_boton_fichar'] ?? '#43a047';
                ?>
                <button id="clock-button"
                    class="w-full <?php echo CSSComponents::getButtonClasses($buttonVariant, 'xl'); ?> transform hover:scale-105 shadow-lg"
                    <?php if ($estado_fichaje['es_entrada']): ?>
                        style="background-color: <?php echo htmlspecialchars($colorBotonFichar); ?>; border-color: <?php echo htmlspecialchars($colorBotonFichar); ?>;"
                    <?php endif; ?>>
                    <i class="fas fa-<?php echo $buttonIcon; ?> mr-3"></i>
                    <?php echo $buttonText; ?>
                </button>
            </div>

            <!-- Daily Time Tracking Details -->
            <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6">
                <div class="flex items-center mb-4">
                    <i class="fas fa-calendar-day text-primary-dynamic text-xl mr-3"></i>
                    <h2 class="text-lg font-semibold text-gray-900">Sesiones del día</h2>
                </div>

                <div id="fichajes-del-dia">
                    <?php
                    $fichajes_hoy = $fichajes->getFichajesHoy($trabajador_id, $empresa_id);

                    // Agrupar fichajes en sesiones
                    $sesiones = [];
                    foreach ($fichajes_hoy as $fichaje) {
                        if ($fichaje['tipo'] === 'entrada') {
                            $sesiones[] = [
                                'entrada' => $fichaje,
                                'salida' => null,
                                'duracion_segundos' => 0,
                                'estado' => 'abierta'
                            ];
                        } elseif ($fichaje['tipo'] === 'salida' && !empty($sesiones)) {
                            $ultima_sesion = &$sesiones[count($sesiones) - 1];
                            if ($ultima_sesion['salida'] === null) {
                                $ultima_sesion['salida'] = $fichaje;
                                $ultima_sesion['estado'] = 'cerrada';
                                // Calcular duración
                                $entrada_time = strtotime($ultima_sesion['entrada']['fecha_hora']);
                                $salida_time = strtotime($fichaje['fecha_hora']);
                                $ultima_sesion['duracion_segundos'] = $salida_time - $entrada_time;
                            }
                        }
                    }

                    if (empty($sesiones)):
                        ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-clock text-4xl mb-4 opacity-50"></i>
                            <p>No hay sesiones registradas para hoy.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($sesiones as $index => $sesion):
                                $es_sesion_cerrada = $sesion['estado'] === 'cerrada';
                                $entrada = $sesion['entrada'];
                                $salida = $sesion['salida'];
                                $fichaje_id = $entrada['id'];
                                $anotacion = $entrada['anotacion_trabajador'] ?? '';

                                // Calcular duración
                                $duracion_texto = '--:--';
                                if ($es_sesion_cerrada && $sesion['duracion_segundos'] > 0) {
                                    $horas = floor($sesion['duracion_segundos'] / 3600);
                                    $minutos = floor(($sesion['duracion_segundos'] % 3600) / 60);
                                    $duracion_texto = sprintf('%02d:%02d', $horas, $minutos);
                                }

                                $card_bg = $es_sesion_cerrada ? 'bg-white' : 'bg-blue-50';
                                $border_color = $es_sesion_cerrada ? 'border-gray-200' : 'border-blue-200';
                            ?>
                                <div class="<?php echo $card_bg; ?> border <?php echo $border_color; ?> rounded-lg p-4">
                                    <!-- Encabezado de sesión -->
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center mr-2">
                                                <span class="text-xs font-bold text-gray-700"><?php echo $index + 1; ?></span>
                                            </div>
                                            <h3 class="font-semibold text-gray-900">Sesión <?php echo $index + 1; ?></h3>
                                        </div>
                                        <?php if (!$es_sesion_cerrada): ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <i class="fas fa-circle text-blue-500 text-[6px] mr-1.5 animate-pulse"></i>
                                                Activa
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Horarios de entrada/salida -->
                                    <div class="grid grid-cols-2 gap-3 mb-3">
                                        <!-- Entrada -->
                                        <div class="flex items-start">
                                            <div class="w-7 h-7 bg-green-100 text-green-600 rounded-full flex items-center justify-center mr-2 flex-shrink-0">
                                                <i class="fas fa-sign-in-alt text-xs"></i>
                                            </div>
                                            <div>
                                                <div class="text-xs text-gray-500">Entrada</div>
                                                <div class="text-sm font-semibold text-gray-900">
                                                    <?php echo date('H:i:s', strtotime($entrada['fecha_hora'])); ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Salida -->
                                        <div class="flex items-start">
                                            <div class="w-7 h-7 <?php echo $es_sesion_cerrada ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-400'; ?> rounded-full flex items-center justify-center mr-2 flex-shrink-0">
                                                <i class="fas fa-sign-out-alt text-xs"></i>
                                            </div>
                                            <div>
                                                <div class="text-xs text-gray-500">Salida</div>
                                                <div class="text-sm font-semibold <?php echo $es_sesion_cerrada ? 'text-gray-900' : 'text-gray-400'; ?>">
                                                    <?php echo $salida ? date('H:i:s', strtotime($salida['fecha_hora'])) : '--:--:--'; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Duración -->
                                    <?php if ($es_sesion_cerrada): ?>
                                        <div class="flex items-center mb-3 pb-3 border-b border-gray-200">
                                            <i class="fas fa-clock text-gray-400 text-sm mr-2"></i>
                                            <span class="text-sm text-gray-600">Duración:</span>
                                            <span class="text-sm font-semibold text-gray-900 ml-2"><?php echo $duracion_texto; ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Anotación (solo editable si sesión cerrada) -->
                                    <div class="mt-3">
                                        <label class="block text-xs font-medium text-gray-700 mb-1">
                                            <i class="fas fa-sticky-note text-gray-400 mr-1"></i>
                                            Anotación
                                        </label>

                                        <?php if ($es_sesion_cerrada): ?>
                                            <!-- Anotación editable -->
                                            <div class="relative" data-anotacion-container="<?php echo $fichaje_id; ?>">
                                                <textarea
                                                    id="anotacion-<?php echo $fichaje_id; ?>"
                                                    data-fichaje-id="<?php echo $fichaje_id; ?>"
                                                    data-valor-original="<?php echo htmlspecialchars($anotacion, ENT_QUOTES); ?>"
                                                    disabled
                                                    class="w-full text-sm text-gray-800 bg-white border border-gray-200 rounded-lg p-3 resize-none focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                                    rows="2"
                                                    placeholder="Sin anotación"
                                                ><?php echo htmlspecialchars($anotacion); ?></textarea>

                                                <!-- Botones de acción (ocultos inicialmente) -->
                                                <div id="anotacion-actions-<?php echo $fichaje_id; ?>" class="hidden mt-2 flex gap-2 justify-end">
                                                    <button
                                                        onclick="cancelarEdicionAnotacion(<?php echo $fichaje_id; ?>)"
                                                        class="text-xs px-3 py-1.5 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors flex items-center gap-1">
                                                        <i class="fas fa-times"></i>Cancelar
                                                    </button>
                                                    <button
                                                        onclick="guardarAnotacion(<?php echo $fichaje_id; ?>)"
                                                        class="text-xs px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-1">
                                                        <i class="fas fa-check"></i>Guardar
                                                    </button>
                                                </div>

                                                <!-- Botón de editar (visible inicialmente) -->
                                                <button
                                                    id="btn-edit-anotacion-<?php echo $fichaje_id; ?>"
                                                    onclick="editarAnotacion(<?php echo $fichaje_id; ?>)"
                                                    class="absolute top-2 right-2 inline-flex items-center justify-center w-7 h-7 text-blue-600 hover:text-blue-800 hover:bg-blue-100 rounded-lg transition-colors duration-200"
                                                    title="Editar anotación">
                                                    <i class="fas fa-pen text-xs"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <!-- Anotación no editable (sesión activa) -->
                                            <div class="text-sm text-gray-500 bg-gray-50 border border-gray-200 rounded-lg p-3 italic">
                                                <?php echo !empty($anotacion) ? htmlspecialchars($anotacion) : 'Podrás editar la anotación cuando finalices la sesión'; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php else: ?>

		<!-- Especial cabecera Dashboard -->
<?php require_once __DIR__ . '/../improvements/add_cabecera_dashboard.php'; ?>


        <!-- Admin/Supervisor View - Dashboard -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Stats Cards -->
            <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-users text-green-600 text-xl"></i>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-gray-900"><?php echo $empleados_por_estado_sesion['abiertos']; ?>
                        </div>
                        <div class="text-sm text-gray-500">DENTRO</div>
                    </div>
                </div>
            </div>

            <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-user-times text-red-600 text-xl"></i>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-gray-900"><?php echo $empleados_por_estado_sesion['cerrados']; ?>
                        </div>
                        <div class="text-sm text-gray-500">FUERA</div>
                    </div>
                </div>
            </div>

            <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-gray-900"><?php echo $total_incidencias; ?></div>
                        <div class="text-sm text-gray-500">INCIDENCIAS</div>
                    </div>
                </div>
            </div>

            <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-calendar-check text-blue-600 text-xl"></i>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-gray-900"><?php echo $vacaciones_pendientes_count; ?></div>
                        <div class="text-sm text-gray-500">VACACIONES</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Additional Content -->
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Horas trabajadas por día (total empleados)</h3>
                <?php if (!empty($datos_grafico['fecha_inicio']) && !empty($datos_grafico['fecha_fin'])): ?>
                    <div class="text-sm text-gray-500">
                        <?php echo date('d/m/Y', strtotime($datos_grafico['fecha_inicio'])); ?> -
                        <?php echo date('d/m/Y', strtotime($datos_grafico['fecha_fin'])); ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($datos_grafico['horas_decimales'])): ?>
                <!-- Chart Container -->
                <div class="h-64 relative">
                    <canvas id="hoursChart" width="400" height="200"></canvas>
                </div>

                <!-- Chart Summary -->
                <div class="mt-4 grid grid-cols-7 gap-2 text-center">
                    <?php foreach ($datos_grafico['labels'] as $index => $dia): ?>
                        <div class="p-2 bg-gray-50 rounded">
                            <div class="text-xs font-medium text-gray-600"><?php echo mb_substr($dia, 0, 3, 'UTF-8'); ?></div>
                            <div class="text-sm font-bold text-gray-900"><?php echo $datos_grafico['horas_formateadas'][$index]; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <!-- No Data State -->
                <div class="h-64 flex items-center justify-center text-gray-500">
                    <div class="text-center">
                        <i class="fas fa-chart-bar text-4xl mb-4 opacity-50"></i>
                        <p>No hay datos de fichajes para esta semana</p>
                        <p class="text-sm">Los datos aparecerán cuando los empleados registren fichajes</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Incidencias por Empleado -->
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6 mt-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-exclamation-triangle text-yellow-600 mr-2"></i>
                    Incidencias por empleado (última semana)
                </h3>
                <div class="text-sm text-gray-500">
                    <?php echo date('d/m/Y', strtotime('-7 days')); ?> - <?php echo date('d/m/Y'); ?>
                </div>
            </div>

            <?php if (!empty($incidencias_por_empleado)): ?>
                <div class="overflow-x-auto max-h-96">
                    <table class="<?php echo CSSComponents::getTableClasses(); ?>">
                        <thead class="<?php echo CSSComponents::getTableHeaderClasses(); ?>">
                            <tr>
                                <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">
                                    Empleado
                                </th>
                                <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">
                                    DNI
                                </th>
                                <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">
                                    Total Incidencias
                                </th>
                                <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">
                                    Acciones
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($incidencias_por_empleado as $empleado): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-8 w-8">
                                                <div class="h-8 w-8 rounded-full bg-gray-300 flex items-center justify-center">
                                                    <span class="text-xs font-medium text-gray-700">
                                                        <?php echo strtoupper(substr($empleado['nombre_completo'], 0, 2)); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="ml-3">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($empleado['nombre_completo']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                        <span class="text-sm font-mono text-gray-900">
                                            <?php echo htmlspecialchars($empleado['dni']); ?>
                                        </span>
                                    </td>
                                    <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">
                                        <?php if ($empleado['total_incidencias'] > 0): ?>
                                            <span
                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <?php echo $empleado['total_incidencias']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span
                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                0
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">
                                        <?php if ($empleado['total_incidencias'] > 0): ?>
                                            <a href="fichajes.php?solo_incidencias=1&trabajadores[]=<?php echo $empleado['trabajador_id']; ?>&fecha_desde=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&fecha_hasta=<?php echo date('Y-m-d'); ?>"
                                                class="inline-flex items-center px-3 py-1 border border-transparent text-xs font-medium rounded-md text-yellow-700 bg-yellow-100 hover:bg-yellow-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition-colors duration-200"
                                                title="Ver detalles de incidencias">
                                                <i class="fas fa-eye mr-1"></i>
                                                Ver detalles
                                            </a>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400">Sin incidencias</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Resumen de la tabla -->
                <div class="mt-4 flex items-center justify-between text-sm text-gray-600 border-t border-gray-200 pt-4">
                    <div>
                        Mostrando <?php echo count($incidencias_por_empleado); ?> empleado(s)
                    </div>
                    <div class="font-medium">
                        Total de incidencias: <?php echo $total_incidencias; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Estado sin datos -->
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-check-circle text-4xl mb-4 text-green-400"></i>
                    <h4 class="text-lg font-medium text-gray-900 mb-2">¡Excelente!</h4>
                    <p>No hay incidencias registradas en la última semana.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Bottom Action Bar (Mobile) -->
    <div class="p-4 mt-6 rounded-lg flex justify-center">
        <button id="add-to-home-screen"
            class="w-full max-w-2xl flex items-center justify-center text-lg font-medium hover:bg-gray-700 rounded-lg transition-colors bg-gray-800 text-white p-4"
            style="display: none;">
            <i class="fas fa-plus mr-2"></i>
            AÑADIR A LA PANTALLA DE INICIO
        </button>
    </div>

    <!-- Spacer for bottom bar on mobile -->
    <div class="h-20 lg:hidden"></div>

    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // Current time display
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('es-ES', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('current-time').textContent = timeString;
        }

        // Update time every second
        updateTime();
        setInterval(updateTime, 1000);

        // Variables globales para fichaje
        let currentLocation = null;
        let sessionTimer = null;

        // Obtener geolocalización
        function getCurrentLocation() {
            return new Promise((resolve) => {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            resolve({
                                lat: position.coords.latitude,
                                lng: position.coords.longitude
                            });
                        },
                        (error) => {
                            console.log('Error obteniendo ubicación:', error);
                            resolve(null);
                        },
                        {
                            enableHighAccuracy: false,
                            timeout: 1000,
                            maximumAge: Infinity
                        }
                    );
                } else {
                    resolve(null);
                }
            });
        }

        // Timer de sesión
        function startSessionTimer() {
            const timerElement = document.getElementById('session-timer');
            if (!timerElement) {
                console.log('Timer element not found');
                return;
            }

            const startTime = timerElement.getAttribute('data-start-time');
            if (!startTime) {
                console.log('Start time not found');
                return;
            }

            console.log('Starting timer with start time:', startTime);

            // Clear any existing timer
            if (sessionTimer) {
                clearInterval(sessionTimer);
            }

            sessionTimer = setInterval(() => {
                // Parse the server time (which is already in Madrid timezone)
                const start = new Date(startTime);

                // Check if date is valid
                if (isNaN(start.getTime())) {
                    console.error('Invalid start time format:', startTime);
                    return;
                }

                // Create a "now" time in Madrid timezone
                const now = new Date();
                const madridTime = new Date(now.toLocaleString("es-ES", { timeZone: "Europe/Madrid" }));

                const diff = Math.floor((now - start) / 1000);

                const hours = Math.floor(diff / 3600);
                const minutes = Math.floor((diff % 3600) / 60);
                const seconds = diff % 60;

                const timeString = String(hours).padStart(2, '0') + ':' +
                    String(minutes).padStart(2, '0') + ':' +
                    String(seconds).padStart(2, '0');

                timerElement.textContent = timeString;
            }, 1000);
        }

        // Detener timer de sesión
        function stopSessionTimer() {
            if (sessionTimer) {
                clearInterval(sessionTimer);
                sessionTimer = null;
            }
        }

        // Clock in/out functionality (only for employees)
        const clockButton = document.getElementById('clock-button');
        if (clockButton) {
            let isClockIn = <?php echo json_encode($estado_fichaje['es_entrada']); ?>;

            clockButton.addEventListener('click', async function () {
                // Add loading state
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin mr-3"></i>Procesando...';

                try {

                    <?php if ($config_empresa["localizacion"] == 1): ?>
                        // Obtener ubicación
                        currentLocation = await getCurrentLocation();
                    <?php else: ?>
                        // Location disabled - set to null
                        currentLocation = null;
                    <?php endif; ?>

                    // Determinar tipo de fichaje
                    const tipo = isClockIn ? 'entrada' : 'salida';
					// Obtener el valor del textarea
					const anotacion_trabajador = document.getElementById('anotacion_trabajador').value;

                    // Enviar petición AJAX
                    const response = await fetch('ajax/fichaje.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            tipo: tipo,
                            ubicacion: currentLocation,
							anotacion_trabajador: anotacion_trabajador
                        })
                    });

                    // Check if session expired (401 Unauthorized)
                    if (response.status === 401) {
                        showNotification('Tu sesión ha expirado. Redirigiendo al login...', 'error');
                        setTimeout(() => {
                            window.location.href = 'login.php';
                        }, 2000);
                        return;
                    }

                    const result = await response.json();

                    if (result.success) {
                        // Mostrar notificación de éxito
                        showNotification(result.message);

                        // Verificar si debe salir de la app después del fichaje
                        if (<?php echo $config_empresa["salir_app_fichar"] ? 'true' : 'false'; ?>) {
                            // Redirigir al logout
                            setTimeout(() => {
                                window.location.href = 'logout.php';
                            }, 1000);
                        } else {
                            // Recargar página para mostrar datos actualizados después de un breve delay
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        }

                    } else {
                        showNotification(result.message, 'error');
                    }

                } catch (error) {
                    console.error('Error:', error);
                    showNotification('Error de conexión', 'error');
                }

                this.disabled = false;
            });
        }

        // Actualizar interfaz de usuario
        function updateUI(estado, horasHoy) {
            const clockButton = document.getElementById('clock-button');

            // Actualizar botón usando las clases de componentes
            const colorBotonFichar = '<?php echo htmlspecialchars($config_empresa['color_boton_fichar'] ?? '#43a047'); ?>';
            
            if (estado.es_entrada) {
                clockButton.innerHTML = '<i class="fas fa-sign-in-alt mr-3"></i>Entrar';
                clockButton.className = 'w-full <?php echo CSSComponents::getButtonClasses('success', 'xl'); ?> transform hover:scale-105 shadow-lg';
                clockButton.style.backgroundColor = colorBotonFichar;
                clockButton.style.borderColor = colorBotonFichar;
            } else {
                clockButton.innerHTML = '<i class="fas fa-sign-out-alt mr-3"></i>Salir';
                clockButton.className = 'w-full <?php echo CSSComponents::getButtonClasses('danger', 'xl'); ?> transform hover:scale-105 shadow-lg';
                clockButton.style.backgroundColor = '';
                clockButton.style.borderColor = '';
            }

            // Status and hours elements removed

            // Manejar timer de sesión y tiempo total
            if (estado.tiene_sesion_activa) {
                // Remover display de tiempo total si existe
                const totalTimeDisplay = document.getElementById('total-time-display');
                if (totalTimeDisplay) {
                    totalTimeDisplay.closest('.text-center.mb-6').remove();
                }

                // Crear o actualizar timer de sesión activa
                let timerContainer = document.getElementById('session-timer');
                if (!timerContainer) {
                    // Crear elemento de timer prominente si no existe
                    const statusCard = document.querySelector('.<?php echo str_replace(' ', '.', CSSComponents::getCardClasses('default')); ?>');
                    const timerDiv = document.createElement('div');
                    timerDiv.className = 'text-center mb-6 p-4 <?php echo CSSComponents::getCardClasses('primary'); ?>';
                    timerDiv.innerHTML = `
                        <div class="text-sm text-red-600 font-medium mb-2">TIEMPO DE SESIÓN ACTIVA</div>
                        <div id="session-timer" class="text-5xl font-bold text-red-600 tracking-wider" data-start-time="${estado.fecha_inicio_sesion}">
                            ${horasHoy.sesion_activa ? horasHoy.sesion_activa.duracion_formateada : '00:00:00'}
                        </div>
                    `;
                    // Insertar al principio del contenido de la tarjeta, después del padding
                    const firstChild = statusCard.firstElementChild;
                    statusCard.insertBefore(timerDiv, firstChild);
                    timerContainer = document.getElementById('session-timer');
                }

                timerContainer.setAttribute('data-start-time', estado.fecha_inicio_sesion);
                startSessionTimer();
            } else {
                // Remover timer de sesión si existe
                stopSessionTimer();
                const timerElement = document.getElementById('session-timer');
                if (timerElement) {
                    timerElement.closest('.text-center.mb-6').remove();
                }

                // Crear o actualizar display de tiempo total trabajado
                let totalTimeContainer = document.getElementById('total-time-display');
                if (!totalTimeContainer) {
                    const statusCard = document.querySelector('.<?php echo str_replace(' ', '.', CSSComponents::getCardClasses('default')); ?>');
                    const totalTimeDiv = document.createElement('div');
                    totalTimeDiv.className = 'text-center mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>';
                    totalTimeDiv.innerHTML = `
                        <div class="text-sm font-medium mb-2" style="color: ${colorBotonFichar};">TIEMPO TOTAL TRABAJADO HOY</div>
                        <div id="total-time-display" class="text-4xl font-bold tracking-wider" style="color: ${colorBotonFichar};">
                            ${horasHoy.total_formateado}
                        </div>
                        ${(horasHoy.numero_sesiones && horasHoy.numero_sesiones > 0) ? `
                        <div class="text-sm mt-2" style="color: ${colorBotonFichar};">
                            ${horasHoy.numero_sesiones} sesión${horasHoy.numero_sesiones > 1 ? 'es' : ''} completada${horasHoy.numero_sesiones > 1 ? 's' : ''}
                        </div>
                        ` : ''}
                    `;
                    // Insertar al principio del contenido de la tarjeta
                    const firstChild = statusCard.firstElementChild;
                    statusCard.insertBefore(totalTimeDiv, firstChild);
                } else {
                    // Actualizar tiempo total existente
                    totalTimeContainer.textContent = horasHoy.total_formateado;
                }
            }
        }

        // Cargar fichajes del día - removed since we reload the page now

        // Actualizar display de fichajes
        function updateFichajesDisplay(fichajes) {
            const container = document.getElementById('fichajes-del-dia');

            if (fichajes.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-clock text-4xl mb-4 opacity-50"></i>
                        <p>No hay fichajes registrados para hoy.</p>
                    </div>
                `;
            } else {
                let html = '<div class="space-y-3">';
                fichajes.forEach(fichaje => {
                    const isEntrada = fichaje.tipo === 'entrada';
                    const bgColor = isEntrada ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600';
                    const icon = isEntrada ? 'sign-in-alt' : 'sign-out-alt';
                    const time = new Date(fichaje.fecha_hora).toLocaleTimeString('es-ES');

                    html += `
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <div class="w-8 h-8 ${bgColor} rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-${icon} text-sm"></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900">${fichaje.tipo.charAt(0).toUpperCase() + fichaje.tipo.slice(1)}</div>
                                    <div class="text-sm text-gray-500">${time}</div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm text-gray-500">${fichaje.metodo.charAt(0).toUpperCase() + fichaje.metodo.slice(1)}</div>
                                ${fichaje.ubicacion ? '<div class="text-xs text-gray-400"><i class="fas fa-map-marker-alt mr-1"></i>Ubicación</div>' : ''}
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                container.innerHTML = html;
            }
        }

        // Inicializar timer si hay sesión activa
        document.addEventListener('DOMContentLoaded', function () {
            console.log('DOM loaded');
            <?php if ($estado_fichaje['tiene_sesion_activa']): ?>
                console.log('Session is active, starting timer');
                startSessionTimer();
            <?php else: ?>
                console.log('No active session');
            <?php endif; ?>

            // Inicializar gráfico de horas
            initializeHoursChart();
        });

        // Inicializar gráfico de horas trabajadas
        function initializeHoursChart() {
            const chartCanvas = document.getElementById('hoursChart');
            if (!chartCanvas) {
                console.log('Chart canvas not found');
                return;
            }

            <?php if (!empty($datos_grafico['horas_decimales'])): ?>
                const chartData = {
                    labels: <?php echo json_encode($datos_grafico['labels']); ?>,
                    datasets: [{
                        label: 'Horas trabajadas',
                        data: <?php echo json_encode($datos_grafico['horas_decimales']); ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.8)', // Blue color
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                };

                const chartConfig = {
                    type: 'bar',
                    data: chartData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (context) {
                                        const formattedHours = <?php echo json_encode($datos_grafico['horas_formateadas']); ?>;
                                        return `Horas trabajadas: ${formattedHours[context.dataIndex]}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                },
                                ticks: {
                                    callback: function (value) {
                                        const hours = Math.floor(value);
                                        const minutes = Math.round((value - hours) * 60);
                                        return hours + 'h' + (minutes > 0 ? ' ' + minutes + 'm' : '');
                                    }
                                },
                                title: {
                                    display: true,
                                    text: 'Horas'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        },
                        animation: {
                            duration: 1000,
                            easing: 'easeInOutQuart'
                        }
                    }
                };

                new Chart(chartCanvas.getContext('2d'), chartConfig);
                console.log('Chart initialized with data:', chartData);
            <?php else: ?>
                console.log('No chart data available');
            <?php endif; ?>
        }

        // Notification system
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            const bgColor = type === 'error' ? 'bg-red-500' : 'bg-green-500';
            const icon = type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle';

            notification.className = `fixed top-4 right-4 ${bgColor} text-white px-6 py-3 rounded-lg shadow-lg z-50 transform translate-x-full transition-transform duration-300`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${icon} mr-2"></i>
                    ${message}
                </div>
            `;

            document.body.appendChild(notification);

            // Animate in
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);

            // Animate out and remove
            setTimeout(() => {
                notification.classList.add('translate-x-full');
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }

        /* PWA APP MOVIL */
        let deferredPrompt;

        // Debug: Log when the page loads
        console.log('PWA: Page loaded, waiting for beforeinstallprompt event...');

        // Debug: Check if the install button exists
        const installButton = document.getElementById('add-to-home-screen');
        console.log('PWA: Install button found:', !!installButton);

        window.addEventListener('beforeinstallprompt', (e) => {
            console.log('PWA: beforeinstallprompt event fired!');
            e.preventDefault();
            deferredPrompt = e;

            if (installButton) {  // Verifica si el botón existe
                console.log('PWA: Showing install button');
                installButton.style.display = 'block';

                installButton.addEventListener('click', () => {
                    console.log('PWA: Install button clicked');
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then((choiceResult) => {
                        console.log('PWA: User choice:', choiceResult.outcome);
                        if (choiceResult.outcome === 'accepted') {
                            showNotification('App instalada correctamente');
                            installButton.style.display = 'none';
                        } else {
                            showNotification('Instalación cancelada', 'error');
                        }
                        deferredPrompt = null;
                    });
                });
            } else {
                console.error('PWA: Install button not found in DOM');
            }
        });

        // Debug: Check if already installed
        window.addEventListener('appinstalled', (evt) => {
            console.log('PWA: App was installed');
            if (installButton) {
                installButton.style.display = 'none';
            }
        });

        // ==================== EDICIÓN DE ANOTACIONES ====================

        // Función para activar edición de anotación
        function editarAnotacion(fichajeId) {
            const textarea = document.getElementById('anotacion-' + fichajeId);
            const btnEdit = document.getElementById('btn-edit-anotacion-' + fichajeId);
            const actions = document.getElementById('anotacion-actions-' + fichajeId);

            // Habilitar el textarea y cambiar estilos
            textarea.removeAttribute('disabled');
            textarea.classList.remove('border-gray-200');
            textarea.classList.add('border-blue-500', 'ring-2', 'ring-blue-200');
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
            textarea.classList.remove('border-blue-500', 'ring-2', 'ring-blue-200');
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
                    textarea.classList.remove('border-blue-500', 'ring-2', 'ring-blue-200');
                    textarea.classList.add('border-gray-200');

                    // Mostrar botón de editar y ocultar acciones
                    if (btnEdit) {
                        btnEdit.classList.remove('hidden');
                    }
                    actions.classList.add('hidden');

                    // Mostrar notificación de éxito
                    showNotification('Anotación guardada correctamente');
                } else {
                    showNotification(data.message || 'Error al guardar la anotación', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error de conexión al guardar la anotación', 'error');
            });
        }

        // ==================== FIN EDICIÓN DE ANOTACIONES ====================

        // Register service worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/service-worker.js')
                    .then((registration) => {
                        console.log('SW registered: ', registration);
                    })
                    .catch((registrationError) => {
                        console.log('SW registration failed: ', registrationError);
                    });
            });
        }
    </script>
    <?php
    return ob_get_clean();
}

// Renderizar el contenido
$content = renderDashboardContent($rol_trabajador, $estado_fichaje, $horas_hoy, $fichajes, $trabajador_id, $empresa_id, $config_empresa, $datos_grafico, $vacaciones_pendientes_count, $empleados_por_estado_sesion, $incidencias_por_empleado, $total_incidencias);

// Usar el BaseLayout para renderizar la página completa
BaseLayout::render($page_title, $content, $config_empresa, $user_data);
?>