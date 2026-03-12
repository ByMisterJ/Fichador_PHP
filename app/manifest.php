<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';
require_once __DIR__ . '/../shared/models/Empresa.php';
header('Content-Type: application/json');

// Default manifest values
$default_manifest = [
    "name" => "Fichador Digital",
    "short_name" => "Fichador",
    "start_url" => "/",
    "scope" => "/",
    "display" => "standalone",
    "orientation" => "any",
    "background_color" => "#ffa500",
    "theme_color" => "#ffa500",
    "description" => "Aplicación de fichaje digital para empresas",
    "categories" => ["business", "productivity"],
    "screenshots" => [
        [
            "src" => "../uploads/screenshots/desktop.png",
            "sizes" => "1280x720",
            "type" => "image/png",
            "form_factor" => "wide",
            "label" => "Dashboard principal"
        ],
        [
            "src" => "../uploads/screenshots/mobile.png", 
            "sizes" => "640x1136",
            "type" => "image/png",
            "form_factor" => "narrow",
            "label" => "Vista móvil"
        ]
    ],
    "icons" => []
];

// Get empresa info from subdomain context
$empresa_id = null;

// Method 1: Get from session (most reliable in subdomain context)
if (isset($_SESSION['empresa_id'])) {
    $empresa_id = (int)$_SESSION['empresa_id'];
}

// Method 2: URL parameter fallback
if (!$empresa_id && isset($_GET['empresa_id']) && is_numeric($_GET['empresa_id'])) {
    $empresa_id = (int)$_GET['empresa_id'];
}

// Method 3: Get from SubdomainRouter if available
if (!$empresa_id && class_exists('SubdomainRouter') && SubdomainRouter::isCompanyContext()) {
    $company = SubdomainRouter::getCurrentCompany();
    if ($company && isset($company['id'])) {
        $empresa_id = (int)$company['id'];
    }
}

// If we have empresa_id, get company data
if ($empresa_id) {
    try {
        $empresa = new Empresa();
        $config_empresa = $empresa->obtenerConfiguracion($empresa_id);
        
        if ($config_empresa) {
            $manifest = $default_manifest;
            
            // Update manifest with company data
            if (!empty($config_empresa['nombre'])) {
                $manifest['name'] = $config_empresa['nombre'];
            }
            if (!empty($config_empresa['nombre_app'])) {
                $manifest['short_name'] = $config_empresa['nombre_app'];
            }
            if (!empty($config_empresa['color_app'])) {
                $manifest['background_color'] = $config_empresa['color_app'];
                $manifest['theme_color'] = $config_empresa['color_app'];
            }
            
            // Get manifest icons
            $manifest_icons = $empresa->getManifestIcons($empresa_id);
            
            if (!empty($manifest_icons)) {
                foreach ($manifest_icons as &$icon) {
                    $icon['purpose'] = 'any';
                }
                $manifest['icons'] = $manifest_icons;
            }
            
            echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit();
        }
    } catch (Exception $e) {
        error_log("Manifest error: " . $e->getMessage());
        error_log("Manifest error trace: " . $e->getTraceAsString());
    }
}

// Fallback to default manifest
echo json_encode($default_manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit();
?>
