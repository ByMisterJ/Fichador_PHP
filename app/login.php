<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir la clase Trabajador
require_once __DIR__ . '/../shared/models/Trabajador.php';

// Get company configuration for branding
$config_empresa = [];
if (class_exists('SubdomainRouter') && SubdomainRouter::isCompanyContext()) {
    try {
        // Get company info from router
        $company = SubdomainRouter::getCurrentCompany();
        if ($company) {
            // Get full configuration from database
            require_once __DIR__ . '/../shared/models/Empresa.php';
            $empresa = new Empresa();
            $config_empresa = $empresa->obtenerConfiguracion(1); // Use ID 1 as it's the company in each database

            if (!$config_empresa) {
                // Fallback to basic company info
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
    // Default configuration
    $config_empresa = [
        'nombre_app' => 'Fichador',
        'color_app' => '#3B82F6',
        'logo_filepath' => null
    ];
}

// Si el trabajador ya está logueado, redirigir al dashboard
if (Trabajador::estaLogueado()) {
    header('Location: /app/dashboard.php');
    exit();
}

$mensaje_error = '';

// Manejar el envío del formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = trim($_POST['pin'] ?? '');

    // Crear instancia de la clase Trabajador
    $trabajador = new Trabajador();

    // Validación básica
    if (!$trabajador->validarPin($pin)) {
        $mensaje_error = 'El PIN debe tener exactamente 4 números.';
    } else {
        try {
            // Autenticar trabajador por PIN
            $datosTrabajador = $trabajador->autenticarPorPin($pin);

            if ($datosTrabajador) {
                // Establecer sesión del trabajador
                $trabajador->establecerSesionTrabajador($datosTrabajador, $pin);

                // Redirigir al dashboard
                header('Location: /app/dashboard.php');
                exit();
            } else {
                $mensaje_error = 'PIN incorrecto o trabajador inactivo. Inténtalo de nuevo.';
            }

        } catch (Exception $e) {
            $mensaje_error = 'Error de conexión. Inténtalo más tarde.';
            // Log del error para debugging
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
    // Get company colors
    $primary_color = $config_empresa['color_app'] ?? '#3B82F6';
    $secondary_color = adjustBrightness($primary_color, -20); // Darker version
    $accent_color = adjustBrightness($primary_color, 20); // Lighter version
    
    // Function to adjust color brightness
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
                // Use FileHelper for logo handling
                require_once __DIR__ . '/../shared/utils/FileHelper.php';
                $logo_filepath = $config_empresa['logo_filepath'] ?? null;
                $logo_url = null;

                if (!empty($logo_filepath)) {
                    $logo_url = FileHelper::getFileUrl($logo_filepath);

                    // Check if file actually exists, if not, don't show logo
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
        // Auto-focus on PIN field
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('pin').focus();
        });

        // Only allow numbers in PIN field
        document.getElementById('pin').addEventListener('input', function (e) {
            this.value = this.value.replace(/[^0-9]/g, '');

            // Auto-submit when 4 digits are entered
            if (this.value.length === 4) {
                // Small delay to show the 4th digit
                setTimeout(() => {
                    this.form.submit();
                }, 300);
            }
        });

        // Prevent non-numeric input
        document.getElementById('pin').addEventListener('keypress', function (e) {
            if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'Tab', 'Enter'].includes(e.key)) {
                e.preventDefault();
            }
        });

        // Add loading state to button on submit
        document.querySelector('form').addEventListener('submit', function () {
            const button = document.querySelector('button[type="submit"]');
            const pin = document.getElementById('pin').value;

            if (pin.length === 4) {
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Verificando...';
                button.disabled = true;
            }
        });

        // Add visual feedback for PIN input
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