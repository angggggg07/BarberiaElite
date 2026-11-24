<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

requireAuth();
switch ($_SESSION['ruolo']) {
    case 'Admin':
        redirect('admin/index.php');
    case 'Barbiere':
        redirect('barbiere/index.php');
    case 'Cliente':
        redirect('cliente/index.php');
    default:
        $_SESSION = [];
        redirect('index.php');
}
