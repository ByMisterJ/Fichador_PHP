<?php
// Inicializar la aplicación: arrancar la sesión PHP, resolver el subdominio y cargar la configuración global.
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir el modelo Trabajador para acceder al método de cierre de sesión.
require_once __DIR__ . '/../shared/models/Trabajador.php';

// Destruir la sesión activa del trabajador y limpiar los datos de autenticación.
Trabajador::cerrarSesion();

// Redirigir al formulario de autenticación tras el cierre de sesión.
header('Location: /app/login.php');
exit();
?> 