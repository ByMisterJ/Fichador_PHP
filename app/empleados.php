<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir los modelos y componentes necesarios para la gestión del listado de empleados.
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';

// Verificar que el usuario dispone de una sesión autenticada válida; de lo contrario, redirigir al login.
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit();
}

// Verificar que el usuario tenga permisos (solo administradores y supervisores)
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    header('Location: /app/dashboard.php?error=sin_permisos');
    exit();
}

// Obtener datos del trabajador de la sesión
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$trabajador_login = $_SESSION['trabajador_login'] ?? 'N/A';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$centro_trabajador = $_SESSION['centro_trabajador'] ?? 'N/A';
$grupo_horario_trabajador = $_SESSION['grupo_horario_trabajador'] ?? 'N/A';
$tiempo_login = $_SESSION['tiempo_login'] ?? date('Y-m-d H:i:s');
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

// Obtener configuración de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Leer el parámetro de búsqueda enviado por GET para filtrar el listado de empleados.
$busqueda = trim($_GET['busqueda'] ?? '');

// Instanciar el modelo Trabajador y obtener todos los empleados activos de la empresa.
$trabajador = new Trabajador();
$empleados = $trabajador->obtenerTodosTrabajadoresEmpresaAdmin($empresa_id);

// Filtrar el array de empleados en memoria aplicando la búsqueda por nombre, DNI o tarjeta RFID.
if (!empty($busqueda)) {
    $empleados = array_filter($empleados, function($empleado) use ($busqueda) {
        $busqueda_lower = strtolower($busqueda);
        return (
            strpos(strtolower($empleado['nombre_trabajador']), $busqueda_lower) !== false ||
            strpos(strtolower($empleado['nombre_completo']), $busqueda_lower) !== false ||
            strpos(strtolower($empleado['dni']), $busqueda_lower) !== false ||
            strpos(strtolower($empleado['tarjeta_rfid'] ?? ''), $busqueda_lower) !== false
        );
    });
}

// Función auxiliar que mapea el rol de un empleado al nombre del estilo de badge correspondiente.
function obtenerColorRol($rol) {
    switch (strtolower($rol)) {
        case 'administrador':
            return 'error';
        case 'supervisor':
            return 'warning';
        case 'empleado':
        default:
            return 'success';
    }
}

