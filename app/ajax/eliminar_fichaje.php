<?php
// Initialize app context
require_once __DIR__ . '/../../shared/utils/app_init.php';

require_once __DIR__ . '/../../shared/models/Fichajes.php';
require_once __DIR__ . '/../../shared/models/Trabajador.php';

// Configurar headers para respuesta JSON
header('Content-Type: application/json');

// Verificar método de petición
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

// Verificar autenticación
if (!Trabajador::estaLogueado()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Verificar permisos (solo administradores y supervisores)
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sin permisos para esta acción']);
    exit();
}

try {
    // Obtener datos del trabajador desde la sesión
    $trabajador_id = $_SESSION['id_trabajador'];
    $empresa_id = $_SESSION['empresa_id'];

    // Validar que existan los datos necesarios
    if (!$trabajador_id || !$empresa_id) {
        throw new Exception('Datos de sesión incompletos');
    }

    // Obtener datos de la petición
    $input = json_decode(file_get_contents('php://input'), true);
    $fichaje_id = $input['fichaje_id'] ?? null;

    // Validar datos requeridos
    if (!$fichaje_id || !is_numeric($fichaje_id)) {
        throw new Exception('ID de fichaje inválido');
    }

    // Obtener centro del supervisor para control de acceso
    $centro_id_supervisor = null;
    if (strtolower($rol_trabajador) === 'supervisor') {
        require_once __DIR__ . '/../../config/database.php';
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT centro_id FROM trabajador WHERE id = ?");
        $stmt->execute([$trabajador_id]);
        $supervisor_data = $stmt->fetch();
        $centro_id_supervisor = $supervisor_data['centro_id'] ?? null;
    }

    // Crear instancia del modelo Fichajes
    $fichajes = new Fichajes();

    // Eliminar fichaje
    $resultado = $fichajes->eliminarFichaje(
        (int)$fichaje_id,
        $trabajador_id,
        $empresa_id,
        $centro_id_supervisor
    );

    if ($resultado['success']) {
        echo json_encode([
            'success' => true,
            'message' => $resultado['message'],
            'fichaje_id' => $fichaje_id
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $resultado['message']
        ]);
    }

} catch (Exception $e) {
    // Log del error para debugging
    error_log("Error en ajax/eliminar_fichaje.php: " . $e->getMessage());

    // Respuesta de error
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage()
    ]);
}
?>