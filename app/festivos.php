<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir los modelos, componentes y utilidades necesarios para esta vista.
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/Festivos.php';
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

// Verificar que el rol sea administrador: la gestión de festivos está restringida exclusivamente a este rol.
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (strtolower($rol_trabajador) !== 'administrador') {
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

// Instanciar el modelo Festivos para acceder a los métodos de gestión de días festivos.
$festivos = new Festivos();

$errors = [];
$success_message = '';

// Definir los años disponibles para la navegación por pestañas del calendario de festivos.
$anios_disponibles = [2026, 2025];
$anio_seleccionado = isset($_GET['anio']) && in_array((int)$_GET['anio'], $anios_disponibles) 
    ? (int)$_GET['anio'] 
    : 2026;

// Procesar los formularios de alta, modificación y eliminación de festivos (método HTTP POST).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $anio_seleccionado = isset($_POST['anio']) && in_array((int)$_POST['anio'], $anios_disponibles) 
        ? (int)$_POST['anio'] 
        : $anio_seleccionado;
    
    switch ($accion) {
        case 'crear_bulk':
            $festivos_texto = $_POST['festivos_texto'] ?? '';
            
            if (empty(trim($festivos_texto))) {
                $errors['general'] = 'Debes introducir al menos un festivo';
                break;
            }
            
            $lineas = explode("\n", $festivos_texto);
            $creados = 0;
            $errores_lineas = [];
            
            foreach ($lineas as $num_linea => $linea) {
                $linea = trim($linea);
                if (empty($linea)) continue;
                
                // Parsear: fecha, descripcion
                $partes = array_map('trim', explode(',', $linea, 2));
                
                if (count($partes) < 2) {
                    $errores_lineas[] = "Línea " . ($num_linea + 1) . ": formato incorrecto (usar: fecha, descripción)";
                    continue;
                }
                
                $fecha_raw = $partes[0];
                $descripcion = $partes[1];
                
                // Intentar parsear la fecha en varios formatos
                $fecha = null;
                $formatos = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'];
                foreach ($formatos as $formato) {
                    $fecha_obj = DateTime::createFromFormat($formato, $fecha_raw);
                    if ($fecha_obj && $fecha_obj->format($formato) === $fecha_raw) {
                        $fecha = $fecha_obj->format('Y-m-d');
                        break;
                    }
                }
                
                if (!$fecha) {
                    $errores_lineas[] = "Línea " . ($num_linea + 1) . ": fecha inválida '$fecha_raw'";
                    continue;
                }
                
                // Validar datos
                $errores_validacion = $festivos->validarDatosFestivo($fecha, $descripcion);
                
                if (!empty($errores_validacion)) {
                    $errores_lineas[] = "Línea " . ($num_linea + 1) . ": " . implode(', ', $errores_validacion);
                    continue;
                }
                
                // Crear festivo
                $resultado = $festivos->crearFestivo($empresa_id, $fecha, $descripcion);
                if ($resultado['success']) {
                    $creados++;
                } else {
                    $errores_lineas[] = "Línea " . ($num_linea + 1) . ": " . $resultado['error'];
                }
            }
            
            if ($creados > 0) {
                $success_message = "Se han creado $creados festivo(s) correctamente";
                $_POST = [];
            }
            
            if (!empty($errores_lineas)) {
                $errors['general'] = implode("\n", $errores_lineas);
            }
            break;
            
        case 'actualizar':
            $id = $_POST['id'] ?? '';
            $fecha = $_POST['fecha'] ?? '';
            $descripcion = $_POST['descripcion'] ?? '';
            
            if (empty($id) || !is_numeric($id)) {
                $errors['general'] = 'ID de festivo inválido';
                break;
            }
            
            $errores_validacion = $festivos->validarDatosFestivo($fecha, $descripcion);
            
            if (empty($errores_validacion)) {
                $resultado = $festivos->actualizarFestivo($id, $empresa_id, $fecha, $descripcion);
                if ($resultado['success']) {
                    $success_message = $resultado['message'];
                    // Limpiar datos del formulario tras éxito
                    $_POST = [];
                } else {
                    $errors['general'] = $resultado['error'];
                }
            } else {
                $errors['general'] = 'Error en los datos: ' . implode(', ', $errores_validacion);
            }
            break;
            
        case 'eliminar':
            $id = $_POST['id'] ?? '';
            
            if (empty($id) || !is_numeric($id)) {
                $errors['general'] = 'ID de festivo inválido';
                break;
            }
            
            $resultado = $festivos->eliminarFestivo($id, $empresa_id);
            if ($resultado['success']) {
                $success_message = $resultado['message'];
                // Limpiar datos del formulario tras éxito
                $_POST = [];
            } else {
                $errors['general'] = $resultado['error'];
            }
            break;
    }
}

