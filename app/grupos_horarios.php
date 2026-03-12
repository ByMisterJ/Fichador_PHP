<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir los modelos y componentes necesarios para la gestión de grupos horarios.
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/GruposHorarios.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';

// Verificar que el usuario dispone de una sesión autenticada válida; de lo contrario, redirigir al login.
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Verificar que el rol del usuario autoriza el acceso: solo administradores y supervisores pueden gestionar grupos horarios.
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    header('Location: /app/dashboard.php');
    exit;
}

// Recuperar los datos del usuario autenticado desde la superglobal $_SESSION.
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Trabajador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

// Obtener la configuración de la empresa (colores, logo, nombre de app, etc.) desde la sesión.
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Instanciar el modelo GruposHorarios y leer el parámetro de búsqueda para filtrar el listado.
$gruposHorarios = new GruposHorarios();

// Leer el parámetro de búsqueda enviado por GET y obtener todos los grupos horarios de la empresa.
$busqueda = trim($_GET['busqueda'] ?? '');
$grupos_horarios = $gruposHorarios->obtenerGruposHorarioEmpresa($empresa_id);

// Filtrar el array de grupos horarios en memoria aplicando la búsqueda por nombre, descripción o tipo.
if (!empty($busqueda)) {
    $grupos_horarios = array_filter($grupos_horarios, function($grupo) use ($busqueda) {
        $busqueda_lower = strtolower($busqueda);
        return (
            strpos(strtolower($grupo['nombre']), $busqueda_lower) !== false ||
            strpos(strtolower($grupo['descripcion'] ?? ''), $busqueda_lower) !== false ||
            strpos(strtolower(GruposHorarios::obtenerNombreTipo($grupo['tipo'])), $busqueda_lower) !== false
        );
    });
}

// Calcular estadísticas agregadas para mostrar en el encabezado del listado.
$total_grupos = count($grupos_horarios);
$grupos_con_empleados = count(array_filter($grupos_horarios, function($grupo) {
    return $grupo['empleados_asignados'] > 0;
}));

// Procesar acciones o notificaciones pendientes (éxito de operaciones previas, etc.).

