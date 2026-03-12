<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Verificar autenticación
require_once __DIR__ . '/../shared/models/Trabajador.php';
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Verificar permisos
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';

// Includes necesarios
require_once __DIR__ . '/../shared/models/Vacaciones.php';
require_once __DIR__ . '/../shared/utils/ExcelUtil.php';
require_once __DIR__ . '/../config/database.php';

// Datos del usuario
$empresa_id = $_SESSION['empresa_id'] ?? null;
$trabajador_id = $_SESSION['id_trabajador'] ?? null;

if (!$empresa_id) {
    header('Location: /app/login.php');
    exit;
}

// Obtener conexión a la base de datos
$pdo = getDbConnection();

// Obtener centro del supervisor para control de acceso
$centro_id_supervisor = null;
if (strtolower($rol_trabajador) === 'supervisor') {
    $stmt = $pdo->prepare("SELECT centro_id FROM trabajador WHERE id = ?");
    $stmt->execute([$trabajador_id]);
    $supervisor_data = $stmt->fetch();
    $centro_id_supervisor = $supervisor_data['centro_id'] ?? null;
}

// Obtener parámetros del informe
$filtros = [
    'fecha_inicio' => $_GET['fecha_inicio'] ?? '',
    'fecha_fin' => $_GET['fecha_fin'] ?? '',
    'trabajadores' => [],
    'centro_id' => $_GET['centro_id'] ?? '',
    'estado' => $_GET['estado'] ?? '',
    'motivo' => $_GET['motivo'] ?? ''
];

// Procesar trabajadores (pueden venir como array)
if (isset($_GET['trabajadores']) && is_array($_GET['trabajadores'])) {
    $filtros['trabajadores'] = array_map('intval', array_filter($_GET['trabajadores']));
}

// Para empleados, filtrar solo sus propias vacaciones
if (strtolower($rol_trabajador) === 'empleado') {
    $filtros['trabajador_id'] = $trabajador_id;
}

// Generar datos de exportación
try {
    $vacaciones = new Vacaciones();
    $vacaciones_list = $vacaciones->obtenerVacacionesPorEmpresa($empresa_id, $filtros, $centro_id_supervisor);

    if (empty($vacaciones_list)) {
        header('Location: vacaciones.php?error=sin_datos');
        exit;
    }

    // Transformar datos para exportación
    $datos_exportacion = transformarDatosParaExportacion($vacaciones_list);

    // Definir columnas
    $columnas = ['Nombre', 'DNI', 'Fecha Inicio', 'Fecha Fin', 'Días', 'Motivo', 'Estado'];

    // Generar nombre del archivo
    $nombre_archivo = generarNombreArchivo($filtros);

    // Generar Excel
    $excel_util = new ExcelUtil();
    $excel_util->generateExcel($datos_exportacion, $columnas, $nombre_archivo);

} catch (Exception $e) {
    error_log("Error generando exportación de vacaciones: " . $e->getMessage());
    header('Location: vacaciones.php?error=error_excel');
    exit;
}

/**
 * Transformar datos de vacaciones para exportación
 */
function transformarDatosParaExportacion($vacaciones_list)
{
    $datos_transformados = [];

    // Mapeo de motivos
    $motivos_map = [
        'vacaciones' => 'Vacaciones',
        'permiso' => 'Permiso',
        'baja' => 'Baja médica',
        'asuntos_propios' => 'Asuntos propios',
        'otro' => 'Otro'
    ];

    // Mapeo de estados
    $estados_map = [
        'pendiente' => 'Pendiente',
        'aprobada' => 'Aprobada',
        'rechazada' => 'Rechazada',
        'cancelada' => 'Cancelada'
    ];

    foreach ($vacaciones_list as $vacacion) {
        $fila = [
            'Nombre' => $vacacion['nombre_completo'] ?? '-',
            'DNI' => $vacacion['dni'] ?? '-',
            'Fecha Inicio' => !empty($vacacion['fecha_inicio']) ? date('d/m/Y', strtotime($vacacion['fecha_inicio'])) : '-',
            'Fecha Fin' => !empty($vacacion['fecha_fin']) ? date('d/m/Y', strtotime($vacacion['fecha_fin'])) : '-',
            'Días' => $vacacion['dias_solicitados'] ?? '0',
            'Motivo' => $motivos_map[$vacacion['motivo']] ?? $vacacion['motivo'],
            'Estado' => $estados_map[$vacacion['estado']] ?? $vacacion['estado']
        ];
        $datos_transformados[] = $fila;
    }

    return $datos_transformados;
}

/**
 * Generar nombre del archivo basado en filtros
 */
function generarNombreArchivo($filtros)
{
    $nombre_base = 'vacaciones';
    $partes = [];

    // Agregar fechas si están definidas
    if (!empty($filtros['fecha_inicio']) && !empty($filtros['fecha_fin'])) {
        $partes[] = $filtros['fecha_inicio'] . '_' . $filtros['fecha_fin'];
    } elseif (!empty($filtros['fecha_inicio'])) {
        $partes[] = 'desde_' . $filtros['fecha_inicio'];
    } elseif (!empty($filtros['fecha_fin'])) {
        $partes[] = 'hasta_' . $filtros['fecha_fin'];
    }

    // Agregar indicador de estado
    if (!empty($filtros['estado'])) {
        $partes[] = $filtros['estado'];
    }

    // Agregar indicador de motivo
    if (!empty($filtros['motivo'])) {
        $partes[] = $filtros['motivo'];
    }

    // Construir nombre final
    if (!empty($partes)) {
        $nombre_base .= '_' . implode('_', $partes);
    }

    // Agregar timestamp
    $nombre_base .= '_' . date('Y-m-d_H-i-s');

    return $nombre_base . '.xlsx';
}
?>
