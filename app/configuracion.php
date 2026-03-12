<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir archivos necesarios
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/Empresa.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
require_once __DIR__ . '/../shared/layouts/BaseLayout.php';
require_once __DIR__ . '/../shared/components/Breadcrumb.php';
require_once __DIR__ . '/../assets/css/components.php';
require_once __DIR__ . '/../shared/forms/ConfiguracionForm.php';

// Verificar autenticación
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Verificar que el usuario sea administrador
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (strtolower($rol_trabajador) !== 'administrador') {
    header('Location: /app/dashboard.php');
    exit;
}

// Obtener datos del trabajador de la sesión
$nombre_trabajador = $_SESSION['nombre_trabajador'] ?? 'Administrador';
$correo_trabajador = $_SESSION['correo_trabajador'] ?? 'N/A';
$trabajador_id = $_SESSION['id_trabajador'] ?? null;
$empresa_id = $_SESSION['empresa_id'] ?? null;

if (!$empresa_id) {
    header('Location: /app/dashboard.php');
    exit;
}

// Inicializar clase Empresa
$empresa = new Empresa();

// Variables para el formulario
$errors = [];
$success_message = '';

// Handle AJAX logo deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_logo') {
    header('Content-Type: application/json');
    
    $result = $empresa->deleteCompanyLogo($empresa_id);
    echo json_encode($result);
    exit;
}

// Procesar formulario si es POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: log raw POST data for toggles
    error_log("Configuration POST data (selected fields): localizacion=" . ($_POST['localizacion'] ?? 'not set') . 
              ", grupo_horario=" . ($_POST['grupo_horario'] ?? 'not set') . 
              ", generar_ausencia_olvido_fichaje=" . ($_POST['generar_ausencia_olvido_fichaje'] ?? 'not set'));
    
    $form_data = procesarFormularioConfiguracion($_POST);
    
    // Debug: log processed toggle data
    error_log("Processed toggle data: localizacion=" . $form_data['localizacion'] . 
              ", grupo_horario=" . $form_data['grupo_horario'] . 
              ", generar_ausencia_olvido_fichaje=" . $form_data['generar_ausencia_olvido_fichaje']);
    
    // Validar datos usando el método de la clase Empresa
    $errors = $empresa->validarDatos($form_data);
    
    if (empty($errors)) {
        // Actualizar configuración con archivos
        $resultado = $empresa->actualizarConfiguracion($empresa_id, $form_data, $_FILES);
        
        if ($resultado['success']) {
            $success_message = $resultado['message'];
            
            // Actualizar variables de sesión con la nueva configuración
            Trabajador::actualizarSesionEmpresa($form_data);
            
            // Recargar configuración actualizada desde la base de datos
            $config_empresa = Trabajador::obtenerConfiguracionEmpresa();
        } else {
            $errors['general'] = 'Error: ' . $resultado['error'];
        }
    }
}

// Obtener configuración actual de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();
if (!$config_empresa) {
    header('Location: /app/dashboard.php');
    exit;
}

// Obtener estadísticas de la empresa
$stats = $empresa->obtenerEstadisticas($empresa_id);

// Si hay datos del formulario (POST con errores), usar esos datos, sino usar los de la BD
$form_data = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors) ? 
    array_merge($config_empresa, $form_data ?? []) : 
    $config_empresa;

/**
 * Procesar datos del formulario
 */
