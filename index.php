<?php
require_once 'config/config.php';
require_once 'includes/auth.php';

// Redirect to appropriate dashboard if logged in
if (Auth::isLoggedIn()) {
    $role = $_SESSION['role'];
    header("Location: $role/dashboard.php");
    exit();
}

// Otherwise redirect to login
header('Location: login.php');
exit();
?>
