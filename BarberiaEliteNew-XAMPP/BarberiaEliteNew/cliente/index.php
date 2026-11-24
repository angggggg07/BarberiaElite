<?php
require_once __DIR__ . '/../config.php';
requireAuth('Cliente');

$idUtente = (int) $_SESSION['id_utente'];
$oraLocale = date('Y-m-d H:i:s');
$stmt = $conn->prepare("
    SELECT p.id_prenotazione, p.data_ora, p.stato,
           CONCAT(ub.nome, ' ', ub.cognome) AS barbiere,
           GROUP_CONCAT(s.nome_servizio ORDER BY dp.id_dettaglio SEPARATOR ', ') AS servizi,
           SUM(dp.prezzo_applicato) AS totale
    FROM PRENOTAZIONE p
    JOIN CLIENTE c ON p.id_cliente = c.id_cliente
    LEFT JOIN BARBIERE b ON p.id_barbiere = b.id_barbiere
    LEFT JOIN UTENTE ub ON b.id_utente = ub.id_utente
    LEFT JOIN DETTAGLIO_PRENOTAZIONE dp ON dp.id_prenotazione = p.id_prenotazione
    LEFT JOIN SERVIZIO s ON dp.id_servizio = s.id_servizio
    WHERE c.id_utente = ? AND p.data_ora > ? AND p.stato IN ('Prenotato', 'Confermata')
    GROUP BY p.id_prenotazione, p.data_ora, p.stato, ub.nome, ub.cognome
    ORDER BY p.data_ora, p.id_prenotazione LIMIT 1
");
$stmt->bind_param('is', $idUtente, $oraLocale);
$stmt->execute();
$prossima = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) AS tot, COALESCE(SUM(p.stato = 'Completata'), 0) AS completate
    FROM PRENOTAZIONE p JOIN CLIENTE c ON p.id_cliente = c.id_cliente
    WHERE c.id_utente = ?
