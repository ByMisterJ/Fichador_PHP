<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir los modelos, componentes y utilidades necesarios para esta vista.
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/Fichajes.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';

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

// Verificar que el parámetro de identificador del fichaje esté presente en la query string (GET).
$fichaje_id = $_GET['id'] ?? null;
if (!$fichaje_id || !is_numeric($fichaje_id)) {
    header('Location: fichajes.php?error=invalid_id');
    exit;
}

// Instanciar el modelo Fichajes para consultar los detalles del registro.
$fichajes = new Fichajes();

// Obtener los datos completos del fichaje desde la base de datos por su identificador.
$fichaje_detalle = $fichajes->obtenerDetalleFichaje($fichaje_id, $empresa_id);
if (!$fichaje_detalle) {
    header('Location: fichajes.php?error=not_found');
    exit;
}

// Obtener el historial de auditoría (log de cambios) asociado al fichaje.
$historial_cambios = $fichajes->obtenerHistorialFichaje($fichaje_id);

// Preparar el array de datos del usuario que se pasará al layout base para la cabecera de navegación.
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Construir la URL de retorno al listado, restaurando los filtros previamente guardados en sesión.
$back_url = 'fichajes.php';
if (isset($_SESSION['fichajes_filtros'])) {
    $filtros_guardados = $_SESSION['fichajes_filtros'];
    $query_params = [];

    foreach ($filtros_guardados as $key => $value) {
        if ($key === 'trabajadores' && is_array($value) && !empty($value)) {
            foreach ($value as $trabajador_id) {
                $query_params[] = 'trabajadores[]=' . urlencode($trabajador_id);
            }
        } elseif (!empty($value) && $key !== 'trabajadores') {
            $query_params[] = $key . '=' . urlencode($value);
        }
    }

    if (!empty($query_params)) {
        $back_url .= '?' . implode('&', $query_params);
    }
}