function procesarFormularioConfiguracion($post_data) {
    $data = [
        // Información básica
        'nombre' => trim($post_data['nombre'] ?? ''),
        'nif_cif' => trim($post_data['nif_cif'] ?? ''),
        'direccion' => trim($post_data['direccion'] ?? ''),
        'telefono' => trim($post_data['telefono'] ?? ''),
        'email' => trim($post_data['email'] ?? ''),
        
        // Configuración de la APP
        'color_app' => trim($post_data['color_app_text'] ?? $post_data['color_app'] ?? '#3B82F6'),
        'color_boton_fichar' => trim($post_data['color_boton_fichar_text'] ?? $post_data['color_boton_fichar'] ?? '#43a047'),
        'nombre_app' => trim($post_data['nombre_app'] ?? ''),

        
        // Configuraciones generales (toggles - check for value '1')
        'localizacion' => isset($post_data['localizacion']) && $post_data['localizacion'] == '1' ? 1 : 0,
        'salir_app_fichar' => isset($post_data['salir_app_fichar']) && $post_data['salir_app_fichar'] == '1' ? 1 : 0,
        'enviar_informe_administrador' => isset($post_data['enviar_informe_administrador']) && $post_data['enviar_informe_administrador'] == '1' ? 1 : 0,
        'empleados_ver_fichajes' => isset($post_data['empleados_ver_fichajes']) && $post_data['empleados_ver_fichajes'] == '1' ? 1 : 0,
        'empleado_editar_perfil' => isset($post_data['empleado_editar_perfil']) && $post_data['empleado_editar_perfil'] == '1' ? 1 : 0,
        'empleados_solicitar_vacaciones' => isset($post_data['empleados_solicitar_vacaciones']) && $post_data['empleados_solicitar_vacaciones'] == '1' ? 1 : 0,
        'empleados_solicitar_incidencias' => isset($post_data['empleados_solicitar_incidencias']) && $post_data['empleados_solicitar_incidencias'] == '1' ? 1 : 0,
        'empleados_detalles_fichajes' => isset($post_data['empleados_detalles_fichajes']) && $post_data['empleados_detalles_fichajes'] == '1' ? 1 : 0,
        
        // Configuración de horario (toggles - check for value '1')
        'grupo_horario' => isset($post_data['grupo_horario']) && $post_data['grupo_horario'] == '1' ? 1 : 0,
        'forzar_horario' => isset($post_data['forzar_horario']) && $post_data['forzar_horario'] == '1' ? 1 : 0,
        'forzar_inicio_grupo_horario' => isset($post_data['forzar_inicio_grupo_horario']) && $post_data['forzar_inicio_grupo_horario'] == '1' ? 1 : 0,
        'grupo_horario_ventana' => isset($post_data['grupo_horario_ventana']) && $post_data['grupo_horario_ventana'] == '1' ? 1 : 0,
        'grupo_horario_respetar_segundos' => isset($post_data['grupo_horario_respetar_segundos']) && $post_data['grupo_horario_respetar_segundos'] == '1' ? 1 : 0,
        'grupo_horario_ventana_minutos' => (int)($post_data['grupo_horario_ventana_minutos'] ?? 15),
        
        // Automatizaciones (toggles - check for value '1')
        'generar_ausencia_olvido_fichaje' => isset($post_data['generar_ausencia_olvido_fichaje']) && $post_data['generar_ausencia_olvido_fichaje'] == '1' ? 1 : 0,
        'cerrar_fichaje_automaticamente_olvido_fichar' => isset($post_data['cerrar_fichaje_automaticamente_olvido_fichar']) && $post_data['cerrar_fichaje_automaticamente_olvido_fichar'] == '1' ? 1 : 0,
    ];
    
    return $data;
}

