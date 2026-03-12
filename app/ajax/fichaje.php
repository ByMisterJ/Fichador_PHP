<?php
// Initialize app context (includes session_start and subdomain routing)
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

    // Validar datos requeridos - mantener compatibilidad con UI existente
    $tipo = $input['tipo'] ?? $_POST['tipo'] ?? null;
    $metodo = $input['metodo'] ?? $_POST['metodo'] ?? 'pin';
    $observaciones = $input['observaciones'] ?? $_POST['observaciones'] ?? null;

    // Validar tipo de fichaje
    if (!in_array($tipo, ['entrada', 'salida'])) {
        throw new Exception('Tipo de fichaje inválido');
    }

    // Procesar anotación de trabajador (opcional)
    $anotacion_trabajador = null;
    if (isset($input['anotacion_trabajador'])) {
        $anotacion_trabajador = $input['anotacion_trabajador'];
    }

    // Obtener dirección IP del cliente
    $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (strpos($ip_address, ',') !== false) {
        $ip_address = trim(explode(',', $ip_address)[0]);
    }

    // Procesar ubicación GPS (opcional)
    $ubicacion = null;
    if (isset($input['ubicacion']) && is_array($input['ubicacion'])) {
        $ubicacion = $input['ubicacion'];
    } elseif (isset($_POST['ubicacion_lat']) && isset($_POST['ubicacion_lng'])) {
        $ubicacion = [
            'lat' => (float) $_POST['ubicacion_lat'],
            'lng' => (float) $_POST['ubicacion_lng']
        ];
    }

    // Validar coordenadas GPS si se proporcionan
    if ($ubicacion) {
        if (!isset($ubicacion['lat']) || !isset($ubicacion['lng'])) {
            throw new Exception('Coordenadas GPS incompletas');
        }

        if (!is_numeric($ubicacion['lat']) || !is_numeric($ubicacion['lng'])) {
            throw new Exception('Coordenadas GPS inválidas');
        }

        // Validar rangos válidos para coordenadas
        if ($ubicacion['lat'] < -90 || $ubicacion['lat'] > 90) {
            throw new Exception('Latitud fuera de rango válido');
        }

        if ($ubicacion['lng'] < -180 || $ubicacion['lng'] > 180) {
            throw new Exception('Longitud fuera de rango válido');
        }
    }

    // Crear instancia del modelo Fichajes
    $fichajes = new Fichajes();

    // Registrar fichaje con el nuevo sistema de sesiones
    $resultado = $fichajes->registrarFichaje(
        $trabajador_id,
        $empresa_id,
        $tipo,
        $ip_address,
        $ubicacion,
        $observaciones,
        $metodo,
		$anotacion_trabajador
    );

    if ($resultado['success']) {
        // Obtener estado actual después del fichaje para enviar información actualizada
        $estado_actual = $fichajes->getEstadoActual($trabajador_id, $empresa_id);

        // Respuesta exitosa
        echo json_encode([
            'success' => true,
            'message' => $resultado['message'],
            'tipo' => $resultado['tipo'],
            'fichaje_id' => $resultado['fichaje_id'],
            'estado_actual' => [
                'tiene_sesion_activa' => $estado_actual['tiene_sesion_activa'],
                'ultimo_fichaje' => $estado_actual['ultimo_fichaje'],
                'estado_actual' => $estado_actual['estado_actual'],
                'es_entrada' => $estado_actual['es_entrada'],
                'tiempo_sesion' => $estado_actual['tiempo_sesion']
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        // Error en el registro
        echo json_encode([
            'success' => false,
            'message' => $resultado['message'],
            'error_code' => 'REGISTRATION_FAILED'
        ]);
    }

} catch (Exception $e) {
    // Log del error para debugging
    error_log("Error en ajax/fichaje.php: " . $e->getMessage());
    error_log("Request data: " . print_r($_POST, true));
    error_log("JSON input: " . file_get_contents('php://input'));

    // Respuesta de error
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage(),
        'error_code' => 'INTERNAL_ERROR'
    ]);
}
?>