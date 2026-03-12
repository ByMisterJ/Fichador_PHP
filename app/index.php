<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir el modelo Trabajador para comprobar el estado de autenticación.
require_once __DIR__ . '/../shared/models/Trabajador.php';

// Comprobar si el usuario ya dispone de una sesión autenticada activa.
if (Trabajador::estaLogueado()) {
    // Si la sesión es válida, redirigir al panel de control (dashboard).
    header('Location: /app/dashboard.php');
} else {
    // Si no existe sesión activa, redirigir al formulario de autenticación por PIN.
    header('Location: /app/login.php');
}
exit;
?>