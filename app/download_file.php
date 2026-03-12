<?php
/**
 * Secure file download endpoint for JSON-stored files
 * Checks user permissions before serving files stored in JSON fields
 */

// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Verify authentication
require_once __DIR__ . '/../shared/models/Trabajador.php';
if (!Trabajador::estaLogueado()) {
    http_response_code(401);
    die('No autorizado');
}

// Get parameters from URL
$table = $_GET['table'] ?? '';
$field = $_GET['field'] ?? '';
$entity_id = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;
$file_index = isset($_GET['file_index']) ? (int)$_GET['file_index'] : 0;

if (!$table || !$field || !$entity_id) {
    http_response_code(400);
    die('Parámetros inválidos');
}

try {
    // Include required files
    require_once __DIR__ . '/../config/database.php';
    
    // Get session data
    $empresa_id = $_SESSION['empresa_id'] ?? null;
    $trabajador_id = $_SESSION['id_trabajador'] ?? null;
    $rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
    
    if (!$empresa_id) {
        http_response_code(400);
        die('Empresa no encontrada');
    }
    
    // Get files from database using simplified approach
    require_once __DIR__ . '/../shared/utils/FileHelper.php';
    
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT {$field} FROM {$table} WHERE id = ? AND empresa_id = ?");
        $stmt->execute([$entity_id, $empresa_id]);
        $result = $stmt->fetch();
        
        if (!$result || empty($result[$field])) {
            http_response_code(404);
            die('Archivo no encontrado');
        }
        
        $jsonData = json_decode($result[$field], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(404);
            die('Error al leer información del archivo');
        }
        
        // Handle both single file and array formats
        $single_file = ($table === 'empresa' && $field === 'logo_info');
        $files = $single_file ? [$jsonData] : $jsonData;
        
    } catch (Exception $e) {
        error_log("Error getting file data: " . $e->getMessage());
        http_response_code(500);
        die('Error interno del servidor');
    }
    
    if (empty($files) || !isset($files[$file_index])) {
        http_response_code(404);
        die('Archivo no encontrado');
    }
    
    $file = $files[$file_index];
    
    // Check permissions based on context
    $can_access = false;
    $rol_lower = strtolower($rol_trabajador);
    
    if ($table === 'empresa' && $field === 'logo_info') {
        // Company logos - everyone can view
        $can_access = true;
    } elseif ($table === 'vacaciones' && $field === 'justificantes') {
        // Vacation justificantes - check ownership and permissions
        if ($rol_lower === 'administrador') {
            $can_access = true;
        } elseif ($rol_lower === 'supervisor') {
            // Supervisors can access files from their centro
            require_once __DIR__ . '/../shared/models/Vacaciones.php';
            $vacaciones = new Vacaciones();
            $vacation = $vacaciones->obtenerVacacionConCentro($entity_id, $empresa_id);
            
            if ($vacation) {
                $trabajador = new Trabajador();
                $centro_id_supervisor = $trabajador->obtenerCentroIdTrabajador($trabajador_id);
                $can_access = ($centro_id_supervisor && $centro_id_supervisor == $vacation['centro_id']);
            }
        } elseif ($rol_lower === 'empleado') {
            // Employees can only access their own vacation files
            require_once __DIR__ . '/../shared/models/Vacaciones.php';
            $vacaciones = new Vacaciones();
            $vacation = $vacaciones->obtenerVacacionPorId($entity_id, $empresa_id);
            
            if ($vacation) {
                $can_access = ($vacation['trabajador_id'] == $trabajador_id);
            }
        }
    }
    
    if (!$can_access) {
        http_response_code(403);
        die('No tienes permisos para acceder a este archivo');
    }
    
    // Check if file exists physically
    $file_path = $file['filepath'] ?? '';
    if (!$file_path || !file_exists($file_path)) {
        http_response_code(404);
        die('Archivo físico no encontrado');
    }
    
    // Serve the file
    $file_name = $file['original_filename'] ?? basename($file_path);
    $file_size = filesize($file_path);
    $mime_type = $file['mime_type'] ?? 'application/octet-stream';
    
    // Set headers for file download
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . addslashes($file_name) . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, must-revalidate');
    header('Pragma: private');
    header('Expires: 0');
    
    // Output file content
    readfile($file_path);
    
} catch (Exception $e) {
    error_log("Error downloading JSON file: " . $e->getMessage());
    http_response_code(500);
    die('Error interno del servidor');
}
?> 