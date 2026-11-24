<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/staff.php';
requireAuth('Admin');

$kpi=[];
$kpi['utenti']      =$conn->query("SELECT COUNT(*) c FROM UTENTE")->fetch_assoc()['c'];
$kpi['barbieri']    =$conn->query("SELECT COUNT(*) c FROM BARBIERE b JOIN UTENTE u ON u.id_utente=b.id_utente WHERE b.attivo=1 AND u.stato='Attivo'")->fetch_assoc()['c'];
$kpi['servizi']     =$conn->query("SELECT COUNT(*) c FROM SERVIZIO WHERE attivo=1")->fetch_assoc()['c'];
$kpi['prenotazioni']=$conn->query("SELECT COUNT(*) c FROM PRENOTAZIONE WHERE DATE(data_ora)=CURDATE() AND stato NOT IN ('Annullata','No_Show')")->fetch_assoc()['c'];
$kpi['fatturato']   =$conn->query("SELECT COALESCE(SUM(prezzo_applicato),0) s FROM DETTAGLIO_PRENOTAZIONE dp JOIN PRENOTAZIONE p ON dp.id_prenotazione=p.id_prenotazione WHERE p.stato='Completata' AND MONTH(p.data_ora)=MONTH(CURDATE()) AND YEAR(p.data_ora)=YEAR(CURDATE())")->fetch_assoc()['s'];
$kpi['clienti']     =$conn->query("SELECT COUNT(*) c FROM CLIENTE")->fetch_assoc()['c'];

$ultime=$conn->query("
    SELECT p.id_prenotazione, p.data_ora, p.stato,
           CONCAT(uc.nome, ' ', uc.cognome) AS cliente, CONCAT(ub.nome, ' ', ub.cognome) AS barbiere,
           ds.nome_servizio, ds.prezzo_applicato
    FROM PRENOTAZIONE p
    LEFT JOIN CLIENTE c ON p.id_cliente = c.id_cliente
    LEFT JOIN UTENTE uc ON uc.id_utente = c.id_utente
    LEFT JOIN BARBIERE b ON p.id_barbiere = b.id_barbiere
    LEFT JOIN UTENTE ub ON ub.id_utente = b.id_utente
    LEFT JOIN (" . staffBookingDetailsSql() . ") ds ON ds.id_prenotazione = p.id_prenotazione
    ORDER BY p.id_prenotazione DESC LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

require __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <span class="section-subtitle">Amministrazione</span>
        <h1 class="section-main-title">Panoramica</h1>
        <div class="title-gold-line"></div>
    </div>

    <!-- KPI Grid -->
    <div class="kpi-grid stats-overview">
        <div class="kpi-card">
            <div class="kpi-icon" aria-hidden="true">◎</div>
            <div class="kpi-data"><h3><?= $kpi['utenti'] ?></h3><p>Utenti totali</p></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" aria-hidden="true">✂</div>
            <div class="kpi-data"><h3><?= $kpi['barbieri'] ?></h3><p>Barbieri attivi</p></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" aria-hidden="true">▦</div>
            <div class="kpi-data"><h3><?= $kpi['prenotazioni'] ?></h3><p>Appuntamenti oggi</p></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" aria-hidden="true">€</div>
            <div class="kpi-data"><h3><?= staffMoney($kpi['fatturato']) ?></h3><p>Fatturato del mese</p></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" aria-hidden="true">◇</div>
            <div class="kpi-data"><h3><?= $kpi['servizi'] ?></h3><p>Servizi attivi</p></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" aria-hidden="true">◉</div>
            <div class="kpi-data"><h3><?= $kpi['clienti'] ?></h3><p>Clienti registrati</p></div>
        </div>
    </div>

    <!-- Quick links -->
    <div class="quick-links-grid">
        <a href="utenti.php" class="quick-card">
            <span class="qc-icon" aria-hidden="true">◎</span>
            <span class="qc-label">Gestisci utenti</span>
        </a>
        <a href="barbieri.php" class="quick-card">
            <span class="qc-icon" aria-hidden="true">◇</span>
            <span class="qc-label">Gestisci barbieri</span>
        </a>
        <a href="servizi.php" class="quick-card">
            <span class="qc-icon" aria-hidden="true">✂</span>
            <span class="qc-label">Gestisci servizi</span>
        </a>
        <a href="prenotazioni.php" class="quick-card">
            <span class="qc-icon" aria-hidden="true">▦</span>
            <span class="qc-label">Tutte le prenotazioni</span>
        </a>
    </div>

    <!-- Ultime prenotazioni -->
    <div class="luxury-data-card">
        <div class="card-data-header">
            <div>
                <h2>Ultime prenotazioni</h2>
            </div>
            <a href="prenotazioni.php" class="btn-action-add">Vedi tutte</a>
        </div>
        <div class="table-responsive-wrapper" tabindex="0" role="region" aria-label="Tabella scorrevole">
            <table class="luxury-table">
                <thead><tr>
                    <th scope="col">Rif.</th><th scope="col">Data e ora</th><th scope="col">Cliente</th><th scope="col">Barbiere</th><th scope="col">Servizi</th><th scope="col">Importo</th><th scope="col">Stato</th>
                </tr></thead>
                <tbody>
                <?php if (!$ultime): ?><tr><td colspan="7" class="empty-td">Non ci sono ancora prenotazioni.</td></tr><?php endif; ?>
                <?php foreach ($ultime as $r): ?>
                <tr class="table-row-interactive">
                    <td><span class="id-badge"><?= $r['id_prenotazione'] ?></span></td>
                    <td><?= date('d/m/Y H:i', strtotime($r['data_ora'])) ?></td>
                    <td class="user-fullname"><?= e($r['cliente'] ?? 'Cliente rimosso') ?></td>
                    <td><?= e($r['barbiere'] ?? 'Barbiere rimosso') ?></td>
                    <td class="gold-text-highlight"><?= htmlspecialchars($r['nome_servizio'] ?? '—') ?></td>
                    <td><?= staffMoney($r['prezzo_applicato'] ?? 0) ?></td>
                    <td><?= staffBookingBadge($r['stato']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
