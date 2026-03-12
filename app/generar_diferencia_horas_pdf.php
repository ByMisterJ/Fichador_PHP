<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir las clases necesarias
require_once __DIR__ . '/../shared/models/Trabajador.php';
require_once __DIR__ . '/../shared/models/Informes.php';
require_once __DIR__ . '/../shared/utils/PdfUtil.php';
require_once __DIR__ . '/../config/database.php';

// Verificar autenticación
if (!Trabajador::estaLogueado()) {
    http_response_code(401);
    echo 'No autorizado';
    exit;
}

// Verificar permisos (solo admin y supervisor)
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    http_response_code(403);
    echo 'Sin permisos suficientes';
    exit;
}

// Obtener datos de la sesión
$empresa_id = $_SESSION['empresa_id'] ?? null;
$centro_id_supervisor = (strtolower($rol_trabajador) === 'supervisor') ? ($_SESSION['centro_id'] ?? null) : null;

// Obtener configuración de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Procesar parámetros GET
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$centro_id = !empty($_GET['centro']) ? (int) $_GET['centro'] : null;
$grupo_horario_id = !empty($_GET['grupo_horario']) ? (int) $_GET['grupo_horario'] : null;

// Procesar trabajadores (pueden venir como array o string separada por comas)
$trabajadores = [];
if (isset($_GET['trabajadores'])) {
    if (is_array($_GET['trabajadores'])) {
        $trabajadores = array_map('intval', array_filter($_GET['trabajadores']));
    } else {
        // Si viene como string separada por comas
        $trabajadores_str = $_GET['trabajadores'];
        if (!empty($trabajadores_str)) {
            $trabajadores = array_map('intval', array_filter(explode(',', $trabajadores_str)));
        }
    }
}

// Validar parámetros obligatorios
if (empty($fecha_desde) || empty($fecha_hasta)) {
    http_response_code(400);
    echo 'Fechas de inicio y fin son obligatorias';
    exit;
}

// Validar formato de fechas
if (!DateTime::createFromFormat('Y-m-d', $fecha_desde) || !DateTime::createFromFormat('Y-m-d', $fecha_hasta)) {
    http_response_code(400);
    echo 'Formato de fecha inválido';
    exit;
}

try {
    // Inicializar clase de informes
    $informes = new Informes();

    // Preparar filtros
    $filtros = [
        'fecha_desde' => $fecha_desde,
        'fecha_hasta' => $fecha_hasta,
        'centro_id' => $centro_id,
        'trabajadores' => $trabajadores,
        'grupo_horario_id' => $grupo_horario_id
    ];

    // Aplicar filtro de supervisor si corresponde
    if ($centro_id_supervisor !== null && empty($filtros['centro_id'])) {
        $filtros['centro_id'] = $centro_id_supervisor;
    }

    // Generar datos del informe
    $resultados_informe = $informes->calcularDiferenciaHoras($empresa_id, $filtros);

    if (empty($resultados_informe)) {
        http_response_code(404);
        echo 'No se encontraron datos para los filtros especificados';
        exit;
    }

    // Preparar datos para el PDF
    $headers = [
        'Empleado',
        'DNI',
        'Horas Totales',
        'Horas Nocturnas',
        'Diferencia'
    ];

    $rows = [];
    foreach ($resultados_informe as $resultado) {
        $rows[] = [
            $resultado['nombre_completo'],
            $resultado['dni'],
            $resultado['total_horas'], // Horas Totales (worked + justified)
            '00:00', // Horas Nocturnas (placeholder - not calculated yet)
            $resultado['diferencia']
        ];
    }

    // Generar PDF
    $pdfUtil = new PdfUtil($config_empresa);

    // Usar el método limpio para obtener información de filtros
    $filters_info = $pdfUtil->getFilterInfoFromModels($filtros, $empresa_id);

    $config_pdf = [
        'title' => 'Informe - Diferencia de Horas',
        'fecha_desde' => $fecha_desde,
        'fecha_hasta' => $fecha_hasta,
        'headers' => $headers,
        'rows' => $rows,
        'filters_info' => $filters_info,
        'filename' => 'informe_diferencia_horas_' . date('Y-m-d_H-i-s') . '.pdf'
    ];

    // Generate and output PDF directly (this will force download)
    $pdfUtil->generateReport($config_pdf);

} catch (Exception $e) {
    error_log("Error al generar PDF: " . $e->getMessage());
    http_response_code(500);
    echo 'Error interno del servidor al generar el PDF';
}
?>