<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../../shared/utils/app_init.php';

require_once __DIR__ . '/../../shared/models/Trabajador.php';
require_once __DIR__ . '/../../shared/models/GruposHorarios.php';

// Establecer la cabecera Content-Type para indicar al cliente que la respuesta será en formato JSON.
header('Content-Type: application/json');

// Verificar que el usuario dispone de una sesión autenticada válida antes de procesar la petición.
if (!Trabajador::estaLogueado()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Verificar que el rol del usuario autoriza la operación: solo administradores y supervisores pueden eliminar grupos horarios.
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sin permisos suficientes']);
    exit;
}

// Restringir el endpoint únicamente a peticiones HTTP POST para seguir el principio REST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

// Deserializar el cuerpo de la petición JSON y validar los parámetros requeridos.
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['grupo_id']) || !is_numeric($input['grupo_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de grupo horario inválido']);
    exit;
}

$grupo_id = (int) $input['grupo_id'];
$empresa_id = $_SESSION['empresa_id'] ?? null;

if (!$empresa_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de empresa no válido']);
    exit;
}

try {
    $gruposHorarios = new GruposHorarios();

    // Determinar la acción a ejecutar según el parámetro 'action' recibido en el cuerpo JSON.
    $action = $input['action'] ?? 'delete';

    if ($action === 'check') {
        // Verificar si el grupo horario puede eliminarse sin dejar empleados sin horario asignado.
        $resultado = $gruposHorarios->verificarEliminacion($grupo_id, $empresa_id);
        echo json_encode($resultado);

    } elseif ($action === 'delete') {
        // Ejecutar la eliminación del grupo horario en la base de datos.
        $resultado = $gruposHorarios->eliminarGrupoHorario($grupo_id, $empresa_id);

        if ($resultado['success']) {
            http_response_code(200);
        } else {
            http_response_code(400);
        }

        echo json_encode($resultado);

    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }

} catch (Exception $e) {
    error_log("Error in ajax_delete_grupo_horario.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor'
    ]);
}
?>