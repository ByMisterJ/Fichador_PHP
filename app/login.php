<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir el modelo Trabajador para la autenticación por PIN y la gestión de sesiones.
require_once __DIR__ . '/../shared/models/Trabajador.php';

// Obtener la configuración de marca (branding) de la empresa para personalizar la interfaz de login.
$config_empresa = [];
if (class_exists('SubdomainRouter') && SubdomainRouter::isCompanyContext()) {
    try {
        // Obtener la información de la empresa a través del enrutador de subdominios.
        $company = SubdomainRouter::getCurrentCompany();
        if ($company) {
            // Cargar la configuración completa de la empresa desde la base de datos.
            require_once __DIR__ . '/../shared/models/Empresa.php';
            $empresa = new Empresa();
            $config_empresa = $empresa->obtenerConfiguracion(1); // Se usa el ID 1 ya que cada base de datos pertenece a una empresa

            if (!$config_empresa) {
                // Configuración de respaldo con valores mínimos si la consulta a la BD no devuelve resultados.
                $config_empresa = [
                    'nombre_app' => $company['nombre'] ?? 'Fichador',
                    'color_app' => '#3B82F6',
                    'logo_filepath' => null
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error loading company config for login: " . $e->getMessage());
        $config_empresa = [
            'nombre_app' => 'Fichador',
            'color_app' => '#3B82F6',
            'logo_filepath' => null
        ];
    }
} else {
    // Configuración por defecto si no se está en un contexto de subdominio de empresa.
    $config_empresa = [
        'nombre_app' => 'Fichador',
        'color_app' => '#3B82F6',
        'logo_filepath' => null
    ];
}

// Si el trabajador ya dispone de una sesión autenticada activa, redirigir directamente al dashboard.
if (Trabajador::estaLogueado()) {
    header('Location: /app/dashboard.php');
    exit();
}

$mensaje_error = '';

// Procesar el envío del formulario de autenticación (método HTTP POST).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = trim($_POST['pin'] ?? '');

    // Instanciar el modelo Trabajador para ejecutar la lógica de autenticación.
    $trabajador = new Trabajador();

    // Validar el formato del PIN antes de realizar la consulta a la base de datos.
    if (!$trabajador->validarPin($pin)) {
        $mensaje_error = 'El PIN debe tener exactamente 4 números.';
    } else {
        try {
            // Autenticar al trabajador verificando el PIN contra los registros de la base de datos.
            $datosTrabajador = $trabajador->autenticarPorPin($pin);

            if ($datosTrabajador) {
                // Establecer las variables de sesión del trabajador autenticado.
                $trabajador->establecerSesionTrabajador($datosTrabajador, $pin);

                // Redirigir al panel de control tras la autenticación exitosa.
                header('Location: /app/dashboard.php');
                exit();
            } else {
                $mensaje_error = 'PIN incorrecto o trabajador inactivo. Inténtalo de nuevo.';
            }

        } catch (Exception $e) {
            $mensaje_error = 'Error de conexión. Inténtalo más tarde.';
            // Registrar el error en el log del servidor para facilitar la depuración.
            error_log("Error en login: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config_empresa['nombre_app'] ?? 'Fichador'); ?> - Login</title>
    
    <!-- Favicon -->
    <?php 
    require_once __DIR__ . '/../shared/templates/header.php';
    echo generateFaviconLinks($config_empresa); 
    ?>
    
    <script src="../assets/js/tailwind.min.js"></script>
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet">
    <?php
    // Obtener los colores corporativos de la empresa para generar las variables CSS dinámicas del login.
    $primary_color = $config_empresa['color_app'] ?? '#3B82F6';
    $secondary_color = adjustBrightness($primary_color, -20); // Versión más oscura
    $accent_color = adjustBrightness($primary_color, 20); // Versión más clara
    
    // Función auxiliar para ajustar el brillo de un color hexadecimal dado un porcentaje.
    function adjustBrightness($hex, $percent)
    {
        $hex = str_replace('#', '', $hex);
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = max(0, min(255, $r + ($r * $percent / 100)));
        $g = max(0, min(255, $g + ($g * $percent / 100)));
        $b = max(0, min(255, $b + ($b * $percent / 100)));

        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }

    if (!function_exists('hex2rgb')) {
        function hex2rgb($hex)
        {
            $hex = str_replace('#', '', $hex);
            return [
                hexdec(substr($hex, 0, 2)),
                hexdec(substr($hex, 2, 2)),
                hexdec(substr($hex, 4, 2))
            ];
        }
    }

    $rgb = hex2rgb($primary_color);
    ?>

    <style>
        :root {
            --primary-color:
                <?php echo $primary_color; ?>
            ;
            --primary-rgb:
                <?php echo implode(', ', $rgb); ?>
            ;
        }

        .bg-primary-gradient {
            background: linear-gradient(135deg,
                    <?php echo $primary_color; ?>
                    ,
                    <?php echo $secondary_color; ?>
                );
        }

        .text-primary-custom {
            color:
                <?php echo $primary_color; ?>
            ;
        }

        .bg-primary-custom {
            background-color:
                <?php echo $primary_color; ?>
            ;
        }

        .border-primary-custom {
            border-color:
                <?php echo $primary_color; ?>
            ;
        }

        .ring-primary-custom {
            --tw-ring-color: rgba(<?php echo implode(', ', $rgb); ?>, 0.3);
        }
    </style>
</head>

<body class="bg-primary-gradient min-h-screen flex items-center justify-center p-4">
    <!-- Background Pattern -->
    <!-- <div class="absolute inset-0 opacity-10">
        <div class="absolute inset-0" style="background-image: url('data:image/svg+xml,<svg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"><g fill="none" fill-rule="evenodd"><g fill="%23ffffff" fill-opacity="0.1"><circle cx="30" cy="30" r="2"/></g></g></svg>');"></div>
    </div> -->

    <!-- Login Card -->
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm relative z-10 overflow-hidden">
        <!-- RFID Badge -->
        <div class="absolute top-4 right-4 z-10">
            <a href="/app/rfid.php"
                class="inline-flex items-center bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-800 px-3 py-2 rounded-full text-xs font-medium transition-all duration-200 shadow-sm hover:shadow-md">
                <i class="fas fa-wifi mr-1.5 text-xs"></i>
                <span>RFID</span>
            </a>
        </div>

        <!-- Header with Logo -->
        <div class="p-8 pt-12 text-center">
            <!-- Company Logo/Icon -->
            <div class="flex items-center justify-center mb-6">
                <?php
                // Usar el helper de ficheros para resolver la URL pública del logotipo de la empresa.
                require_once __DIR__ . '/../shared/utils/FileHelper.php';
                $logo_filepath = $config_empresa['logo_filepath'] ?? null;
                $logo_url = null;

                if (!empty($logo_filepath)) {
                    $logo_url = FileHelper::getFileUrl($logo_filepath);

                    // Verificar la existencia física del archivo; si no existe, no renderizar la imagen.
                    if (!FileHelper::fileExists($logo_filepath)) {
                        $logo_url = null;
                    }
                }

                $app_name = $config_empresa['nombre_app'] ?? 'Fichador';
                ?>

                <?php if (!empty($logo_url)): ?>
                    <!-- Company Logo -->
                    <div class="w-16 h-16 mr-4 rounded-xl flex items-center justify-center overflow-hidden bg-gray-50">
                        <img src="<?php echo htmlspecialchars($logo_url); ?>"
                            alt="<?php echo htmlspecialchars($app_name); ?>" class="max-h-full max-w-full object-contain">
                    </div>
                <?php else: ?>
                    <!-- Default Icon -->
                    <div class="w-16 h-16 mr-4 bg-primary-custom rounded-xl flex items-center justify-center">
                        <i class="fas fa-clock text-white text-2xl"></i>
                    </div>
                <?php endif; ?>

                <div class="text-left">
                    <h1 class="font-bold text-2xl text-primary-custom"><?php echo htmlspecialchars($app_name); ?></h1>
                    <p class="text-sm text-gray-600 mt-1">Sistema de Fichaje</p>
                </div>
            </div>
        </div>

        <!-- PIN Form -->
        <div class="px-8 pb-8">
            <?php if ($mensaje_error): ?>
                <div class="mb-6 p-3 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center justify-center">
                        <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
                        <span class="text-red-700 text-sm"><?php echo htmlspecialchars($mensaje_error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-6">
                <!-- PIN Field -->
                <div>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input type="password" id="pin" name="pin" maxlength="4"
                            class="block w-full pl-12 pr-4 py-4 border border-gray-300 rounded-xl focus:ring-2 ring-primary-custom focus:border-primary-custom transition-colors text-center text-lg font-mono tracking-widest"
                            placeholder="PIN" required inputmode="numeric" pattern="[0-9]{4}">
                    </div>
                </div>

                <!-- Login Button -->
                <button type="submit"
                    class="w-full bg-primary-custom text-white py-4 px-4 rounded-xl font-semibold hover:opacity-90 focus:ring-4 ring-primary-custom transition-all transform hover:scale-[1.02] active:scale-[0.98] text-lg"
                    style="background-color: <?php echo $primary_color; ?>;">
                    Acceder
                </button>
            </form>
        </div>

        <!-- Footer -->
        <!-- <div class="px-8 py-4 bg-gray-50 text-center">
            <a href="/index.php" class="text-xs text-gray-500 hover:text-gray-700 transition-colors">
                <i class="fas fa-arrow-left mr-1"></i>
                Volver al inicio
            </a>
            </div>
        </div> -->
    </div>

    <!-- JavaScript -->
    <script>
        // Enfocar automáticamente el campo de PIN al cargar la página para mejorar la experiencia de usuario (UX).
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('pin').focus();
        });

        // Filtrar la entrada del campo PIN para permitir únicamente caracteres numéricos y enviar el formulario automáticamente.
        document.getElementById('pin').addEventListener('input', function (e) {
            this.value = this.value.replace(/[^0-9]/g, '');

            // Enviar el formulario automáticamente cuando se hayan introducido los 4 dígitos del PIN.
            if (this.value.length === 4) {
                // Pequeño retardo para que el usuario pueda ver el 4.º dígito antes del envío automático.
                setTimeout(() => {
                    this.form.submit();
                }, 300);
            }
        });

        // Cancelar el evento de tecla si el carácter introducido no es numérico para bloquear caracteres no permitidos.
        document.getElementById('pin').addEventListener('keypress', function (e) {
            if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'Tab', 'Enter'].includes(e.key)) {
                e.preventDefault();
            }
        });

        // Mostrar el estado de carga en el botón de envío para proporcionar feedback visual durante la autenticación.
        document.querySelector('form').addEventListener('submit', function () {
            const button = document.querySelector('button[type="submit"]');
            const pin = document.getElementById('pin').value;

            if (pin.length === 4) {
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Verificando...';
                button.disabled = true;
            }
        });

        // Actualizar los estilos del campo PIN dinámicamente según el número de dígitos introducidos.
        document.getElementById('pin').addEventListener('input', function () {
            const length = this.value.length;
            if (length > 0) {
                this.classList.add('border-primary', 'ring-2', 'ring-blue-200');
                this.classList.remove('border-gray-300');
            } else {
                this.classList.remove('border-primary', 'ring-2', 'ring-blue-200');
                this.classList.add('border-gray-300');
            }
        });
    </script>
</body>

</html>