");
$stmt->bind_param('i', $idUtente);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$servizi = $conn->query("
    SELECT s.id_servizio, s.nome_servizio, s.descrizione, s.prezzo, s.durata_minuti, cs.nome_categoria
    FROM SERVIZIO s
    LEFT JOIN CATEGORIA_SERVIZIO cs ON s.id_categoria = cs.id_categoria
    WHERE s.attivo = 1
    ORDER BY s.prezzo, s.nome_servizio LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

$valutazioni = $conn->query("
    SELECT AVG(valutazione) AS media, COUNT(*) AS totale
    FROM RECENSIONE WHERE valutazione BETWEEN 1 AND 5
")->fetch_assoc();

$recensioniRecenti = $conn->query("
    SELECT r.valutazione, r.commento,
           CONCAT(u.nome, ' ', LEFT(u.cognome, 1), '.') AS cliente_nome,
           CONCAT(ub.nome, ' ', ub.cognome) AS barbiere_nome
    FROM RECENSIONE r
    JOIN CLIENTE c ON r.id_cliente = c.id_cliente
    JOIN UTENTE u ON c.id_utente = u.id_utente
    JOIN BARBIERE b ON r.id_barbiere = b.id_barbiere
    JOIN UTENTE ub ON b.id_utente = ub.id_utente
    WHERE r.valutazione BETWEEN 1 AND 5
    ORDER BY r.data_recensione DESC, r.id_recensione DESC LIMIT 3
")->fetch_all(MYSQLI_ASSOC);

require __DIR__ . '/../includes/header.php';
?>

<div class="cliente-hero">
    <div class="cliente-hero-overlay"></div>
    <div class="cliente-hero-content">
        <p class="hero-greeting">Area cliente</p>
        <h1 class="hero-name">Ciao, <?= e($_SESSION['nome']) ?>.</h1>
        <p class="hero-sub">Scegli il tuo prossimo trattamento e consulta i tuoi appuntamenti.</p>
        <div class="hero-actions">
            <a href="nuova-prenotazione.php" class="btn-hero-primary">Nuova prenotazione</a>
            <a href="prenotazioni.php" class="btn-hero-secondary">I miei appuntamenti</a>
        </div>
    </div>
    <div class="hero-stats-bar">
        <div class="hero-stat">
            <span class="hstat-val"><?= (int) $stats['tot'] ?></span>
            <span class="hstat-lbl">Prenotazioni</span>
        </div>
        <div class="hero-stat-divider" aria-hidden="true"></div>
        <div class="hero-stat">
            <span class="hstat-val"><?= (int) $stats['completate'] ?></span>
            <span class="hstat-lbl">Appuntamenti completati</span>
        </div>
        <?php if ((int) $valutazioni['totale'] > 0): ?>
        <div class="hero-stat-divider" aria-hidden="true"></div>
        <div class="hero-stat">
            <span class="hstat-val"><?= number_format((float) $valutazioni['media'], 1, ',', '.') ?> / 5</span>
            <span class="hstat-lbl">Valutazione media</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="dashboard-container page-enter">
    <?php if ($prossima): ?>
    <section class="next-appt-card" aria-label="Prossimo appuntamento">
        <div class="next-appt-icon" aria-hidden="true">◷</div>
        <div class="next-appt-info">
            <span class="next-appt-label">Prossimo appuntamento</span>
            <span class="next-appt-date"><?= e(date('d/m/Y \a\l\l\e H:i', strtotime($prossima['data_ora']))) ?></span>
            <span class="next-appt-detail">
                <?= e($prossima['servizi'] ?? 'Trattamento') ?>
                <?php if ($prossima['barbiere'] !== null): ?>
                con <strong><?= e($prossima['barbiere']) ?></strong>
                <?php endif; ?>
                <?php if ($prossima['totale'] !== null): ?>
                · <strong><?= number_format((float) $prossima['totale'], 2, ',', '.') ?> €</strong>
                <?php endif; ?>
            </span>
        </div>
        <div class="next-appt-actions">
            <span class="status-badge status-<?= e(strtolower($prossima['stato'])) ?>"><?= e($prossima['stato']) ?></span>
            <a href="prenotazioni.php" class="btn-appt-detail">Vedi appuntamenti</a>
        </div>
    </section>
    <?php else: ?>
    <div class="no-appt-card">
        <div><strong>Nessun appuntamento in programma</strong></div>
    </div>
    <?php endif; ?>

    <section aria-labelledby="servizi-title">
        <div class="section-title-wrapper" style="margin-top: 3rem">
            <h2 id="servizi-title" class="section-main-title">I nostri <span class="gold-highlight">servizi</span></h2>
        </div>
        <?php if ($servizi): ?>
        <div class="servizi-grid-client">
            <?php foreach ($servizi as $servizio): ?>
            <article class="servizio-card-client">
                <div class="svc-category"><?= e($servizio['nome_categoria'] ?? 'Trattamenti') ?></div>
                <h3 class="svc-name"><?= e($servizio['nome_servizio']) ?></h3>
                <?php if ($servizio['descrizione']): ?><p class="svc-desc"><?= e($servizio['descrizione']) ?></p><?php endif; ?>
                <div class="svc-footer">
                    <span class="svc-price"><?= number_format((float) $servizio['prezzo'], 2, ',', '.') ?> €</span>
                    <span class="svc-duration"><?= (int) $servizio['durata_minuti'] ?> min</span>
                </div>
                <a href="nuova-prenotazione.php?servizio=<?= (int) $servizio['id_servizio'] ?>" class="svc-book-btn" aria-label="Prenota <?= e($servizio['nome_servizio']) ?>">Prenota</a>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state"><p>Il listino è in aggiornamento.</p></div>
        <?php endif; ?>
    </section>

    <div class="quick-actions-row">
        <a href="recensioni.php" class="quick-action-card">
            <span class="qa-title">Le mie recensioni</span>
            <span class="qa-sub">Valuta un appuntamento completato</span>
        </a>
        <a href="profilo.php" class="quick-action-card">
            <span class="qa-title">Il mio profilo</span>
            <span class="qa-sub">Modifica dati e password</span>
        </a>
    </div>

    <?php if ($recensioniRecenti): ?>
    <section aria-labelledby="recensioni-title">
        <div class="section-title-wrapper" style="margin-top: 3rem">
            <h2 id="recensioni-title" class="section-main-title">Recensioni <span class="gold-highlight">recenti</span></h2>
        </div>
        <div class="recensioni-preview-grid">
            <?php foreach ($recensioniRecenti as $recensione): ?>
            <article class="recensione-preview-card">
                <?php $voto = (int) $recensione['valutazione']; ?>
                <div class="rec-stars" aria-label="<?= $voto ?> su 5"><?= str_repeat('★', $voto) . str_repeat('☆', 5 - $voto) ?></div>
                <p class="rec-commento">«<?= e($recensione['commento'] ?? '') ?>»</p>
                <div class="rec-meta">
                    <span class="rec-cliente"><?= e($recensione['cliente_nome']) ?></span>
                    <span class="rec-barbiere">con <?= e($recensione['barbiere_nome']) ?></span>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