// Preparar datos de usuario para el layout
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función para renderizar el contenido de configuración
function renderConfiguracionContent($form_data, $errors, $stats, $success_message, $config_empresa) {
    ob_start();
    ?>
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Configuración']
    ]); 
    ?>

    <!-- Success Message -->
    <?php if (!empty($success_message)): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Configuración actualizada!</h3>
                    <p class="text-green-700 text-sm mt-1"><?php echo htmlspecialchars($success_message); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Error Messages -->
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

    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Configuración de la Empresa</h1>
                <p class="text-gray-600 mt-1">Gestiona la configuración general del sistema</p>
            </div>
            
            <!-- Statistics Cards -->
            <div class="mt-4 sm:mt-0 grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-3">
                    <div class="text-lg font-bold text-primary"><?php echo $stats['empleados_activos']; ?></div>
                    <div class="text-xs text-gray-600">Empleados Activos</div>
                </div>
                <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-3">
                    <div class="text-lg font-bold text-green-600"><?php echo $stats['total_centros']; ?></div>
                    <div class="text-xs text-gray-600">Centros</div>
                </div>
                <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-3">
                    <div class="text-lg font-bold text-blue-600"><?php echo $stats['total_grupos_horarios']; ?></div>
                    <div class="text-xs text-gray-600">Grupos Horarios</div>
                </div>
                <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-3">
                    <div class="text-lg font-bold text-orange-600"><?php echo $stats['fichajes_hoy']; ?></div>
                    <div class="text-xs text-gray-600">Fichajes Hoy</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Configuration Form -->
    <?php echo ConfiguracionForm::render($form_data, $errors, $stats); ?>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initializeConfigurationForm();
        });

        function initializeConfigurationForm() {
            // Sincronizar color picker con input de texto
            const colorPicker = document.getElementById('color_app');
            const colorText = document.getElementById('color_app_text');
            const colorBotonPicker = document.getElementById('color_boton_fichar');
            const colorBotonText = document.getElementById('color_boton_fichar_text');
            const previewIcon = document.getElementById('preview_icon');
            const iconInput = document.getElementById('icono_app');

            if (colorPicker && colorText) {
                // Sincronizar cuando cambia el color picker
                colorPicker.addEventListener('change', function() {
                    colorText.value = this.value;
                    updatePreviewColors();
                });

                // Sincronizar cuando cambia el texto y validar formato
                colorText.addEventListener('input', function() {
                    const colorValue = this.value.trim();
                    
                    // Validar formato de color
                    if (colorValue.match(/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/)) {
                        colorPicker.value = colorValue;
                        this.classList.remove('border-red-300', 'bg-red-50');
                        this.classList.add('border-gray-300');
                        updatePreviewColors();
                    } else if (colorValue.length > 0) {
                        // Color inválido
                        this.classList.add('border-red-300', 'bg-red-50');
                        this.classList.remove('border-gray-300');
                    }
                });

                // Sincronizar en tiempo real mientras escribe
                colorText.addEventListener('keyup', function() {
                    const colorValue = this.value.trim();
                    if (colorValue.match(/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/)) {
                        colorPicker.value = colorValue;
                        updatePreviewColors();
                    }
                });
                
                // Asegurar que el color picker refleje el valor inicial del texto
                if (colorText.value && colorText.value.match(/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/)) {
                    colorPicker.value = colorText.value;
                }
            }

            // Sincronizar color picker del botón fichar con input de texto
            if (colorBotonPicker && colorBotonText) {
                // Sincronizar cuando cambia el color picker
                colorBotonPicker.addEventListener('change', function() {
                    colorBotonText.value = this.value;
                });

                // Sincronizar cuando cambia el texto y validar formato
                colorBotonText.addEventListener('input', function() {
                    const colorValue = this.value.trim();
                    
                    // Validar formato de color
                    if (colorValue.match(/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/)) {
                        colorBotonPicker.value = colorValue;
                        this.classList.remove('border-red-300', 'bg-red-50');
                        this.classList.add('border-gray-300');
                    } else if (colorValue.length > 0) {
                        // Color inválido
                        this.classList.add('border-red-300', 'bg-red-50');
                        this.classList.remove('border-gray-300');
                    }
                });

                // Sincronizar en tiempo real mientras escribe
                colorBotonText.addEventListener('keyup', function() {
                    const colorValue = this.value.trim();
                    if (colorValue.match(/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/)) {
                        colorBotonPicker.value = colorValue;
                    }
                });
                
                // Asegurar que el color picker refleje el valor inicial del texto
                if (colorBotonText.value && colorBotonText.value.match(/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/)) {
                    colorBotonPicker.value = colorBotonText.value;
                }
            }

            // Actualizar icono en vista previa
            if (iconInput && previewIcon) {
                iconInput.addEventListener('input', function() {
                    previewIcon.className = this.value || 'fas fa-clock';
                });
            }

            function updatePreviewColors() {
                // Usar el valor del input de texto como fuente principal
                const color = colorText.value.trim() || colorPicker.value;
                
                // Actualizar vista previa del icono
                const iconPreview = document.querySelector('.h-12.w-12.rounded-full');
                const iconPreviewSmall = document.querySelector('.h-10.w-10.rounded-md');
                
                if (iconPreview) {
                    iconPreview.style.backgroundColor = color;
                }
                if (iconPreviewSmall) {
                    iconPreviewSmall.style.backgroundColor = color;
                }
            }

            // Validación del formulario
            const form = document.getElementById('configuracionForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!validateConfigurationForm()) {
                        e.preventDefault();
                    }
                });
            }
        }

        function validateConfigurationForm() {
            const errors = [];
            
            // Validar campos obligatorios
            const nombre = document.getElementById('nombre');
            const nombreApp = document.getElementById('nombre_app');
            
            if (!nombre || !nombre.value.trim()) {
                errors.push('El nombre de la empresa es obligatorio');
            }
            
            if (!nombreApp || !nombreApp.value.trim()) {
                errors.push('El nombre de la aplicación es obligatorio');
            }
            
            // Validar formato de color
            const colorApp = document.getElementById('color_app_text');
            if (colorApp && colorApp.value && !colorApp.value.match(/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/)) {
                errors.push('El formato del color no es válido. Use el formato #RRGGBB o #RGB');
            }

            // Validar formato de color del botón fichar
            const colorBotonFichar = document.getElementById('color_boton_fichar_text');
            if (colorBotonFichar && colorBotonFichar.value && !colorBotonFichar.value.match(/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/)) {
                errors.push('El formato del color del botón fichar no es válido. Use el formato #RRGGBB o #RGB');
            }
            
            // Validar rango de minutos
            const ventanaMinutos = document.getElementById('grupo_horario_ventana_minutos');
            if (ventanaMinutos) {
                const value = parseInt(ventanaMinutos.value);
                if (value < 1 || value > 60) {
                    errors.push('Los minutos de ventana deben estar entre 1 y 60');
                }
            }
            
            if (errors.length > 0) {
                alert('Errores encontrados:\n\n' + errors.join('\n'));
                return false;
            }
            
            return true;
        }

        // Función para mostrar notificaciones
        function showNotification(message, type = 'info') {
            // Similar a la implementación de grupos_horarios.php
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 max-w-sm p-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full`;
            
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
            }
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);
            
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
$content = renderConfiguracionContent($form_data, $errors, $stats, $success_message, $config_empresa);

// Usar el BaseLayout para renderizar la página completa
BaseLayout::render('Configuración', $content, $config_empresa, $user_data);
?> 