<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Incluir la clase Trabajador para usar el método de cerrar sesión
require_once __DIR__ . '/../shared/models/Trabajador.php';

// Cerrar sesión usando el método de la clase Trabajador
Trabajador::cerrarSesion();

// Redirigir al login
header('Location: /app/login.php');
exit();
?> 