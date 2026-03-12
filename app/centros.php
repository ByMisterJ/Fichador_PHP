<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir las clases necesarias
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/Centro.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';

// Verificar si el trabajador está logueado
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
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

// Obtener configuración de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Obtener parámetros de búsqueda
$busqueda = trim($_GET['busqueda'] ?? '');

// Obtener lista de centros (con búsqueda integrada)
$centro = new Centro();
$centros = $centro->obtenerTodosCentrosEmpresa($empresa_id, $busqueda);

// Obtener total de empleados activos de la empresa para mostrar en la tabla
$trabajador = new Trabajador();
$total_empleados_empresa = count($trabajador->obtenerTodosTrabajadoresEmpresa($empresa_id));

// Obtener documento de la empresa (usar el del primer centro si está disponible, o fallback)
$documento_empresa = !empty($centros) ? $centros[0]['empresa_documento'] : $centro->obtenerDocumentoEmpresa($empresa_id);

// Preparar datos de usuario para el layout
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función para renderizar el contenido de centros
function renderCentrosContent($centros, $busqueda, $config_empresa, $rol_trabajador, $total_empleados_empresa = 8, $documento_empresa = '20925308T') {
    ob_start();
    ?>
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Administrar Centros']
    ]); 
    ?>

    <!-- Success Message -->
    <?php if (isset($_GET['success'])): ?>
        <?php if ($_GET['success'] === 'created'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Centro creado exitosamente!</h3>
                    <p class="text-green-700 text-sm mt-1">El nuevo centro ha sido añadido a la empresa.</p>
                </div>
            </div>
        </div>
        <?php elseif ($_GET['success'] === 'updated'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Centro actualizado exitosamente!</h3>
                    <p class="text-green-700 text-sm mt-1">Los datos del centro han sido modificados.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Administrar Centros</h1>
                <p class="text-gray-600 mt-1">Gestiona los centros de trabajo de tu empresa</p>
            </div>
            <div class="mt-4 sm:mt-0">
                <a 
                    href="centro-add.php"
                    class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?>"
                >
                    <i class="fas fa-plus mr-2"></i>
                    AÑADIR NUEVO CENTRO
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
                        placeholder="Buscar por nombre o documento"
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
                    href="centros.php"
                    class="<?php echo CSSComponents::getButtonClasses('outline', 'md'); ?>"
                >
                    <i class="fas fa-times mr-2"></i>
                    RESET
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Centros Table -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            ID
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Nombre del Centro
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Documento
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            GPS
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
                    <?php if (empty($centros)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <div class="text-gray-500">
                                    <i class="fas fa-building text-4xl mb-4 opacity-50"></i>
                                    <p class="text-lg font-medium">No se encontraron centros</p>
                                    <?php if (!empty($busqueda)): ?>
                                        <p class="text-sm mt-2">Intenta con otros términos de búsqueda</p>
                                    <?php else: ?>
                                        <p class="text-sm mt-2">Aún no hay centros registrados</p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($centros as $centro): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($centro['id']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-medium mr-3" style="background-color: var(--color-primary)">
                                            <i class="fas fa-building"></i>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($centro['nombre']); ?>
                                            </div>
                                            <?php if (!empty($centro['direccion'])): ?>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($centro['direccion']); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <span class="font-mono"><?php echo htmlspecialchars($documento_empresa); ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <?php if ($centro['zona_gps']): ?>
                                            <div class="flex flex-col items-start">
                                                <span class="<?php echo CSSComponents::getBadgeClasses('success', 'sm'); ?>">
                                                    <i class="fas fa-map-marker-alt mr-1"></i>
                                                    Habilitado
                                                </span>
                                                <span class="text-xs text-gray-500 mt-1">
                                                    Radio: <?php echo htmlspecialchars($centro['gps_radio_metros'] ?? 100); ?>m
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="<?php echo CSSComponents::getBadgeClasses('default', 'sm'); ?>">
                                                <i class="fas fa-map-marker-alt mr-1 opacity-50"></i>
                                                Deshabilitado
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php 
                                        $empleados_asignados = (int)$centro['empleados_asignados'];
                                        echo $empleados_asignados . ' / ' . $total_empleados_empresa;
                                        ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex items-center space-x-2">
                                        <a 
                                            href="centro-edit.php?id=<?php echo $centro['id']; ?>"
                                            class="inline-flex items-center p-2 border border-transparent rounded-lg text-primary bg-primary-100 hover:bg-primary-200 focus:ring-4 focus:ring-primary-200 transition-colors"
                                            title="Editar centro"
                                        >
                                            <i class="fas fa-edit text-sm"></i>
                                        </a>
                                        <?php if (strtolower($rol_trabajador) === 'administrador'): ?>
                                        <button 
                                            class="delete-centro-btn inline-flex items-center p-2 border border-transparent rounded-lg text-red-600 bg-red-100 hover:bg-red-200 focus:ring-4 focus:ring-red-200 transition-colors"
                                            data-centro-id="<?php echo $centro['id']; ?>"
                                            data-centro-nombre="<?php echo htmlspecialchars($centro['nombre']); ?>"
                                            title="Eliminar centro"
                                        >
                                            <i class="fas fa-trash text-sm"></i>
                                        </button>
                                        <?php else: ?>
                                        <button 
                                            onclick="showPermissionNotification()"
                                            class="inline-flex items-center p-2 border border-transparent rounded-lg text-gray-400 bg-gray-100 cursor-not-allowed"
                                            title="Solo administradores pueden eliminar centros"
                                            disabled
                                        >
                                            <i class="fas fa-trash text-sm"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Table Footer with Stats -->
        <?php if (!empty($centros)): ?>
        <div class="bg-gray-50 px-6 py-3 border-t border-gray-200">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    Mostrando <span class="font-medium"><?php echo count($centros); ?></span> centro<?php echo count($centros) !== 1 ? 's' : ''; ?>
                    <?php if (!empty($busqueda)): ?>
                        de la búsqueda "<span class="font-medium"><?php echo htmlspecialchars($busqueda); ?></span>"
                    <?php endif; ?>
                </div>
                <div class="flex items-center space-x-4 text-sm text-gray-500">
                    <?php
                    $activos = array_filter($centros, function($centro) { return $centro['activo']; });
                    $inactivos = array_filter($centros, function($centro) { return !$centro['activo']; });
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
        // Auto-focus on search field
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="busqueda"]');
            if (searchInput && !searchInput.value) {
                searchInput.focus();
            }
        });

        // Clear search on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('input[name="busqueda"]');
                if (searchInput && searchInput.value) {
                    searchInput.value = '';
                    searchInput.form.submit();
                }
            }
        });

        // Handle delete centro button clicks
        document.addEventListener('click', function(e) {
            if (e.target.closest('.delete-centro-btn')) {
                e.preventDefault();
                const button = e.target.closest('.delete-centro-btn');
                const centroId = button.dataset.centroId;
                const centroNombre = button.dataset.centroNombre;
                
                showDeleteConfirmation(centroId, centroNombre);
            }
        });

        // Show delete confirmation dialog
        function showDeleteConfirmation(centroId, centroNombre) {
            // First, check if deletion is safe
            fetch('ajax/delete_centro.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    centro_id: parseInt(centroId),
                    action: 'check'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    let confirmMessage = `¿Estás seguro de que deseas eliminar el centro "${centroNombre}"?`;
                    
                    if (data.warning) {
                        confirmMessage += '\n\n⚠️ ATENCIÓN: ' + data.warning;
                    }
                    
                    confirmMessage += '\n\nEsta acción no se puede deshacer.';
                    
                    if (confirm(confirmMessage)) {
                        deleteCentro(centroId, centroNombre);
                    }
                } else {
                    showNotification('Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error al verificar el centro', 'error');
            });
        }

        // Delete centro
        function deleteCentro(centroId, centroNombre) {
            // Show loading state
            const deleteButton = document.querySelector(`[data-centro-id="${centroId}"]`);
            const originalHTML = deleteButton.innerHTML;
            deleteButton.innerHTML = '<i class="fas fa-spinner fa-spin text-sm"></i>';
            deleteButton.disabled = true;
            
            fetch('ajax/delete_centro.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    centro_id: parseInt(centroId),
                    action: 'delete'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    let successMessage = data.message;
                    if (data.empleados_desasignados > 0) {
                        successMessage += ` (${data.empleados_desasignados} empleado(s) desasignado(s))`;
                    }
                    showNotification(successMessage, 'success');
                    
                    // Remove the row from table with animation
                    const row = deleteButton.closest('tr');
                    row.style.opacity = '0.5';
                    row.style.transform = 'scale(0.95)';
                    
                    setTimeout(() => {
                        row.remove();
                        updateStatistics();
                    }, 300);
                    
                } else {
                    // Show error message
                    showNotification('Error: ' + data.error, 'error');
                    
                    // Restore button
                    deleteButton.innerHTML = originalHTML;
                    deleteButton.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error de conexión al eliminar el centro', 'error');
                
                // Restore button
                deleteButton.innerHTML = originalHTML;
                deleteButton.disabled = false;
            });
        }

        // Update statistics after deletion
        function updateStatistics() {
            const rows = document.querySelectorAll('tbody tr:not([data-empty])');
            const totalCentros = rows.length;
            
            // Update table footer
            const tableFooter = document.querySelector('.bg-gray-50');
            if (tableFooter && totalCentros === 0) {
                // Show empty state
                const tbody = document.querySelector('tbody');
                tbody.innerHTML = `
                    <tr data-empty>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <div class="text-gray-500">
                                <i class="fas fa-building text-4xl mb-4 opacity-50"></i>
                                <p class="text-lg font-medium">No se encontraron centros</p>
                                <p class="text-sm mt-2">Aún no hay centros registrados</p>
                            </div>
                        </td>
                    </tr>
                `;
            } else if (tableFooter) {
                // Update center count in footer
                const countSpan = tableFooter.querySelector('.font-medium');
                if (countSpan) {
                    countSpan.textContent = totalCentros;
                }
                
                // Update plural/singular text
                const centroText = tableFooter.querySelector('div');
                if (centroText) {
                    centroText.innerHTML = centroText.innerHTML.replace(
                        /\d+ centro(s)?/,
                        `${totalCentros} centro${totalCentros !== 1 ? 's' : ''}`
                    );
                }
            }
        }

        // Function to show permission notification
        function showPermissionNotification() {
            showNotification('Solo los administradores pueden eliminar centros', 'error');
        }

        // Function to show notifications
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 max-w-sm p-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full`;
            
            // Set color based on type
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
            
            // Add to page
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);
            
            // Auto remove after 5 seconds
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

// Renderizar el contenido
$content = renderCentrosContent($centros, $busqueda, $config_empresa, $rol_trabajador, $total_empleados_empresa, $documento_empresa);

// Usar el BaseLayout para renderizar la página completa
BaseLayout::render('Administrar Centros', $content, $config_empresa, $user_data);
?> 