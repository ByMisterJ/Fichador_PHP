<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Verificar autenticación
require_once __DIR__ . '/../shared/models/Trabajador.php';
if (!Trabajador::estaLogueado()) {
    header('Location: /app/login.php');
    exit;
}

// Verificar permisos (solo administrador y supervisor)
$rol_trabajador = $_SESSION['rol_trabajador'] ?? 'Empleado';
if (!in_array(strtolower($rol_trabajador), ['administrador', 'supervisor'])) {
    header('Location: /app/dashboard.php');
    exit;
}

// Includes necesarios
require_once __DIR__ . '/../shared/models/Fichajes.php';
require_once __DIR__ . '/../shared/utils/PdfUtil.php';
require_once __DIR__ . '/../shared/utils/CsvUtil.php';
require_once __DIR__ . '/../shared/utils/ExcelUtil.php';
require_once __DIR__ . '/../config/database.php';

// Datos del usuario
$empresa_id = $_SESSION['empresa_id'] ?? null;
$trabajador_id = $_SESSION['id_trabajador'] ?? null;

if (!$empresa_id) {
    header('Location: /app/login.php');
    exit;
}

// Obtener configuración de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Obtener centro del supervisor para control de acceso
$centro_id_supervisor = null;
if (strtolower($rol_trabajador) === 'supervisor') {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT centro_id FROM trabajador WHERE id = ?");
    $stmt->execute([$trabajador_id]);
    $supervisor_data = $stmt->fetch();
    $centro_id_supervisor = $supervisor_data['centro_id'] ?? null;
}

// Obtener parámetros del informe
$filtros = [
    'hora_desde' => $_GET['hora_desde'] ?? '',
    'hora_hasta' => $_GET['hora_hasta'] ?? '',
    'fecha_desde' => $_GET['fecha_desde'] ?? '',
    'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
    'trabajadores' => [],
    'solo_incidencias' => $_GET['solo_incidencias'] ?? '',
    'tipo_incidencia' => $_GET['tipo_incidencia'] ?? '',
    'formato' => $_GET['formato'] ?? 'PDF',
    'mostrar_eliminados' => false // Always exclude eliminated fichajes from exports
];

// Procesar trabajadores (pueden venir como array o string separada por comas)
if (isset($_GET['trabajadores'])) {
    if (is_array($_GET['trabajadores'])) {
        $filtros['trabajadores'] = array_map('intval', array_filter($_GET['trabajadores']));
    } else {
        // Si viene como string separada por comas desde JavaScript
        $trabajadores_str = $_GET['trabajadores'];
        if (!empty($trabajadores_str)) {
            $filtros['trabajadores'] = array_map('intval', array_filter(explode(',', $trabajadores_str)));
        }
    }
}

// No validar parámetros - permitir exportación sin filtros (consulta por defecto)

