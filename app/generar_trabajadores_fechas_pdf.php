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
require_once __DIR__ . '/../shared/models/Informes.php';
require_once __DIR__ . '/../shared/utils/PdfUtil.php';
require_once __DIR__ . '/../config/database.php';

// Datos del usuario
$empresa_id = $_SESSION['empresa_id'] ?? null;
if (!$empresa_id) {
    header('Location: /app/login.php');
    exit;
}

// Obtener configuración de la empresa
$config_empresa = Trabajador::obtenerConfiguracionEmpresa();

// Obtener parámetros del informe
$filtros = [
    'tipo' => $_GET['tipo'] ?? 'trabajadores_fechas',
    'formato' => $_GET['formato'] ?? 'PDF',
    'hora_desde' => $_GET['hora_desde'] ?? null,
    'hora_hasta' => $_GET['hora_hasta'] ?? null,
    'fecha_desde' => $_GET['fecha_desde'] ?? '',
    'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
    'empleados' => !empty($_GET['empleados']) ? explode(',', $_GET['empleados']) : [],
    'incluir_dias_libres' => ($_GET['incluir_dias_libres'] ?? '0') === '1',
    'mostrar_horas_extra' => ($_GET['mostrar_horas_extra'] ?? '0') === '1',
    'mostrar_horas_nocturnas' => ($_GET['mostrar_horas_nocturnas'] ?? '0') === '1'
];

// Validar parámetros
if (empty($filtros['fecha_desde']) || empty($filtros['fecha_hasta']) || empty($filtros['empleados'])) {
    header('Location: informe-trabajadores-fechas.php?error=parametros_invalidos');
    exit;
}

// Generar informe según formato
$informes = new Informes();

try {
    if ($filtros['formato'] === 'CSV') {
        // Generar CSV
        require_once __DIR__ . '/../shared/utils/CsvUtil.php';
        
        $datos_csv = $informes->generarDatosCsvTrabajadoresFechas($empresa_id, $filtros);
        
        if (empty($datos_csv)) {
            header('Location: informe-trabajadores-fechas.php?error=sin_datos');
            exit;
        }
        
        // Definir columnas base
        $columnas_base = ['Nombre', 'DNI', 'Fecha', 'Inicio', 'Fin', 'Tiempo Trabajado', 'Anotaciones'];

        // Agregar columnas opcionales según filtros
        $columnas_finales = $columnas_base;

        if ($filtros['mostrar_horas_extra']) {
            $columnas_finales[] = 'Horas Extras';
        }

        if ($filtros['incluir_dias_libres']) {
            $columnas_finales[] = 'Día libre / Vacaciones / Ausencias';
        }

        if ($filtros['mostrar_horas_nocturnas']) {
            $columnas_finales[] = 'Horas Nocturnas';
        }
        
        // Filtrar datos para incluir solo las columnas seleccionadas
        $csv_util = new CsvUtil();
        $datos_filtrados = $csv_util->filterColumns($datos_csv, $columnas_finales);
        
        // Generar nombre del archivo
        $nombre_archivo = 'informe_trabajadores_fechas_' . date('Y-m-d_H-i-s') . '.csv';
        
        // Generar y descargar CSV
        $csv_util->generateCsv($datos_filtrados, $columnas_finales, $nombre_archivo);
        
    } elseif ($filtros['formato'] === 'EXCEL') {
        // Generar Excel
        require_once __DIR__ . '/../shared/utils/ExcelUtil.php';
        
        // Reutilizar el mismo método que CSV para obtener datos a nivel de sesión
        $datos_excel = $informes->generarDatosCsvTrabajadoresFechas($empresa_id, $filtros);
        
        if (empty($datos_excel)) {
            header('Location: informe-trabajadores-fechas.php?error=sin_datos');
            exit;
        }
        
        // Definir columnas base
        $columnas_base = ['Nombre', 'DNI', 'Fecha', 'Inicio', 'Fin', 'Tiempo Trabajado', 'Anotaciones'];

        // Agregar columnas opcionales según filtros
        $columnas_finales = $columnas_base;

        if ($filtros['mostrar_horas_extra']) {
            $columnas_finales[] = 'Horas Extras';
        }

        if ($filtros['incluir_dias_libres']) {
            $columnas_finales[] = 'Día libre / Vacaciones / Ausencias';
        }

        if ($filtros['mostrar_horas_nocturnas']) {
            $columnas_finales[] = 'Horas Nocturnas';
        }
        
        // Filtrar datos para incluir solo las columnas seleccionadas
        $excel_util = new ExcelUtil();
        $datos_filtrados = $excel_util->filterColumns($datos_excel, $columnas_finales);
        
        // Generar nombre del archivo
        $nombre_archivo = 'informe_trabajadores_fechas_' . date('Y-m-d_H-i-s') . '.xlsx';
        
        // Generar y descargar Excel
        $excel_util->generateExcel($datos_filtrados, $columnas_finales, $nombre_archivo);
        
    } else {
        // Generar PDF (comportamiento original)
        $datos_informe = $informes->generarInformeTrabajadoresFechas($empresa_id, $filtros);
        
        if (empty($datos_informe)) {
            header('Location: informe-trabajadores-fechas.php?error=sin_datos');
            exit;
        }
        
        $pdf_util = new PdfUtil($config_empresa);
        
        // Generar nombre del archivo
        $nombre_archivo = 'informe_trabajadores_fechas_' . date('Y-m-d_H-i-s') . '.pdf';
        
        // Generar y enviar PDF
        $pdf_util->generateTrabajadoresFechasReport($datos_informe, $filtros, $nombre_archivo);
    }
    
} catch (Exception $e) {
    error_log("Error generando informe: " . $e->getMessage());
    
    // Determinar el parámetro de error según el formato
    $error_param = 'error_pdf'; // Default
    if ($filtros['formato'] === 'CSV') {
        $error_param = 'error_csv';
    } elseif ($filtros['formato'] === 'EXCEL') {
        $error_param = 'error_excel';
    }
    
    header('Location: informe-trabajadores-fechas.php?' . $error_param);
    exit;
}
?> 