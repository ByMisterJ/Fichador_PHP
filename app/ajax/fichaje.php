<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
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

// Verificar que el usuario dispone de una sesión autenticada válida antes de procesar la petición.
if (!Trabajador::estaLogueado()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

try {
    // Recuperar los identificadores del trabajador y la empresa desde la superglobal $_SESSION.
    $trabajador_id = $_SESSION['id_trabajador'];
    $empresa_id = $_SESSION['empresa_id'];

    // Validar que los datos de sesión estén completos; lanzar excepción si faltan.
    if (!$trabajador_id || !$empresa_id) {
        throw new Exception('Datos de sesión incompletos');
    }

    // Deserializar el cuerpo de la petición JSON para extraer los parámetros del fichaje.
    $input = json_decode(file_get_contents('php://input'), true);

    // Extraer los parámetros requeridos con soporte dual JSON/POST para mantener compatibilidad con la UI existente.
    $tipo = $input['tipo'] ?? $_POST['tipo'] ?? null;
    $metodo = $input['metodo'] ?? $_POST['metodo'] ?? 'pin';
    $observaciones = $input['observaciones'] ?? $_POST['observaciones'] ?? null;

    // Validar que el tipo de fichaje sea uno de los valores permitidos (entrada o salida).
    if (!in_array($tipo, ['entrada', 'salida'])) {
        throw new Exception('Tipo de fichaje inválido');
    }

    // Extraer la anotación libre del trabajador si fue incluida en la petición (campo opcional).
    $anotacion_trabajador = null;
    if (isset($input['anotacion_trabajador'])) {
        $anotacion_trabajador = $input['anotacion_trabajador'];
    }

    // Resolver la dirección IP del cliente, teniendo en cuenta la posible presencia de un proxy inverso.
    $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (strpos($ip_address, ',') !== false) {
        $ip_address = trim(explode(',', $ip_address)[0]);
    }

    // Extraer y normalizar las coordenadas GPS enviadas en la petición (campo opcional).
    $ubicacion = null;
    if (isset($input['ubicacion']) && is_array($input['ubicacion'])) {
        $ubicacion = $input['ubicacion'];
    } elseif (isset($_POST['ubicacion_lat']) && isset($_POST['ubicacion_lng'])) {
        $ubicacion = [
            'lat' => (float) $_POST['ubicacion_lat'],
            'lng' => (float) $_POST['ubicacion_lng']
        ];
    }

    // Validar que las coordenadas GPS, si se proporcionan, tengan el formato y rango correctos.
    if ($ubicacion) {
        if (!isset($ubicacion['lat']) || !isset($ubicacion['lng'])) {
            throw new Exception('Coordenadas GPS incompletas');
        }

        if (!is_numeric($ubicacion['lat']) || !is_numeric($ubicacion['lng'])) {
            throw new Exception('Coordenadas GPS inválidas');
        }

        // Verificar que la latitud y longitud estén dentro de los rangos geográficos válidos.
        if ($ubicacion['lat'] < -90 || $ubicacion['lat'] > 90) {
            throw new Exception('Latitud fuera de rango válido');
        }

        if ($ubicacion['lng'] < -180 || $ubicacion['lng'] > 180) {
            throw new Exception('Longitud fuera de rango válido');
        }
    }

    // Instanciar el modelo Fichajes para ejecutar el registro del evento de fichaje.
    $fichajes = new Fichajes();

    // Registrar el evento de fichaje en la base de datos usando el sistema de sesiones de trabajo.
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
        // Consultar el estado actual del trabajador tras el fichaje para incluirlo en la respuesta.
        $estado_actual = $fichajes->getEstadoActual($trabajador_id, $empresa_id);

        // Serializar y enviar la respuesta JSON de éxito con el estado actualizado del trabajador.
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
        // Serializar y enviar la respuesta JSON de error con el código de error correspondiente.
        echo json_encode([
            'success' => false,
            'message' => $resultado['message'],
            'error_code' => 'REGISTRATION_FAILED'
        ]);
    }

} catch (Exception $e) {
    // Registrar el error en el log del servidor para facilitar la depuración en producción.
    error_log("Error en ajax/fichaje.php: " . $e->getMessage());
    error_log("Request data: " . print_r($_POST, true));
    error_log("JSON input: " . file_get_contents('php://input'));

    // Devolver la respuesta JSON de error interno al cliente con código HTTP 500.
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage(),
        'error_code' => 'INTERNAL_ERROR'
    ]);
}
?>