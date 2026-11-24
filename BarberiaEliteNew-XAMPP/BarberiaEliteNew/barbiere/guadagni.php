<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/staff.php';
requireAuth('Barbiere');

$id_barbiere = staffBarberId($conn);

$mensili=$conn->prepare("
    SELECT DATE_FORMAT(p.data_ora,'%Y-%m') AS mese,
           DATE_FORMAT(p.data_ora,'%m/%Y') AS mese_label,
           COUNT(*) AS n_servizi,
           SUM(dp.prezzo_applicato) AS totale
    FROM PRENOTAZIONE p
    JOIN DETTAGLIO_PRENOTAZIONE dp ON p.id_prenotazione=dp.id_prenotazione
    WHERE p.id_barbiere=? AND p.stato='Completata'
      AND p.data_ora >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01')
    GROUP BY DATE_FORMAT(p.data_ora,'%Y-%m'), DATE_FORMAT(p.data_ora,'%m/%Y')
    ORDER BY mese DESC
");
$mensili->bind_param("i",$id_barbiere); $mensili->execute();
$dati_mensili=$mensili->get_result()->fetch_all(MYSQLI_ASSOC);
$mensili->close();

$tot=$conn->prepare("
    SELECT COALESCE(SUM(dp.prezzo_applicato),0) tot, COUNT(*) n
    FROM PRENOTAZIONE p
    JOIN DETTAGLIO_PRENOTAZIONE dp ON p.id_prenotazione=dp.id_prenotazione
    WHERE p.id_barbiere=? AND p.stato='Completata'
");
$tot->bind_param("i",$id_barbiere); $tot->execute();
$totali=$tot->get_result()->fetch_assoc();
$tot->close();

$top_servizi=$conn->prepare("
    SELECT s.nome_servizio, COUNT(*) n, SUM(dp.prezzo_applicato) totale
    FROM PRENOTAZIONE p
    JOIN DETTAGLIO_PRENOTAZIONE dp ON p.id_prenotazione=dp.id_prenotazione
    JOIN SERVIZIO s ON dp.id_servizio=s.id_servizio
    WHERE p.id_barbiere=? AND p.stato='Completata'
    GROUP BY s.id_servizio, s.nome_servizio ORDER BY n DESC, s.nome_servizio LIMIT 5
");
$top_servizi->bind_param("i",$id_barbiere); $top_servizi->execute();
$top=$top_servizi->get_result()->fetch_all(MYSQLI_ASSOC);
$top_servizi->close();

require __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <span class="section-subtitle">Area barbiere</span>
        <h1 class="section-main-title">I tuoi guadagni</h1>
        <div class="title-gold-line"></div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon">💰</div>
            <div class="kpi-data"><h3><?= staffMoney($totali['tot']) ?></h3><p>TOTALE STORICO</p></div>
            <div class="card-accent-line"></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon">✂️</div>
            <div class="kpi-data"><h3><?= $totali['n'] ?></h3><p>SERVIZI EROGATI</p></div>
            <div class="card-accent-line"></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon">📊</div>
            <div class="kpi-data">
                <h3><?= staffMoney($totali['n'] > 0 ? $totali['tot'] / $totali['n'] : 0) ?></h3>
                <p>MEDIA PER SERVIZIO</p>
            </div>
            <div class="card-accent-line"></div>
        </div>
    </div>

    <div class="staff-split">
        <!-- Andamento mensile -->
        <div class="luxury-data-card">
            <div class="card-data-header">
                <div><h2>ANDAMENTO MENSILE</h2><p>Ultimi 6 mesi di attività completata.</p></div>
            </div>
            <div class="table-responsive-wrapper" tabindex="0" role="region" aria-label="Tabella scorrevole">
                <table class="luxury-table">
                    <thead><tr><th scope="col">MESE</th><th scope="col">SERVIZI</th><th scope="col">GUADAGNO</th></tr></thead>
                    <tbody>
                    <?php if (empty($dati_mensili)): ?>
                    <tr><td colspan="3" class="empty-td">Nessun dato disponibile.</td></tr>
                    <?php else: ?>
                    <?php foreach ($dati_mensili as $m): ?>
                    <tr class="table-row-interactive">
                        <td><?= $m['mese_label'] ?></td>
                        <td><?= $m['n_servizi'] ?></td>
                        <td class="gold-text-highlight"><?= staffMoney($m['totale']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="luxury-data-card">
            <div class="card-data-header">
                <div><h2>SERVIZI PIÙ RICHIESTI</h2><p>I trattamenti che hai erogato di più.</p></div>
            </div>
            <div class="table-responsive-wrapper" tabindex="0" role="region" aria-label="Tabella scorrevole">
                <table class="luxury-table">
                    <thead><tr><th scope="col">SERVIZIO</th><th scope="col">VOLTE</th><th scope="col">TOTALE</th></tr></thead>
                    <tbody>
                    <?php if (empty($top)): ?>
                    <tr><td colspan="3" class="empty-td">Nessun dato.</td></tr>
                    <?php else: ?>
                    <?php foreach ($top as $t): ?>
                    <tr class="table-row-interactive">
                        <td class="gold-text-highlight"><?= htmlspecialchars($t['nome_servizio']) ?></td>
                        <td><?= $t['n'] ?></td>
                        <td><?= staffMoney($t['totale']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>