// Obtener la lista de festivos de la empresa filtrada por el año seleccionado en la pestaña activa.
$festivos_list = $festivos->obtenerFestivosPorEmpresa($empresa_id, $anio_seleccionado);
$total_festivos = $festivos->contarFestivos($empresa_id, $anio_seleccionado);

// Preparar el array de datos del usuario que se pasará al layout base para la cabecera de navegación.
$user_data = [
    'nombre' => $nombre_trabajador,
    'correo' => $correo_trabajador,
    'rol' => $rol_trabajador
];

// Función encapsuladora que genera el HTML del listado de festivos usando output buffering.
function renderFestivosContent($festivos_list, $total_festivos, $errors, $success_message, $config_empresa, $anio_seleccionado, $anios_disponibles) {
    ob_start();
    ?>
    
    <!-- Breadcrumb -->
    <?php 
    Breadcrumb::render([
        ['label' => 'Inicio', 'url' => '/app/dashboard.php', 'icon' => 'fas fa-home'],
        ['label' => 'Vacaciones / Ausencias', 'url' => '/app/vacaciones.php'],
        ['label' => 'Gestión de Festivos Anuales']
    ]); 
    ?>

    <!-- Messages -->
    <?php if (!empty($errors['general'])): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('error'); ?>">
            <div class="flex items-start">
                <i class="fas fa-exclamation-triangle text-red-500 mr-3 mt-0.5"></i>
                <div>
                    <h3 class="text-red-800 font-medium">Error</h3>
                    <pre class="text-red-700 text-sm mt-1 whitespace-pre-wrap font-sans"><?php echo htmlspecialchars($errors['general']); ?></pre>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="mb-6 p-4 <?php echo CSSComponents::getCardClasses('success'); ?>">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div>
                    <h3 class="text-green-800 font-medium">¡Éxito!</h3>
                    <p class="text-green-700 text-sm mt-1"><?php echo htmlspecialchars($success_message); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Gestión de Festivos Anuales</h1>
                <p class="text-gray-600 mt-1">Administra los días festivos de la empresa</p>
            </div>
        </div>
    </div>

    <!-- Formulario para añadir festivos (Global) -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-6 mb-6">
        <h3 class="text-lg font-medium text-gray-900 mb-2">
            <i class="fas fa-plus-circle text-primary-500 mr-2"></i>
            Añadir festivos
        </h3>
        <p class="text-sm text-gray-500 mb-4">
            Introduce un festivo por línea con formato: <code class="bg-gray-100 px-1 rounded">fecha, descripción</code>
            <br>
            <span class="text-xs">Formatos de fecha aceptados: dd/mm/yyyy, yyyy-mm-dd, dd-mm-yyyy</span>
        </p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="accion" value="crear_bulk">
            
            <div>
                <textarea 
                    name="festivos_texto" 
                    id="festivos_texto"
                    rows="5"
                    class="<?php echo CSSComponents::getTextareaClasses(); ?>"
                    placeholder="01/01/2026, Año Nuevo&#10;06/01/2026, Día de Reyes&#10;19/03/2026, San José"
                ><?php echo htmlspecialchars($_POST['festivos_texto'] ?? ''); ?></textarea>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="<?php echo CSSComponents::getButtonClasses('primary', 'md'); ?>">
                    <i class="fas fa-save mr-2"></i>
                    Añadir Festivos
                </button>
            </div>
        </form>
    </div>

    <!-- Year Tabs -->
    <div class="mb-6">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                <?php foreach ($anios_disponibles as $anio): ?>
                    <a 
                        href="?anio=<?php echo $anio; ?>" 
                        class="<?php echo $anio === $anio_seleccionado 
                            ? 'border-primary-500 text-primary-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm' 
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'; ?>"
                    >
                        <i class="fas fa-calendar-alt mr-2"></i>
                        Festivos <?php echo $anio; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>

    <!-- Tabla de festivos -->
    <div class="<?php echo CSSComponents::getCardClasses('default'); ?> overflow-hidden">
        <div class="overflow-x-auto">
            <table class="<?php echo CSSComponents::getTableClasses(); ?>">
                <thead class="<?php echo CSSComponents::getTableHeaderClasses(); ?>">
                    <tr>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?>">Fecha</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?>">Descripción</th>
                        <th class="<?php echo CSSComponents::getTableCellClasses(); ?>">Acción</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($festivos_list)): ?>
                        <tr>
                            <td colspan="3" class="<?php echo CSSComponents::getTableCellClasses(); ?> text-center text-gray-500 py-8">
                                <i class="fas fa-calendar-times text-gray-300 text-3xl mb-3"></i>
                                <p>No hay festivos registrados</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($festivos_list as $festivo): ?>
                            <tr class="hover:bg-gray-50" id="festivo-<?php echo $festivo['id']; ?>">
                                <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                    <input 
                                        type="date" 
                                        value="<?php echo htmlspecialchars($festivo['fecha']); ?>"
                                        class="<?php echo CSSComponents::getInputClasses(); ?>"
                                        data-field="fecha"
                                        data-id="<?php echo $festivo['id']; ?>"
                                        onchange="prepararActualizacion(<?php echo $festivo['id']; ?>)"
                                    >
                                </td>
                                <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                    <input 
                                        type="text" 
                                        value="<?php echo htmlspecialchars($festivo['descripcion']); ?>"
                                        class="<?php echo CSSComponents::getInputClasses(); ?>"
                                        data-field="descripcion"
                                        data-id="<?php echo $festivo['id']; ?>"
                                        maxlength="255"
                                        placeholder="Descripción del festivo"
                                        onchange="prepararActualizacion(<?php echo $festivo['id']; ?>)"
                                    >
                                </td>
                                <td class="<?php echo CSSComponents::getTableCellClasses(); ?>">
                                    <div class="flex items-center space-x-2">
                                        <button 
                                            type="button"
                                            onclick="guardarFestivo(<?php echo $festivo['id']; ?>)"
                                            class="<?php echo CSSComponents::getButtonClasses('success', 'sm'); ?>"
                                            title="Guardar"
                                        >
                                            <i class="fas fa-save"></i>
                                        </button>
                                        <button 
                                            type="button"
                                            onclick="eliminarFestivo(<?php echo $festivo['id']; ?>)"
                                            class="<?php echo CSSComponents::getButtonClasses('danger', 'sm'); ?>"
                                            title="Eliminar"
                                        >
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
    <div class="mt-6">
        <div class="<?php echo CSSComponents::getCardClasses('default'); ?> p-4 text-center">
            <div class="text-2xl font-bold text-primary-600"><?php echo $total_festivos; ?></div>
            <div class="text-sm text-gray-600">Festivos Configurados en <?php echo $anio_seleccionado; ?></div>
        </div>
    </div>
    
    <script>
        // Variables globales para almacenar los cambios pendientes
        let cambiosPendientes = {};
        
        function prepararActualizacion(festivoId) {
            const fila = document.getElementById('festivo-' + festivoId);
            const fecha = fila.querySelector('[data-field="fecha"]').value;
            const descripcion = fila.querySelector('[data-field="descripcion"]').value;
            
            cambiosPendientes[festivoId] = {
                fecha: fecha,
                descripcion: descripcion
            };
        }
        
        function guardarFestivo(festivoId) {
            if (!cambiosPendientes[festivoId]) {
                alert('No hay cambios pendientes para guardar');
                return;
            }
            
            const datos = cambiosPendientes[festivoId];
            
            // Validaciones básicas
            if (!datos.fecha || !datos.descripcion.trim()) {
                alert('La fecha y descripción son obligatorias');
                return;
            }
            
            if (datos.descripcion.trim().length < 3) {
                alert('La descripción debe tener al menos 3 caracteres');
                return;
            }
            
            // Crear formulario oculto para enviar los datos
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const inputAccion = document.createElement('input');
            inputAccion.type = 'hidden';
            inputAccion.name = 'accion';
            inputAccion.value = 'actualizar';
            form.appendChild(inputAccion);
            
            const inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'id';
            inputId.value = festivoId;
            form.appendChild(inputId);
            
            const inputFecha = document.createElement('input');
            inputFecha.type = 'hidden';
            inputFecha.name = 'fecha';
            inputFecha.value = datos.fecha;
            form.appendChild(inputFecha);
            
            const inputDescripcion = document.createElement('input');
            inputDescripcion.type = 'hidden';
            inputDescripcion.name = 'descripcion';
            inputDescripcion.value = datos.descripcion;
            form.appendChild(inputDescripcion);
            
            document.body.appendChild(form);
            form.submit();
        }
        
        function eliminarFestivo(festivoId) {
            if (confirm('¿Estás seguro de que quieres eliminar este festivo? Esta acción no se puede deshacer.')) {
                // Crear formulario oculto para enviar los datos
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const inputAccion = document.createElement('input');
                inputAccion.type = 'hidden';
                inputAccion.name = 'accion';
                inputAccion.value = 'eliminar';
                form.appendChild(inputAccion);
                
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'id';
                inputId.value = festivoId;
                form.appendChild(inputId);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
    
    <?php
    return ob_get_clean();
}

// Capturar el HTML generado mediante output buffering e invocarlo con los datos preparados.
try {
    $content = renderFestivosContent($festivos_list, $total_festivos, $errors, $success_message, $config_empresa, $anio_seleccionado, $anios_disponibles);

    // Invocar el layout base para construir y enviar la respuesta HTML completa al cliente.
    BaseLayout::render('Gestión de Festivos Anuales ' . $anio_seleccionado, $content, $config_empresa, $user_data);
} catch (Exception $e) {
    error_log("Error rendering festivos page: " . $e->getMessage());
    echo "Error loading page. Please check the logs.";
}
?>
 