// Preparar el array de datos del usuario que se pasará al layout base para la cabecera de navegación.
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función encapsuladora que genera el HTML del listado de empleados usando output buffering.
function renderEmpleadosContent($empleados, $busqueda, $trabajador_id, $config_empresa, $rol_trabajador) {
    ob_start();
    ?>
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Administrar empleados']
    ]); 
    ?>

    <!-- Success Message -->
    <?php if (isset($_GET['success'])): ?>
        <?php if ($_GET['success'] === 'empleado_creado'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Empleado creado exitosamente!</h3>
                    <p class="text-green-700 text-sm mt-1">El nuevo empleado ha sido añadido a la empresa.</p>
                </div>
            </div>
        </div>
        <?php elseif ($_GET['success'] === 'empleado_actualizado'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Empleado actualizado exitosamente!</h3>
                    <p class="text-green-700 text-sm mt-1">Los datos del empleado han sido modificados.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Administrar empleados</h1>
                <p class="text-gray-600 mt-1">Gestiona los empleados de tu empresa</p>
            </div>
            <div class="mt-4 sm:mt-0 flex space-x-3">
                <a 
                    href="/app/anadir_empleado.php"
                    class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?>"
                >
                    <i class="fas fa-plus mr-2"></i>
                    AÑADIR NUEVO EMPLEADO
                </a>
                <?php if (strtolower($rol_trabajador) === 'administrador'): ?>
                <a 
                    href="/app/empleados-bulk.php"
                    class="<?php echo CSSComponents::getButtonClasses('outline', 'md'); ?>"
                    title="Crear múltiples empleados a la vez"
                >
                    <i class="fas fa-users mr-2"></i>
                    CREAR EN LOTE
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Search and Filters -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6 mb-6">
        <form method="GET" action="" class="flex flex-col sm:flex-row gap-4">
            <div class="flex-1">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                    <input 
                        type="text" 
                        name="busqueda"
                        value="<?php echo htmlspecialchars($busqueda); ?>"
                        class="<?php echo CSSComponents::getInputClasses(); ?> pl-10 pr-3 py-2"
                        placeholder="Buscar por nombre o DNI o RFID"
                    >
                </div>
            </div>
            <div class="flex gap-2">
                <button 
                    type="submit"
                    class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?>"
                >
                    <i class="fas fa-search mr-2"></i>
                    Buscar
                </button>
                <?php if (!empty($busqueda)): ?>
                <a 
                    href="empleados.php"
                    class="<?php echo CSSComponents::getButtonClasses('outline', 'md'); ?>"
                >
                    <i class="fas fa-times mr-2"></i>
                    RESET
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Employees Table -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Usuario
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Nombre Completo
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            PIN
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            RFID
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            DNI
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Rol
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Activo
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Acción
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($empleados)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center">
                                <div class="text-gray-500">
                                    <i class="fas fa-users text-4xl mb-4 opacity-50"></i>
                                    <p class="text-lg font-medium">No se encontraron empleados</p>
                                    <?php if (!empty($busqueda)): ?>
                                        <p class="text-sm mt-2">Intenta con otros términos de búsqueda</p>
                                    <?php else: ?>
                                        <p class="text-sm mt-2">Aún no hay empleados registrados</p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($empleados as $empleado): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-medium mr-3" style="background-color: var(--color-primary)">
                                            <?php echo strtoupper(substr($empleado['nombre_trabajador'], 0, 1)); ?>
                                        </div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($empleado['nombre_trabajador']); ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($empleado['nombre_completo']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-mono text-gray-900"><?php echo htmlspecialchars($empleado['pin']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php if (!empty($empleado['tarjeta_rfid'])): ?>
                                            <span class="font-mono"><?php echo htmlspecialchars($empleado['tarjeta_rfid']); ?></span>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($empleado['dni']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="<?php echo CSSComponents::getBadgeClasses(obtenerColorRol($empleado['rol']), 'sm'); ?>">
                                        <?php echo htmlspecialchars($empleado['rol']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <button 
                                        class="toggle-activo relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 <?php echo $empleado['activo'] ? 'bg-green-500' : 'bg-red-500'; ?> disabled:opacity-50 disabled:cursor-not-allowed"
                                        data-trabajador-id="<?php echo $empleado['id']; ?>"
                                        data-estado="<?php echo $empleado['activo'] ? '1' : '0'; ?>"
                                        <?php if ($empleado['id'] == $trabajador_id): ?> disabled title="No puedes desactivar tu propia cuenta"<?php endif; ?>
                                    >
                                        <span class="sr-only">Toggle estado activo</span>
                                        <span class="<?php echo $empleado['activo'] ? 'translate-x-6' : 'translate-x-1'; ?> inline-block h-4 w-4 transform rounded-full bg-white transition-transform"></span>
                                    </button>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex items-center space-x-2">
                                        <a 
                                            href="/app/editar_empleado.php?id=<?php echo $empleado['id']; ?>"
                                            class="inline-flex items-center p-2 border border-transparent rounded-lg text-primary bg-primary-100 hover:bg-primary-200 focus:ring-4 focus:ring-primary-200 transition-colors"
                                            title="Editar empleado"
                                        >
                                            <i class="fas fa-edit text-sm"></i>
                                        </a>
                                        <a 
                                            href="/app/admin_ubicaciones.php?empleado_id=<?php echo $empleado['id']; ?>"
                                            class="inline-flex items-center p-2 border border-transparent rounded-lg text-blue-600 bg-blue-100 hover:bg-blue-200 focus:ring-4 focus:ring-blue-200 transition-colors"
                                            title="Ver ubicaciones de fichajes en el mapa"
                                        >
                                            <i class="fas fa-map-marker-alt text-sm"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Table Footer with Stats -->
        <?php if (!empty($empleados)): ?>
        <div class="bg-gray-50 px-6 py-3 border-t border-gray-200">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    Mostrando <span class="font-medium"><?php echo count($empleados); ?></span> empleado<?php echo count($empleados) !== 1 ? 's' : ''; ?>
                    <?php if (!empty($busqueda)): ?>
                        de la búsqueda "<span class="font-medium"><?php echo htmlspecialchars($busqueda); ?></span>"
                    <?php endif; ?>
                </div>
                <div class="flex items-center space-x-4 text-sm text-gray-500">
                    <?php
                    $activos = array_filter($empleados, function($emp) { return $emp['activo']; });
                    $inactivos = array_filter($empleados, function($emp) { return !$emp['activo']; });
                    ?>
                    <span class="flex items-center">
                        <div class="w-2 h-2 bg-green-500 rounded-full mr-1"></div>
                        <?php echo count($activos); ?> activos
                    </span>
                    <?php if (count($inactivos) > 0): ?>
                    <span class="flex items-center">
                        <div class="w-2 h-2 bg-red-500 rounded-full mr-1"></div>
                        <?php echo count($inactivos); ?> inactivos
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Enfocar automáticamente el campo de búsqueda al cargar la página si está vacío.
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="busqueda"]');
            if (searchInput && !searchInput.value) {
                searchInput.focus();
            }
        });

        // Limpiar el campo de búsqueda y recargar la lista al pulsar la tecla Escape.
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('input[name="busqueda"]');
                if (searchInput && searchInput.value) {
                    searchInput.value = '';
                    searchInput.form.submit();
                }
            }
        });

        // Nota: el botón de mapa está activo y enlaza con admin_ubicaciones.php para ver ubicaciones.

        // Gestionar el cambio de estado activo/inactivo del empleado mediante el toggle y delegación de eventos.
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toggle-activo') && !e.target.closest('.toggle-activo').disabled) {
                const button = e.target.closest('.toggle-activo');
                const trabajadorId = button.dataset.trabajadorId;
                const estadoActual = button.dataset.estado;
                
                // Deshabilitar el botón temporalmente para evitar múltiples peticiones simultáneas.
                button.disabled = true;
                button.style.opacity = '0.6';
                
                // Enviar la petición AJAX al endpoint de cambio de estado del empleado.
                fetch('ajax/toggle_activo.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        trabajador_id: parseInt(trabajadorId)
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Actualizar la apariencia visual del toggle según el nuevo estado del empleado.
                        const nuevoEstado = data.nuevo_estado;
                        const toggle = button.querySelector('span:last-child');
                        
                        if (nuevoEstado === 1) {
                            // Cambiar estilos al estado activo (verde).
                            button.classList.remove('bg-red-500');
                            button.classList.add('bg-green-500');
                            toggle.classList.remove('translate-x-1');
                            toggle.classList.add('translate-x-6');
                        } else {
                            // Cambiar estilos al estado inactivo (rojo).
                            button.classList.remove('bg-green-500');
                            button.classList.add('bg-red-500');
                            toggle.classList.remove('translate-x-6');
                            toggle.classList.add('translate-x-1');
                        }
                        
                        // Actualizar el atributo data-estado con el nuevo valor para reflejar el estado actual.
                        button.dataset.estado = nuevoEstado.toString();
                        
                        // Mostrar notificación de éxito con el mensaje devuelto por el servidor.
                        showNotification(data.message, 'success');
                        
                        // Recalcular y actualizar las estadísticas del pie de tabla.
                        updateFooterStats();
                        
                    } else {
                        showNotification(data.message || 'Error al actualizar el estado', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error de conexión', 'error');
                })
                .finally(() => {
                    // Rehabilitar el botón y restaurar su opacidad tras completar la petición AJAX.
                    button.disabled = false;
                    button.style.opacity = '1';
                });
            }
        });

        // Mostrar una notificación flotante en la esquina superior derecha con soporte para distintos tipos de alerta.
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full ${
                type === 'success' ? 'bg-green-500 text-white' : 
                type === 'error' ? 'bg-red-500 text-white' : 
                'bg-blue-500 text-white'
            }`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);
            
            setTimeout(() => {
                notification.classList.add('translate-x-full');
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }

        // Recalcular el recuento de empleados activos e inactivos y actualizar el pie de tabla dinámicamente.
        function updateFooterStats() {
            const toggles = document.querySelectorAll('.toggle-activo');
            let activos = 0;
            let inactivos = 0;
            
            toggles.forEach(toggle => {
                if (toggle.dataset.estado === '1') {
                    activos++;
                } else {
                    inactivos++;
                }
            });
            
            const footerContainer = document.querySelector('.bg-gray-50.px-6.py-3.border-t.border-gray-200');
            if (footerContainer) {
                const statsContainer = footerContainer.querySelector('.flex.items-center.space-x-4.text-sm.text-gray-500');
                if (statsContainer) {
                    statsContainer.innerHTML = '';
                    
                    const activosSpan = document.createElement('span');
                    activosSpan.className = 'flex items-center';
                    activosSpan.innerHTML = `<div class="w-2 h-2 bg-green-500 rounded-full mr-1"></div>${activos} activos`;
                    statsContainer.appendChild(activosSpan);
                    
                    if (inactivos > 0) {
                        const inactivosSpan = document.createElement('span');
                        inactivosSpan.className = 'flex items-center';
                        inactivosSpan.innerHTML = `<div class="w-2 h-2 bg-red-500 rounded-full mr-1"></div>${inactivos} inactivos`;
                        statsContainer.appendChild(inactivosSpan);
                    }
                }
            }
        }
    </script>
    <?php
    return ob_get_clean();
}

// Capturar el HTML generado mediante output buffering e invocarlo con los datos preparados.
$content = renderEmpleadosContent($empleados, $busqueda, $trabajador_id, $config_empresa, $rol_trabajador);

// Invocar el layout base para construir y enviar la respuesta HTML completa al cliente.
BaseLayout::render('Administrar Empleados', $content, $config_empresa, $user_data);
?> 