// Función encapsuladora que genera el HTML del contenido principal usando output buffering.
function renderFichajeViewContent($fichaje_detalle, $historial_cambios, $rol_trabajador, $fichaje_id, $back_url = 'fichajes.php') {
    ob_start();
    ?>
    
    <!-- Breadcrumb -->
    <?php
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Fichajes', 'url' => '/app/' . $back_url],
        ['label' => 'Detalle de Sesión']
    ]);
    ?>

    <!-- Success Message -->
    <?php if (isset($_GET['success']) && $_GET['success'] === 'updated'): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <span class="text-green-700">Fichaje actualizado correctamente</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Detalle de Sesión de Trabajo</h1>
                <p class="text-gray-600 mt-1">
                    Información completa de la sesión de <?php echo htmlspecialchars($fichaje_detalle['nombre_completo']); ?>
                </p>
            </div>
            <div class="mt-4 sm:mt-0 flex gap-3">
                <?php if (in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])): ?>
                    <a href="fichaje-edit.php?id=<?php echo $fichaje_id; ?>" class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?>">
                        <i class="fas fa-edit mr-2"></i>
                        Editar Fichaje
                    </a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($back_url); ?>" class="<?php echo CSSComponents::getButtonClasses('outline', 'md'); ?>">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Volver a Fichajes
                </a>
            </div>
        </div>
    </div>

    <!-- Información de la Sesión -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6 mb-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <i class="fas fa-clock text-blue-500 mr-2"></i>
            Información de la Sesión
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Estado -->
            <div>
                <span class="text-sm text-gray-600">Estado:</span>
                <div class="mt-1">
                    <span class="<?php echo CSSComponents::getBadgeClasses($fichaje_detalle['estado'] === 'iniciada' ? 'info' : 'success', 'sm'); ?>">
                        <?php echo $fichaje_detalle['estado'] === 'iniciada' ? 'En curso' : 'Finalizada'; ?>
                    </span>
                </div>
            </div>
            
            <!-- Duración -->
            <div>
                <span class="text-sm text-gray-600">Duración:</span>
                <div class="mt-1 text-sm font-medium text-gray-900">
                    <?php echo $fichaje_detalle['duracion_formateada']; ?>
                </div>
            </div>
            
            <!-- Método -->
            <div>
                <span class="text-sm text-gray-600">Método:</span>
                <div class="mt-1 text-sm font-medium text-gray-900 capitalize">
                    <?php echo htmlspecialchars($fichaje_detalle['metodo']); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Detalles de Horarios -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6 mb-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <i class="fas fa-clock text-blue-500 mr-2"></i>
            Horarios de Entrada y Salida
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Entrada -->
            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                <div class="flex items-center mb-3">
                    <i class="fas fa-sign-in-alt text-green-600 mr-2"></i>
                    <span class="font-medium text-green-800">Entrada</span>
                </div>
                <div class="space-y-2 text-sm">
                    <div>
                        <span class="text-green-700">Fecha:</span>
                        <span class="ml-2 font-medium"><?php echo $fichaje_detalle['fecha_inicio_formateada']; ?></span>
                    </div>
                    <div>
                        <span class="text-green-700">Hora:</span>
                        <span class="ml-2 font-medium"><?php echo $fichaje_detalle['hora_inicio_formateada']; ?></span>
                    </div>
                </div>
            </div>

            <!-- Salida -->
            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-center mb-3">
                    <i class="fas fa-sign-out-alt text-red-600 mr-2"></i>
                    <span class="font-medium text-red-800">Salida</span>
                </div>
                <?php if ($fichaje_detalle['fecha_fin_formateada'] && $fichaje_detalle['hora_fin_formateada']): ?>
                <div class="space-y-2 text-sm">
                    <div>
                        <span class="text-red-700">Fecha:</span>
                        <span class="ml-2 font-medium"><?php echo $fichaje_detalle['fecha_fin_formateada']; ?></span>
                    </div>
                    <div>
                        <span class="text-red-700">Hora:</span>
                        <span class="ml-2 font-medium"><?php echo $fichaje_detalle['hora_fin_formateada']; ?></span>
                    </div>
                </div>
                <?php else: ?>
                <div class="text-center py-2">
                    <p class="text-red-600 text-sm">Sesión en curso</p>
                    <p class="text-red-500 text-xs">No registrada</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Mapa de Ubicaciones -->
    <?php if (($fichaje_detalle['inicio_latitud'] && $fichaje_detalle['inicio_longitud']) || 
              ($fichaje_detalle['fin_latitud'] && $fichaje_detalle['fin_longitud'])): ?>
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-4 mb-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <i class="fas fa-map-marker-alt text-red-500 mr-2"></i>
            Ubicaciones GPS
        </h3>
        
        <div id="map" style="height: 400px; position: relative; z-index: 1;" class="rounded-lg border border-gray-300 mb-4"></div>
        
        <!-- Leyenda -->
        <div class="p-3 bg-gray-50 rounded-lg">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs sm:text-sm">
                <?php if ($fichaje_detalle['inicio_latitud'] && $fichaje_detalle['inicio_longitud']): ?>
                <div class="flex items-center">
                    <div class="w-3 h-3 bg-green-500 rounded-full mr-2 flex-shrink-0"></div>
                    <span class="text-gray-600">Entrada: <?php echo $fichaje_detalle['hora_inicio_formateada']; ?></span>
                </div>
                <?php endif; ?>
                <?php if ($fichaje_detalle['fin_latitud'] && $fichaje_detalle['fin_longitud']): ?>
                <div class="flex items-center">
                    <div class="w-3 h-3 bg-red-500 rounded-full mr-2 flex-shrink-0"></div>
                    <span class="text-gray-600">Salida: <?php echo $fichaje_detalle['hora_fin_formateada']; ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Observaciones -->
    <?php if ($fichaje_detalle['observaciones']): ?>
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6 mb-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <i class="fas fa-sticky-note text-yellow-500 mr-2"></i>
            Observaciones
        </h3>
        <div class="text-sm text-gray-700 whitespace-pre-wrap bg-gray-50 p-4 rounded-lg">
            <?php echo htmlspecialchars($fichaje_detalle['observaciones']); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Historial de Registros -->
    <?php if (!empty($historial_cambios)): ?>
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <i class="fas fa-history text-purple-500 mr-2"></i>
            Historial de Registros
        </h3>
        
        <div class="overflow-x-auto">
            <table class="<?php echo CSSComponents::getTableClasses(); ?>">
                <thead>
                    <tr class="<?php echo CSSComponents::getTableHeaderClasses(); ?>">
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">Inicio</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">Fin</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">Fecha</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">Descripción</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">IP</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">Navegador</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">Tipo</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-left">Usuario</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">Localización</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial_cambios as $cambio): ?>
                    <tr class="border-b border-gray-200 hover:bg-gray-50">
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-sm">
                            <?php echo $cambio['hora_inicio'] ? date('H:i:s', strtotime($cambio['hora_inicio'])) : '-'; ?>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-sm">
                            <?php echo $cambio['hora_fin'] ? date('H:i:s', strtotime($cambio['hora_fin'])) : '-'; ?>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-sm">
                            <?php echo date('d/m/Y H:i', strtotime($cambio['fecha_hora_cambio'])); ?>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-sm">
                            <?php echo htmlspecialchars($cambio['descripcion']); ?>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-sm font-mono">
                            <?php echo htmlspecialchars($cambio['ip_address'] ?? '-'); ?>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-sm">
                            <?php 
                            $userAgent = $cambio['user_agent'] ?? '';
                            if (strlen($userAgent) > 40) {
                                // Extraer el nombre del navegador principal a partir del User-Agent para mostrar en la vista de detalle.
                                $browser = 'Desconocido';
                                if (strpos($userAgent, 'Chrome') !== false) $browser = 'Chrome';
                                elseif (strpos($userAgent, 'Firefox') !== false) $browser = 'Firefox';
                                elseif (strpos($userAgent, 'Safari') !== false) $browser = 'Safari';
                                elseif (strpos($userAgent, 'Edge') !== false) $browser = 'Edge';
                                elseif (strpos($userAgent, 'Opera') !== false) $browser = 'Opera';
                                
                                echo '<span title="' . htmlspecialchars($userAgent) . '" class="cursor-help">' . 
                                     htmlspecialchars($browser) . '</span>';
                            } else {
                                echo htmlspecialchars($userAgent ?: '-');
                            }
                            ?>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-sm">
                            <span class="<?php 
                                $tipo = $cambio['tipo_cambio'];
                                switch($tipo) {
                                    case 'iniciada': echo CSSComponents::getBadgeClasses('success', 'xs'); break;
                                    case 'finalizada': echo CSSComponents::getBadgeClasses('info', 'xs'); break;
                                    case 'cancelada': echo CSSComponents::getBadgeClasses('error', 'xs'); break;
                                    case 'modificada': echo CSSComponents::getBadgeClasses('warning', 'xs'); break;
                                    default: echo CSSComponents::getBadgeClasses('default', 'xs'); break;
                                }
                            ?>">
                                <?php echo ucfirst($cambio['tipo_cambio']); ?>
                            </span>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-sm">
                            <?php echo htmlspecialchars($cambio['nombre_completo']); ?>
                        </td>
                        <td class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center">
                            <?php if ($cambio['tiene_coordenadas']): ?>
                                <a href="https://maps.google.com/?q=<?php echo $cambio['latitud']; ?>,<?php echo $cambio['longitud']; ?>" 
                                   target="_blank" 
                                   class="text-blue-600 hover:text-blue-800" 
                                   title="Ver en Google Maps">
                                    <i class="fas fa-map-marker-alt"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Incluir Leaflet CSS y JS para el mapa -->
    <?php if (($fichaje_detalle['inicio_latitud'] && $fichaje_detalle['inicio_longitud']) || 
              ($fichaje_detalle['fin_latitud'] && $fichaje_detalle['fin_longitud'])): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin=""/>
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""></script>

    <script>
        let map = null;
        
        // Datos de ubicaciones desde PHP
        const ubicacionesData = [
            <?php if ($fichaje_detalle['inicio_latitud'] && $fichaje_detalle['inicio_longitud']): ?>
            {
                tipo: 'entrada',
                latitud: <?php echo $fichaje_detalle['inicio_latitud']; ?>,
                longitud: <?php echo $fichaje_detalle['inicio_longitud']; ?>,
                fecha_hora: '<?php echo $fichaje_detalle['fecha_inicio_formateada'] . ' ' . $fichaje_detalle['hora_inicio_formateada']; ?>',
                nombre_completo: '<?php echo htmlspecialchars($fichaje_detalle['nombre_completo'], ENT_QUOTES); ?>',
                nombre_trabajador: '<?php echo htmlspecialchars($fichaje_detalle['nombre_trabajador'], ENT_QUOTES); ?>'
            }<?php echo ($fichaje_detalle['fin_latitud'] && $fichaje_detalle['fin_longitud']) ? ',' : ''; ?>
            <?php endif; ?>
            <?php if ($fichaje_detalle['fin_latitud'] && $fichaje_detalle['fin_longitud']): ?>
            {
                tipo: 'salida',
                latitud: <?php echo $fichaje_detalle['fin_latitud']; ?>,
                longitud: <?php echo $fichaje_detalle['fin_longitud']; ?>,
                fecha_hora: '<?php echo $fichaje_detalle['fecha_fin_formateada'] . ' ' . $fichaje_detalle['hora_fin_formateada']; ?>',
                nombre_completo: '<?php echo htmlspecialchars($fichaje_detalle['nombre_completo'], ENT_QUOTES); ?>',
                nombre_trabajador: '<?php echo htmlspecialchars($fichaje_detalle['nombre_trabajador'], ENT_QUOTES); ?>'
            }
            <?php endif; ?>
        ];
        
        document.addEventListener('DOMContentLoaded', function() {
            initializeMap();
        });
        
        function initializeMap() {
            if (ubicacionesData.length === 0) return;
            
            // Coordenadas por defecto (primera ubicación disponible)
            const defaultLat = ubicacionesData[0].latitud;
            const defaultLng = ubicacionesData[0].longitud;
            
            // Inicializar mapa
            map = L.map('map').setView([defaultLat, defaultLng], 15);
            
            // Añadir capa de OpenStreetMap
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            
            // Añadir marcadores
            const markers = [];
            ubicacionesData.forEach(function(ubicacion) {
                const lat = parseFloat(ubicacion.latitud);
                const lng = parseFloat(ubicacion.longitud);
                
                if (isNaN(lat) || isNaN(lng)) return;
                
                // Crear icono basado en el tipo de fichaje
                const iconColor = ubicacion.tipo === 'entrada' ? 'green' : 'red';
                const iconName = ubicacion.tipo === 'entrada' ? 'sign-in-alt' : 'sign-out-alt';
                
                const customIcon = L.divIcon({
                    className: 'custom-div-icon',
                    html: `<div style="background-color: ${iconColor}; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3);">
                             <i class="fas fa-${iconName}" style="color: white; font-size: 12px;"></i>
                           </div>`,
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                });
                
                // Crear marcador
                const marker = L.marker([lat, lng], { icon: customIcon });
                
                // Crear popup con información
                const popupContent = `
                    <div class="p-2">
                        <h4 class="font-semibold text-gray-900 mb-2">${ubicacion.nombre_completo}</h4>
                        <div class="space-y-1 text-sm">
                            <div class="flex items-center">
                                <i class="fas fa-user w-4 text-gray-500 mr-2"></i>
                                <span class="text-gray-700">${ubicacion.nombre_trabajador}</span>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-${iconName} w-4 text-${iconColor}-500 mr-2"></i>
                                <span class="text-gray-700 capitalize">${ubicacion.tipo === 'entrada' ? 'Entrada' : 'Salida'}</span>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-clock w-4 text-gray-500 mr-2"></i>
                                <span class="text-gray-700">${ubicacion.fecha_hora}</span>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-map-marker-alt w-4 text-gray-500 mr-2"></i>
                                <span class="text-gray-700 text-xs">${lat.toFixed(6)}, ${lng.toFixed(6)}</span>
                            </div>
                        </div>
                    </div>
                `;
                
                marker.bindPopup(popupContent);
                marker.addTo(map);
                markers.push(marker);
            });
            
            // Ajustar vista para mostrar todos los marcadores
            if (markers.length > 1) {
                const group = new L.featureGroup(markers);
                map.fitBounds(group.getBounds().pad(0.1));
            }
        }
    </script>

    <style>
        .custom-div-icon {
            background: transparent;
            border: none;
        }
        
        .leaflet-popup-content {
            margin: 8px 12px;
        }
        
        .leaflet-popup-content-wrapper {
            border-radius: 8px;
        }
    </style>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}

// Capturar el HTML generado mediante output buffering e invocar el layout base para enviar la respuesta al cliente.
$content = renderFichajeViewContent($fichaje_detalle, $historial_cambios, $rol_trabajador, $fichaje_id, $back_url);
BaseLayout::render('Detalle de Sesión', $content, $config_empresa, $user_data);
?> 