// Generar datos de exportación
try {
    $fichajes = new Fichajes();
    $fichajes_list = $fichajes->obtenerFichajesPorEmpresa($empresa_id, $filtros, $centro_id_supervisor);

    if (empty($fichajes_list)) {
        header('Location: fichajes.php?error=sin_datos');
        exit;
    }

    // Transformar datos para exportación
    $datos_exportacion = transformarDatosParaExportacion($fichajes_list);

    // Definir columnas para formato de sesión individual
    $columnas = ['Empleado', 'DNI', 'Fecha', 'Entrada', 'Salida', 'Duración', 'Estado', 'Anotaciones', 'Incidencias'];

    // Generar nombre del archivo
    $nombre_archivo = generarNombreArchivo($filtros);

    // Generar según formato
    if ($filtros['formato'] === 'CSV') {
        $csv_util = new CsvUtil();
        $csv_util->generateCsv($datos_exportacion, $columnas, $nombre_archivo);

    } elseif ($filtros['formato'] === 'EXCEL') {
        $excel_util = new ExcelUtil();
        $excel_util->generateExcel($datos_exportacion, $columnas, $nombre_archivo);

    } else {
        // PDF por defecto
        $pdf_util = new PdfUtil($config_empresa);

        // Preparar información de filtros para el header
        $filters_info = [];
        if (!empty($filtros['hora_desde']) && !empty($filtros['hora_hasta'])) {
            $filters_info[] = "Horario: {$filtros['hora_desde']} - {$filtros['hora_hasta']}";
        }
        if (!empty($filtros['trabajadores'])) {
            $filters_info[] = "Empleados seleccionados: " . count($filtros['trabajadores']);
        }
        if ($filtros['solo_incidencias'] === '1') {
            $filters_info[] = "Solo registros con incidencias";
        }
        if (!empty($filtros['tipo_incidencia'])) {
            $tipos_incidencia = [
                'incidencia_zona_gps' => 'Fuera de zona GPS',
                'incidencia_gps_desactivado' => 'GPS desactivado',
                'incidencia_horario_fijo' => 'Fuera de horario',
                'incidencia_sin_horario' => 'Sin horario',
                'incidencia_horas_extra_ventana' => 'Horas extra fuera de ventana'
            ];
            $tipo_desc = $tipos_incidencia[$filtros['tipo_incidencia']] ?? $filtros['tipo_incidencia'];
            $filters_info[] = "Tipo de incidencia: {$tipo_desc}";
        }

        // Configurar PDF
        $config_pdf = [
            'title' => 'Informe de Fichajes',
            'fecha_desde' => $filtros['fecha_desde'],
            'fecha_hasta' => $filtros['fecha_hasta'],
            'headers' => $columnas,
            'rows' => array_map('array_values', $datos_exportacion),
            'filters_info' => !empty($filters_info) ? $filters_info : null,
            'filename' => $nombre_archivo
        ];

        $pdf_util->generateReport($config_pdf);
    }

} catch (Exception $e) {
    error_log("Error generando exportación de fichajes: " . $e->getMessage());

    // Determinar el parámetro de error según el formato
    $error_param = 'error_pdf'; // Default
    if ($filtros['formato'] === 'CSV') {
        $error_param = 'error_csv';
    } elseif ($filtros['formato'] === 'EXCEL') {
        $error_param = 'error_excel';
    }

    header('Location: fichajes.php?' . $error_param);
    exit;
}

/**
 * Transformar datos de fichajes para exportación (una fila por sesión)
 */
function transformarDatosParaExportacion($fichajes_list)
{
    $datos_transformados = [];

    foreach ($fichajes_list as $fichaje) {
        // Si no hay sesiones, crear una fila indicando que no hay datos
        if (empty($fichaje['sesiones'])) {
            $fila = [
                'Empleado' => $fichaje['nombre_completo'],
                'DNI' => $fichaje['dni'],
                'Fecha' => $fichaje['fecha_formateada'],
                'Entrada' => '-',
                'Salida' => '-',
                'Duración' => '-',
                'Estado' => 'Sin sesiones',
                'Anotaciones' => '-',
                'Incidencias' => formatearIncidenciasGeneralesParaExportacion($fichaje)
            ];
            $datos_transformados[] = $fila;
        } else {
            // Crear una fila por cada sesión
            foreach ($fichaje['sesiones'] as $index => $sesion) {
                $anotacion = !empty($sesion['anotacion_trabajador']) ? $sesion['anotacion_trabajador'] : '-';

                // Para PDF, dividir anotaciones largas en múltiples líneas
                if ($anotacion !== '-' && strlen($anotacion) > 40) {
                    $anotacion = wordwrap($anotacion, 40, "\n", true);
                }

                $fila = [
                    'Empleado' => $fichaje['nombre_completo'],
                    'DNI' => $fichaje['dni'],
                    'Fecha' => $fichaje['fecha_formateada'],
                    'Entrada' => $sesion['hora_inicio_sesion'],
                    'Salida' => $sesion['hora_fin_sesion'] ?: '--:--',
                    'Duración' => formatearDuracionSesion($sesion['segundos_sesion'] ?? 0),
                    'Estado' => ucfirst($sesion['estado']),
                    'Anotaciones' => $anotacion,
                    'Incidencias' => formatearIncidenciasSesionParaExportacion($fichaje, $index)
                ];
                $datos_transformados[] = $fila;
            }
        }
    }

    return $datos_transformados;
}

/**
 * Formatear duración de una sesión
 */
function formatearDuracionSesion($segundos)
{
    if ($segundos <= 0)
        return '00:00';

    $horas = floor($segundos / 3600);
    $minutos = floor(($segundos % 3600) / 60);

    return sprintf('%02d:%02d', $horas, $minutos);
}

