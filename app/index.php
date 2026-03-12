<?php
// Initialize app (session, subdomain routing, etc.)
require_once __DIR__ . '/../shared/utils/app_init.php';

// Include necessary files
require_once __DIR__ . '/../shared/models/Trabajador.php';

// Check if user is already logged in
if (Trabajador::estaLogueado()) {
    // If already logged in, redirect to dashboard
    header('Location: /app/dashboard.php');
} else {
    // If not logged in, redirect to login
    header('Location: /app/login.php');
}
exit;
?>