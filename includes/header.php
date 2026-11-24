<?php
$role = $_SESSION['ruolo'] ?? '';
$links = [
    'Admin' => [
        ['admin/index.php', 'Panoramica', '⌂'],
        ['admin/prenotazioni.php', 'Prenotazioni', '▦'],
        ['admin/barbieri.php', 'Barbieri', '✂︎'],
        ['admin/servizi.php', 'Servizi', '◇'],
        ['admin/utenti.php', 'Utenti', '◎'],
    ],
    'Barbiere' => [
        ['barbiere/index.php', 'Agenda', '▦'],
        ['barbiere/orari.php', 'Orari', '◷'],
        ['barbiere/guadagni.php', 'Guadagni', '€'],
    ],
    'Cliente' => [
        ['cliente/index.php', 'Panoramica', '⌂'],
        ['cliente/nuova-prenotazione.php', 'Prenota', '✂︎'],
        ['cliente/prenotazioni.php', 'Appuntamenti', '▦'],
        ['cliente/recensioni.php', 'Recensioni', '☆'],
        ['cliente/profilo.php', 'Profilo', '◎'],
    ],
][$role] ?? [];
$isDashboard = $links !== [];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$title = $pageTitle ?? 'Barberia Elite | Gestionale';
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#172722">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e(appUrl('assets/css/style.css')) ?>">
    <script src="<?= e(appUrl('assets/js/main.js')) ?>" defer></script>
</head>
<body class="<?= e($isDashboard ? 'dashboard-shell' : ($pageClass ?? 'public-page')) ?>">
<a class="skip-link" href="#main-content">Vai al contenuto</a>
<?php if ($isDashboard): ?>
    <aside class="workspace-sidebar">
        <a class="brand" href="<?= e(appUrl('dashboard.php')) ?>">Barberia <em>Elite.</em></a>
        <div class="workspace-nav">
            <p class="workspace-label"><?= e(['Admin' => 'Amministrazione', 'Barbiere' => 'Area barbiere', 'Cliente' => 'Area cliente'][$role]) ?></p>
            <nav aria-label="Navigazione del gestionale">
                <?php foreach ($links as [$destination, $label, $symbol]): ?>
                    <?php $active = $path === appUrl($destination); ?>
                    <a class="workspace-link<?= $active ? ' is-active' : '' ?>" href="<?= e(appUrl($destination)) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                        <span aria-hidden="true" class="workspace-symbol"><?= e($symbol) ?></span>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <form class="workspace-logout" method="post" action="<?= e(appUrl('logout.php')) ?>">
            <?= csrfField() ?>
            <button type="submit">Esci</button>
        </form>
    </aside>
    <div class="workspace-bar">
        <span>Gestionale</span>
        <span class="workspace-user">
            <span aria-hidden="true" class="workspace-avatar"><?= e(mb_strtoupper(mb_substr($_SESSION['nome'] ?? '', 0, 1))) ?></span>
            <?= e(trim(($_SESSION['nome'] ?? '') . ' ' . ($_SESSION['cognome'] ?? ''))) ?>
        </span>
    </div>
<?php else: ?>
    <header class="public-header">
        <div class="public-width public-bar">
            <a class="brand" href="<?= e(appUrl('index.php')) ?>">Barberia <em>Elite.</em></a>
            <nav aria-label="Navigazione principale">
                <a href="<?= e(appUrl('index.php#listino')) ?>">Servizi</a>
                <a href="<?= e(appUrl('index.php#sede')) ?>">Dove siamo</a>
                <a class="public-access" href="<?= e(appUrl('index.php#accesso')) ?>">Accedi</a>
            </nav>
        </div>
    </header>
<?php endif; ?>
<main id="main-content" class="main-content-wrapper" tabindex="-1">
