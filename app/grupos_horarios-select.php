<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir los modelos, componentes y utilidades necesarios para esta vista.
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/components/MenuHelper.php';
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

// Preparar el array de datos del usuario que se pasará al layout base para la cabecera de navegación.
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función encapsuladora que genera el HTML del contenido de selección de tipo de grupo horario usando output buffering.
function renderSelectGrupoHorarioContent() {
    ob_start();
    ?>
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Grupos Horarios', 'url' => '/app/grupos_horarios.php'],
        ['label' => 'Seleccionar Tipo de Horario']
    ]); 
    ?>

    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Crear Nuevo Grupo de Horario</h1>
            <p class="text-gray-600 mt-2">Seleccione el tipo de horario que desea configurar para su empresa</p>
        </div>
        <div class="flex-shrink-0">
            <a href="grupos_horarios.php" class="<?php echo CSSComponents::getButtonClasses('secondary', 'md'); ?>">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver a Grupos de Horarios
            </a>
        </div>
    </div>

    <!-- Schedule Type Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        
        <!-- Fixed Schedule Card -->
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> hover:shadow-lg transition-shadow duration-200 h-full flex flex-col">
            <div class="p-6 flex flex-col flex-1">
                <div class="flex items-center justify-center w-16 h-16 bg-blue-100 rounded-lg mb-4 mx-auto">
                    <i class="fas fa-clock text-2xl text-blue-600"></i>
                </div>
                
                <h3 class="text-xl font-semibold text-gray-900 text-center mb-3">Horario Fijo</h3>
                
                <p class="text-gray-600 text-center mb-6 text-sm leading-relaxed">
                    Horarios con horas específicas de entrada y salida. Ideal para trabajos con horarios regulares y predecibles.
                </p>
                <div class="mt-auto">
                    <a 
                        href="grupos_horarios-add-fijo.php" 
                        class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?> w-full text-center"
                    >
                        <i class="fas fa-plus mr-2"></i>
                        Crear Horario Fijo
                    </a>
                </div>
            </div>
        </div>

        <!-- Flexible Schedule Card -->
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> hover:shadow-lg transition-shadow duration-200 h-full flex flex-col">
            <div class="p-6 flex flex-col flex-1">
                <div class="flex items-center justify-center w-16 h-16 bg-yellow-100 rounded-lg mb-4 mx-auto">
                    <i class="fas fa-business-time text-2xl text-yellow-600"></i>
                </div>
                
                <h3 class="text-xl font-semibold text-gray-900 text-center mb-3">Horario Flexible</h3>
                
                <p class="text-gray-600 text-center mb-6 text-sm leading-relaxed">
                    Horarios basados en horas totales por período. Perfecto para trabajos con flexibilidad horaria.
                </p>
                <div class="mt-auto">
                    <a 
                        href="grupos_horarios-add-flexible.php" 
                        class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?> w-full text-center"
                    >
                        <i class="fas fa-plus mr-2"></i>
                        Crear Horario Flexible
                    </a>
                </div>
            </div>
        </div>

        <!-- Rotating Schedule Card -->
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> hover:shadow-lg transition-shadow duration-200 h-full flex flex-col">
            <div class="p-6 flex flex-col flex-1">
                <div class="flex items-center justify-center w-16 h-16 bg-purple-100 rounded-lg mb-4 mx-auto">
                    <i class="fas fa-sync-alt text-2xl text-purple-600"></i>
                </div>
                
                <h3 class="text-xl font-semibold text-gray-900 text-center mb-3">Horario Rotativo</h3>
                
                <p class="text-gray-600 text-center mb-6 text-sm leading-relaxed">
                    Horarios que rotan entre diferentes patrones de turnos. Ideal para operaciones 24/7.
                </p>
                <div class="mt-auto">
                    <a 
                        href="grupos_horarios-add-rotativo.php" 
                        class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?> w-full text-center"
                    >
                        <i class="fas fa-plus mr-2"></i>
                        Crear Horario Rotativo
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Information Section -->
    <div class="mt-12">
        <div class="<?php echo CSSComponents::getCardClasses('info'); ?> p-6">
            <div class="flex items-start">
                <i class="fas fa-info-circle text-blue-500 mr-4 mt-1 text-xl"></i>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-blue-900 mb-3">¿Necesita ayuda para elegir?</h3>
                    <div class="text-blue-800 space-y-2">
                        <p><strong>Horario Fijo:</strong> Use cuando los empleados trabajen las mismas horas todos los días (ej: 9:00 AM - 5:00 PM).</p>
                        <p><strong>Horario Flexible:</strong> Use cuando los empleados tengan que cumplir un número de horas por período, pero con flexibilidad en los horarios.</p>
                        <p><strong>Horario Rotativo:</strong> Use para turnos que cambian regularmente (ej: turnos de mañana, tarde y noche que rotan semanalmente).</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Añadir efectos hover y animaciones CSS a las tarjetas de tipo de horario.
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.hover\\:shadow-lg');
            
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });
    </script>
    <?php
    return ob_get_clean();
}

// Capturar el HTML generado mediante output buffering e invocarlo con los datos preparados.
$content = renderSelectGrupoHorarioContent();

// Invocar el layout base para construir y enviar la respuesta HTML completa al cliente.
BaseLayout::render('Seleccionar Tipo de Horario', $content, $config_empresa, $user_data);
?> 