// Preparar el array de datos del usuario que se pasará al layout base para la cabecera de navegación.
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función encapsuladora que genera el HTML del listado de grupos horarios usando output buffering.
function renderGruposHorariosContent($grupos_horarios, $busqueda, $total_grupos, $grupos_con_empleados, $config_empresa) {
    ob_start();
    ?>
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Grupos Horarios']
    ]); 
    ?>

    <!-- Success Message -->
    <?php if (isset($_GET['success'])): ?>
        <?php if ($_GET['success'] === 'grupo_creado'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Grupo horario creado exitosamente!</h3>
                    <p class="text-green-700 text-sm mt-1">El nuevo grupo horario ha sido añadido a la empresa.</p>
                </div>
            </div>
        </div>
        <?php elseif ($_GET['success'] === 'grupo_actualizado'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Grupo horario actualizado exitosamente!</h3>
                    <p class="text-green-700 text-sm mt-1">Los datos del grupo horario han sido modificados.</p>
                </div>
            </div>
        </div>
        <?php elseif ($_GET['success'] === 'grupo_eliminado'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Grupo horario eliminado exitosamente!</h3>
                    <p class="text-green-700 text-sm mt-1">El grupo horario ha sido eliminado y los empleados asignados han sido desasignados.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Administrar Grupos de Horarios</h1>
                <p class="text-gray-600 mt-1">Gestiona los grupos horarios de tu empresa</p>
            </div>
            <div class="mt-4 sm:mt-0">
                <a 
                    href="/app/grupos_horarios-select.php"
                    class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?>"
                >
                    <i class="fas fa-plus mr-2"></i>
                    AÑADIR NUEVO GRUPO
                </a>
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
                        placeholder="Buscar por nombre, descripción o tipo"
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
                    href="grupos_horarios.php"
                    class="<?php echo CSSComponents::getButtonClasses('outline', 'md'); ?>"
                >
                    <i class="fas fa-times mr-2"></i>
                    RESET
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Grupos Horarios Table -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            ID
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Nombre del Grupo
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Tipo de Horario
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Empleados asignados
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Acción
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($grupos_horarios)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <div class="text-gray-500">
                                    <i class="fas fa-clock text-4xl mb-4 opacity-50"></i>
                                    <p class="text-lg font-medium">No se encontraron grupos horarios</p>
                                    <?php if (!empty($busqueda)): ?>
                                        <p class="text-sm mt-2">Intenta con otros términos de búsqueda</p>
                                    <?php else: ?>
                                        <p class="text-sm mt-2">Aún no hay grupos horarios configurados</p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($grupos_horarios as $grupo): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                #<?php echo $grupo['id']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10">
                                        <div class="h-10 w-10 rounded-full flex items-center justify-center text-white font-medium text-sm" style="background-color: <?php 
                                            $color_map = ['success' => '#10b981', 'warning' => '#f59e0b', 'info' => '#3b82f6'];
                                            echo $color_map[GruposHorarios::obtenerColorTipo($grupo['tipo'])] ?? '#6b7280';
                                        ?>">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($grupo['nombre']); ?>
                                        </div>
                                        <?php if ($grupo['descripcion']): ?>
                                        <div class="text-sm text-gray-500 max-w-xs truncate">
                                            <?php echo htmlspecialchars($grupo['descripcion']); ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="text-sm text-gray-400 italic">Sin descripción</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <span class="<?php echo CSSComponents::getBadgeClasses(GruposHorarios::obtenerColorTipo($grupo['tipo']), 'sm'); ?>">
                                    <?php echo GruposHorarios::obtenerNombreTipo($grupo['tipo']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div class="flex items-center">
                                    <span class="<?php echo CSSComponents::getBadgeClasses('info', 'sm'); ?>">
                                        <?php echo $grupo['empleados_asignados']; ?>/<?php echo $grupo['total_empleados_empresa']; ?>
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex items-center space-x-2">
                                    <?php if ($grupo['tipo'] === 'fijo'): ?>
                                        <a 
                                            href="/app/grupos_horarios-edit-fijo.php?id=<?php echo $grupo['id']; ?>"
                                            class="inline-flex items-center p-2 border border-transparent rounded-lg text-primary bg-primary-100 hover:bg-primary-200 focus:ring-4 focus:ring-primary-200 transition-colors"
                                            title="Editar grupo horario fijo"
                                        >
                                            <i class="fas fa-edit text-sm"></i>
                                        </a>
                                    <?php elseif ($grupo['tipo'] === 'flexible'): ?>
                                        <a 
                                            href="/app/grupos_horarios-edit-flexible.php?id=<?php echo $grupo['id']; ?>"
                                            class="inline-flex items-center p-2 border border-transparent rounded-lg text-primary bg-primary-100 hover:bg-primary-200 focus:ring-4 focus:ring-primary-200 transition-colors"
                                            title="Editar grupo horario flexible"
                                        >
                                            <i class="fas fa-edit text-sm"></i>
                                        </a>
                                    <?php elseif ($grupo['tipo'] === 'rotativo'): ?>
                                        <a 
                                            href="/app/grupos_horarios-edit-rotativo.php?id=<?php echo $grupo['id']; ?>"
                                            class="inline-flex items-center p-2 border border-transparent rounded-lg text-primary bg-primary-100 hover:bg-primary-200 focus:ring-4 focus:ring-primary-200 transition-colors"
                                            title="Editar grupo horario rotativo"
                                        >
                                            <i class="fas fa-edit text-sm"></i>
                                        </a>
                                    <?php endif; ?>
                                    <button 
                                        class="inline-flex items-center p-2 border border-transparent rounded-lg text-red-600 bg-red-100 hover:bg-red-200 focus:ring-4 focus:ring-red-200 delete-grupo-btn transition-colors"
                                        title="Eliminar grupo horario"
                                        data-grupo-id="<?php echo $grupo['id']; ?>"
                                        data-grupo-nombre="<?php echo htmlspecialchars($grupo['nombre']); ?>"
                                    >
                                        <i class="fas fa-trash text-sm"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer con estadísticas -->
        <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between text-sm text-gray-600">
                <div class="mb-2 sm:mb-0">
                    Mostrando <span class="font-medium"><?php echo count($grupos_horarios); ?></span> grupos horarios
                    <?php if (!empty($busqueda)): ?>
                        de la búsqueda "<span class="font-medium text-primary"><?php echo htmlspecialchars($busqueda); ?></span>"
                    <?php endif; ?>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="flex items-center">
                        <div class="w-2 h-2 bg-primary rounded-full mr-2"></div>
                        Total: <span class="font-medium ml-1"><?php echo $total_grupos; ?></span>
                    </span>
                    <span class="flex items-center">
                        <div class="w-2 h-2 bg-green-500 rounded-full mr-2"></div>
                        Con empleados: <span class="font-medium ml-1"><?php echo $grupos_con_empleados; ?></span>
                    </span>
                    <span class="flex items-center">
                        <div class="w-2 h-2 bg-gray-400 rounded-full mr-2"></div>
                        Sin empleados: <span class="font-medium ml-1"><?php echo $total_grupos - $grupos_con_empleados; ?></span>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Enfocar automáticamente el campo de búsqueda al cargar la página si está vacío.
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="busqueda"]');
            if (searchInput && !searchInput.value) {
                searchInput.focus();
            }
        });

        // Limpiar el campo de búsqueda y recargar el listado al pulsar la tecla Escape.
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('input[name="busqueda"]');
                if (searchInput && searchInput.value) {
                    searchInput.value = '';
                    searchInput.form.submit();
                }
            }
        });

        // Gestionar los clics en los botones de eliminación de grupo horario mediante delegación de eventos.
        document.addEventListener('click', function(e) {
            if (e.target.closest('.delete-grupo-btn')) {
                e.preventDefault();
                const button = e.target.closest('.delete-grupo-btn');
                const grupoId = button.dataset.grupoId;
                const grupoNombre = button.dataset.grupoNombre;
                
                showDeleteConfirmation(grupoId, grupoNombre);
            }
        });

        // Mostrar el diálogo de confirmación de eliminación: primero comprueba si la operación es segura via AJAX.
        function showDeleteConfirmation(grupoId, grupoNombre) {
            // Verificar mediante petición AJAX si el grupo horario puede eliminarse sin dejar empleados sin horario.
            fetch('ajax/delete_grupo_horario.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    grupo_id: parseInt(grupoId),
                    action: 'check'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    let confirmMessage = `¿Estás seguro de que deseas eliminar el grupo horario "${grupoNombre}"?`;
                    
                    if (data.warning) {
                        confirmMessage += '\n\n⚠️ ATENCIÓN: ' + data.warning;
                    }
                    
                    confirmMessage += '\n\nEsta acción no se puede deshacer.';
                    
                    if (confirm(confirmMessage)) {
                        deleteGrupoHorario(grupoId, grupoNombre);
                    }
                } else {
                    showNotification('Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error al verificar el grupo horario', 'error');
            });
        }

        // Ejecutar la eliminación del grupo horario mediante petición AJAX al endpoint correspondiente.
        function deleteGrupoHorario(grupoId, grupoNombre) {
            // Activar el estado de carga en el botón para proporcionar feedback visual al usuario.
            const deleteButton = document.querySelector(`[data-grupo-id="${grupoId}"]`);
            const originalHTML = deleteButton.innerHTML;
            deleteButton.innerHTML = '<i class="fas fa-spinner fa-spin text-sm"></i>';
            deleteButton.disabled = true;
            
            fetch('ajax/delete_grupo_horario.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    grupo_id: parseInt(grupoId),
                    action: 'delete'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mostrar notificación de éxito con el mensaje devuelto por la API.
                    let successMessage = data.message;
                    if (data.empleados_desasignados > 0) {
                        successMessage += ` (${data.empleados_desasignados} empleado(s) desasignado(s))`;
                    }
                    showNotification(successMessage, 'success');
                    
                    // Eliminar la fila de la tabla aplicando una transición de opacidad para suavizar la experiencia.
                    const row = deleteButton.closest('tr');
                    row.style.opacity = '0.5';
                    row.style.transform = 'scale(0.95)';
                    
                    setTimeout(() => {
                        row.remove();
                        updateStatistics();
                    }, 300);
                    
                } else {
                    // Mostrar el mensaje de error devuelto por el servidor.
                    showNotification('Error: ' + data.error, 'error');
                    
                    // Restaurar el botón a su estado original para permitir reintentos.
                    deleteButton.innerHTML = originalHTML;
                    deleteButton.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error de conexión al eliminar el grupo', 'error');
                
                // Restaurar el botón a su estado original tras un error de red.
                deleteButton.innerHTML = originalHTML;
                deleteButton.disabled = false;
            });
        }

        // Actualizar los contadores de estadísticas tras eliminar un grupo horario de la tabla.
        function updateStatistics() {
            const rows = document.querySelectorAll('tbody tr:not([data-empty])');
            const totalGroups = rows.length;
            
            // Contar cuántos grupos tienen al menos un empleado asignado para actualizar el indicador.
            let groupsWithEmployees = 0;
            rows.forEach(row => {
                const employeesBadge = row.querySelector('td:nth-child(4) .bg-blue-100');
                if (employeesBadge) {
                    const text = employeesBadge.textContent.trim();
                    const employeesCount = parseInt(text.split('/')[0]);
                    if (employeesCount > 0) {
                        groupsWithEmployees++;
                    }
                }
            });
            
            // Actualizar los elementos del DOM con las estadísticas recalculadas.
            const statsContainer = document.querySelector('.bg-gray-50');
            if (statsContainer && totalGroups === 0) {
                // Mostrar el estado vacío si ya no quedan grupos en la tabla.
                const tbody = document.querySelector('tbody');
                tbody.innerHTML = `
                    <tr data-empty>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <div class="text-gray-500">
                                <i class="fas fa-clock text-4xl mb-4 opacity-50"></i>
                                <p class="text-lg font-medium">No se encontraron grupos horarios</p>
                                <p class="text-sm mt-2">Aún no hay grupos horarios configurados</p>
                            </div>
                        </td>
                    </tr>
                `;
            } else {
                // Actualizar los contadores de grupos totales, con empleados y sin empleados.
                const totalSpan = statsContainer.querySelector('.bg-primary + span .font-medium');
                const withEmployeesSpan = statsContainer.querySelector('.bg-green-500 + span .font-medium');
                const withoutEmployeesSpan = statsContainer.querySelector('.bg-gray-400 + span .font-medium');
                
                if (totalSpan) totalSpan.textContent = totalGroups;
                if (withEmployeesSpan) withEmployeesSpan.textContent = groupsWithEmployees;
                if (withoutEmployeesSpan) withoutEmployeesSpan.textContent = totalGroups - groupsWithEmployees;
                
                // Actualizar el contador principal de grupos en la cabecera del listado.
                const mainCount = document.querySelector('.mb-2 .font-medium');
                if (mainCount) mainCount.textContent = totalGroups;
            }
        }

        // Mostrar una notificación flotante en la esquina superior derecha con soporte para distintos tipos.
        function showNotification(message, type = 'info') {
            // Crear el elemento de notificación con las clases CSS base.
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 max-w-sm p-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full`;
            
            // Aplicar estilos visuales según el tipo de notificación (éxito, error o informativa).
            if (type === 'success') {
                notification.className += ' bg-green-100 border border-green-200 text-green-800';
                notification.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-3"></i>
                        <span>${message}</span>
                    </div>
                `;
            } else if (type === 'error') {
                notification.className += ' bg-red-100 border border-red-200 text-red-800';
                notification.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                        <span>${message}</span>
                    </div>
                `;
            } else {
                notification.className += ' bg-blue-100 border border-blue-200 text-blue-800';
                notification.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-blue-500 mr-3"></i>
                        <span>${message}</span>
                    </div>
                `;
            }
            
            // Añadir el elemento de notificación al DOM.
            document.body.appendChild(notification);
            
            // Iniciar la animación de entrada con un pequeño retardo para permitir el repintado del navegador.
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);
            
            // Eliminar automáticamente la notificación del DOM tras 5 segundos con animación de salida.
            setTimeout(() => {
                notification.classList.add('translate-x-full');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 5000);
        }
    </script>
    <?php
    return ob_get_clean();
}

// Capturar el HTML generado mediante output buffering e invocarlo con los datos preparados.
$content = renderGruposHorariosContent($grupos_horarios, $busqueda, $total_grupos, $grupos_con_empleados, $config_empresa);

// Invocar el layout base para construir y enviar la respuesta HTML completa al cliente.
BaseLayout::render('Grupos Horarios', $content, $config_empresa, $user_data);
?> 