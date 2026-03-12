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
        error_log("Error loading company config for RFID login: " . $e->getMessage());
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

// Manejar el envío del formulario de RFID
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rfid = trim($_POST['rfid'] ?? '');

    // Crear instancia de la clase Trabajador
    $trabajador = new Trabajador();

    // Validación básica
    if (empty($rfid)) {
        $mensaje_error = 'Tarjeta RFID no detectada.';
    } else {
        try {
            // Autenticar trabajador por RFID
            $datosTrabajador = $trabajador->autenticarPorRfid($rfid);

            if ($datosTrabajador) {
                // Establecer sesión del trabajador
                $trabajador->establecerSesionTrabajador($datosTrabajador, null); // No PIN for RFID

                // Redirigir al dashboard
                header('Location: /app/dashboard.php');
                exit();
            } else {
                $mensaje_error = 'Tarjeta RFID no reconocida o trabajador inactivo.';
            }

        } catch (Exception $e) {
            $mensaje_error = 'Error de conexión. Inténtalo más tarde.';
            // Log del error para debugging
            error_log("Error en RFID login: " . $e->getMessage());
        }
    }
}

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
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config_empresa['nombre_app'] ?? 'Fichador'); ?> - RFID</title>

    <!-- Favicon -->
    <?php
    require_once __DIR__ . '/../shared/templates/header.php';
    echo generateFaviconLinks($config_empresa);
    ?>

    <script src="../assets/js/tailwind.min.js"></script>
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet">

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

        /* RFID specific styles */
        .rfid-waiting {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }
        }

        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid white;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .overlay {
            transition: all 0.3s ease;
        }

        .overlay.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .overlay.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
    </style>
</head>

<body class="bg-primary-gradient min-h-screen flex items-center justify-center p-4"
    onclick="document.getElementById('rfidInput').focus()">

    <!-- Main RFID Card -->
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md relative z-10 overflow-hidden">
        <!-- RFID Badge -->
        <div class="absolute top-4 right-4 bg-gray-100 text-gray-600 px-3 py-1 rounded-full text-xs font-medium">
            RFID
        </div>

        <!-- Header with Logo -->
        <div class="p-8 text-center">
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
                    <p class="text-sm text-gray-600 mt-1">Sistema de Fichaje RFID</p>
                </div>
            </div>
        </div>

        <!-- Waiting State -->
        <div id="waitingState" class="px-8 pb-8 text-center">
            <div class="rfid-waiting mb-6">
                <div class="w-24 h-24 mx-auto bg-primary-custom rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-wifi text-white text-3xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-800 mb-2">Esperando llavero...</h2>
                <p class="text-gray-600">Acerque su llavero al lector para acceder</p>
            </div>

            <!-- Hidden RFID Input -->
            <input type="text" id="rfidInput" name="rfid" autocomplete="off" autofocus
                class="absolute opacity-0 -top-1000">

            <!-- Hidden Form -->
            <form method="POST" id="rfidForm" style="display: none;">
                <input type="hidden" id="rfidHidden" name="rfid">
            </form>
        </div>

        <!-- Footer -->
        <div class="px-8 py-4 bg-gray-50 text-center">
            <a href="/app/login.php" class="text-xs text-gray-500 hover:text-gray-700 transition-colors mr-4">
                <i class="fas fa-keyboard mr-1"></i>
                Usar PIN
            </a>
            <a href="/index.php" class="text-xs text-gray-500 hover:text-gray-700 transition-colors">
                <i class="fas fa-arrow-left mr-1"></i>
                Volver al inicio
            </a>
        </div>
    </div>

    <!-- Overlay for feedback -->
    <div id="overlay" class="fixed inset-0 z-50 flex items-center justify-center text-white text-center p-8 overlay"
        style="display: none;">
        <div class="max-w-md">
            <div id="overlayIcon" class="text-6xl mb-4"></div>
            <div id="overlayMessage" class="text-4xl font-bold mb-2"></div>
            <div id="overlayDetail" class="text-xl mb-4"></div>
            <div id="spinner" class="spinner mx-auto" style="display: none;"></div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const rfidInput = document.getElementById('rfidInput');
            const rfidForm = document.getElementById('rfidForm');
            const rfidHidden = document.getElementById('rfidHidden');
            const overlay = document.getElementById('overlay');
            const overlayIcon = document.getElementById('overlayIcon');
            const overlayMessage = document.getElementById('overlayMessage');
            const overlayDetail = document.getElementById('overlayDetail');
            const spinner = document.getElementById('spinner');
            const waitingState = document.getElementById('waitingState');

            // Keep focus on RFID input
            rfidInput.focus();
            setInterval(() => {
                if (document.activeElement !== rfidInput) {
                    rfidInput.focus();
                }
            }, 500);

            // Handle RFID input
            rfidInput.addEventListener('input', function () {
                const val = rfidInput.value.trim();

                // Process when we have at least 5 characters (adjust as needed)
                if (val.length >= 5) {
                    processRFID(val);
                }
            });

            function processRFID(rfidValue) {
                // Show processing overlay
                showOverlay('processing', '<i class="fas fa-spinner fa-spin"></i>', 'Procesando...', 'Verificando tarjeta RFID');

                // Set form data and submit
                rfidHidden.value = rfidValue;

                // Submit form after a short delay to show the processing state
                setTimeout(() => {
                    rfidForm.submit();
                }, 500);
            }

            function showOverlay(type, icon, message, detail) {
                overlayIcon.innerHTML = icon;
                overlayMessage.textContent = message;
                overlayDetail.textContent = detail;

                // Remove previous classes
                overlay.classList.remove('success', 'error');

                // Add appropriate class
                if (type === 'success') {
                    overlay.classList.add('success');
                } else if (type === 'error') {
                    overlay.classList.add('error');
                }

                overlay.style.display = 'flex';
                waitingState.style.display = 'none';
            }

            function hideOverlay() {
                overlay.style.display = 'none';
                waitingState.style.display = 'block';
                rfidInput.value = '';
                rfidInput.focus();
            }

            // Handle PHP error messages
            <?php if (!empty($mensaje_error)): ?>
                showOverlay('error', '<i class="fas fa-exclamation-triangle"></i>', 'Error', '<?php echo addslashes($mensaje_error); ?>');
                setTimeout(hideOverlay, 3000);
            <?php endif; ?>

            // Prevent form submission on Enter key
            rfidInput.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });

            // Handle clicks to maintain focus
            document.addEventListener('click', function () {
                rfidInput.focus();
            });
        });
    </script>
</body>

</html>