/**
 * Formatear incidencias generales para exportación (días sin sesiones)
 */
function formatearIncidenciasGeneralesParaExportacion($fichaje)
{
    $incidencias_texto = [];

    // Solo incidencias generales del día
    if (!empty($fichaje['incidencias'])) {
        foreach ($fichaje['incidencias'] as $incidencia) {
            $descripcion = obtenerDescripcionIncidencia($incidencia);
            if ($descripcion) {
                $incidencias_texto[] = $descripcion;
            }
        }
    }

    return empty($incidencias_texto) ? 'Sin incidencias' : implode("\n", $incidencias_texto);
}

/**
 * Formatear incidencias de una sesión específica para exportación
 */
function formatearIncidenciasSesionParaExportacion($fichaje, $index_sesion)
{
    $incidencias_texto = [];

    // Incidencias generales del día (aplican a todas las sesiones)
    if (!empty($fichaje['incidencias'])) {
        $incidencias_generales = array_intersect($fichaje['incidencias'], ['incidencia_sin_horario', 'incidencia_horas_extra_ventana']);
        foreach ($incidencias_generales as $incidencia) {
            $descripcion = obtenerDescripcionIncidencia($incidencia);
            if ($descripcion) {
                $incidencias_texto[] = $descripcion;
            }
        }
    }

    // Incidencias específicas de esta sesión
    if (!empty($fichaje['sesiones_incidencias'][$index_sesion]['incidencias'])) {
        foreach ($fichaje['sesiones_incidencias'][$index_sesion]['incidencias'] as $incidencia) {
            $descripcion = obtenerDescripcionIncidencia($incidencia);
            if ($descripcion) {
                $incidencias_texto[] = $descripcion;
            }
        }
    }

    return empty($incidencias_texto) ? 'Sin incidencias' : implode("\n", $incidencias_texto);
}

/**
 * Obtener descripción legible de una incidencia
 */
function obtenerDescripcionIncidencia($incidencia)
{
    $descripciones = [
        'incidencia_zona_gps' => 'Fuera de zona GPS',
        'incidencia_gps_desactivado' => 'GPS desactivado',
        'incidencia_horario_fijo' => 'Fuera de horario establecido',
        'incidencia_sin_horario' => 'Sin horario establecido',
        'incidencia_horas_extra_ventana' => 'Horas extra fuera de ventana'
    ];

    return $descripciones[$incidencia] ?? $incidencia;
}

/**
 * Generar nombre del archivo basado en filtros
 */
function generarNombreArchivo($filtros)
{
    $nombre_base = 'fichajes';
    $partes = [];

    // Agregar fechas si están definidas
    if (!empty($filtros['fecha_desde']) && !empty($filtros['fecha_hasta'])) {
        $partes[] = $filtros['fecha_desde'] . '_' . $filtros['fecha_hasta'];
    } elseif (!empty($filtros['fecha_desde'])) {
        $partes[] = 'desde_' . $filtros['fecha_desde'];
    } elseif (!empty($filtros['fecha_hasta'])) {
        $partes[] = 'hasta_' . $filtros['fecha_hasta'];
    }

    // Agregar indicador de empleados específicos
    if (!empty($filtros['trabajadores'])) {
        $partes[] = 'empleados';
    }

    // Agregar indicador de solo incidencias
    if ($filtros['solo_incidencias'] === '1') {
        $partes[] = 'incidencias';
    }

    // Agregar tipo de incidencia específico
    if (!empty($filtros['tipo_incidencia'])) {
        $partes[] = 'tipo_' . $filtros['tipo_incidencia'];
    }

    // Construir nombre final
    if (!empty($partes)) {
        $nombre_base .= '_' . implode('_', $partes);
    }

    // Agregar timestamp
    $nombre_base .= '_' . date('Y-m-d_H-i-s');

    // Agregar extensión según formato
    $extension = '.pdf';
    if ($filtros['formato'] === 'CSV') {
        $extension = '.csv';
    } elseif ($filtros['formato'] === 'EXCEL') {
        $extension = '.xlsx';
    }

    return $nombre_base . $extension;
}
?>