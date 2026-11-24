<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/staff.php';
requireAuth('Barbiere');

$path = 'barbiere/index.php';
$id_barbiere = staffBarberId($conn);
$states = staffBookingStates();

staffHandlePost($path, function (string $action) use ($conn, $id_barbiere, $states): string {
    staffValidate($id_barbiere > 0 && in_array($action, ['Confermata', 'Completata', 'No_Show'], true), 'Operazione non valida.');
    $id = staffId('id_prenotazione');
    staffValidate($id > 0, 'Prenotazione non valida.');

    return staffTransaction($conn, function () use ($conn, $id, $id_barbiere, $action, $states): string {

        $lockBarber = $conn->prepare('SELECT id_barbiere FROM BARBIERE WHERE id_barbiere = ? FOR UPDATE');
        $lockBarber->bind_param('i', $id_barbiere);
        $lockBarber->execute();
        staffValidate($lockBarber->get_result()->num_rows === 1, 'Profilo barbiere non disponibile.');
        $lockBarber->close();

        $find = $conn->prepare('SELECT data_ora, data_fine, stato FROM PRENOTAZIONE
            WHERE id_prenotazione = ? AND id_barbiere = ? FOR UPDATE');
        $find->bind_param('ii', $id, $id_barbiere);
        $find->execute();
        $booking = $find->get_result()->fetch_assoc();
        $find->close();
        staffValidate($booking !== null, 'Prenotazione non trovata nella tua agenda.');
        staffValidate(in_array($booking['stato'], ['Prenotato', 'Confermata'], true), 'Questa prenotazione non è più modificabile.');

        $now = date('Y-m-d H:i:s');
        if ($action === 'Confermata') {
            staffValidate($booking['stato'] === 'Prenotato', 'La prenotazione è già stata confermata.');
            staffValidate($booking['data_ora'] > $now, 'Non puoi confermare un appuntamento già iniziato.');
        } elseif ($action === 'Completata') {
            staffValidate($booking['data_fine'] <= $now, 'Puoi completare l’appuntamento soltanto dopo l’orario di fine.');
        } elseif ($action === 'No_Show') {
            staffValidate($booking['data_ora'] <= $now, 'Puoi segnare il cliente assente soltanto dopo l’orario di inizio.');
        }

        $update = $conn->prepare("UPDATE PRENOTAZIONE SET stato = ?
            WHERE id_prenotazione = ? AND id_barbiere = ? AND stato IN ('Prenotato', 'Confermata')");
        $update->bind_param('sii', $action, $id, $id_barbiere);
        $update->execute();
        staffValidate($update->affected_rows === 1, 'La prenotazione è stata modificata da un’altra operazione. Aggiorna la pagina.');
        $update->close();

        return 'Stato aggiornato: ' . $states[$action][1] . '.';
    });
});
[$msg, $err] = staffFlash($path);
$now = date('Y-m-d H:i:s');
$filtro_data = staffIsoDate(staffText($_GET, 'data'));
$where_date = $filtro_data ? 'AND DATE(p.data_ora) = ?' : 'AND DATE(p.data_ora) BETWEEN CURDATE() - INTERVAL 7 DAY AND CURDATE() + INTERVAL 14 DAY';

$sql = "SELECT p.id_prenotazione, p.data_ora, p.data_fine, p.stato, p.note,
               CONCAT(u.nome, ' ', u.cognome) AS cliente_nome, u.telefono AS cliente_tel,
               ds.nome_servizio, TIMESTAMPDIFF(MINUTE, p.data_ora, p.data_fine) AS durata_minuti, ds.prezzo_applicato
        FROM PRENOTAZIONE p
        LEFT JOIN CLIENTE c ON c.id_cliente = p.id_cliente
        LEFT JOIN UTENTE u ON u.id_utente = c.id_utente
        LEFT JOIN (" . staffBookingDetailsSql() . ") ds ON ds.id_prenotazione = p.id_prenotazione
        WHERE p.id_barbiere = ? $where_date
        ORDER BY p.data_ora";

$select = $conn->prepare($sql);
if ($filtro_data) {
    $select->bind_param('is', $id_barbiere, $filtro_data);
} else {
    $select->bind_param('i', $id_barbiere);
}
$select->execute();
$appuntamenti = $select->get_result()->fetch_all(MYSQLI_ASSOC);

$oggi = $conn->prepare("SELECT COUNT(*) AS c FROM PRENOTAZIONE
    WHERE id_barbiere = ? AND DATE(data_ora) = CURDATE() AND stato NOT IN ('Annullata', 'No_Show')");
$oggi->bind_param('i', $id_barbiere);
$oggi->execute();
$n_oggi = $oggi->get_result()->fetch_assoc()['c'];

require __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <span class="section-subtitle">Area barbiere</span>
        <h1 class="section-main-title">La tua agenda</h1>
        <div class="title-gold-line"></div>
    </div>

    <?php if ($msg): ?><div class="flash flash-ok" role="status"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="flash flash-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:32px">
        <div class="kpi-card">
            <div class="kpi-icon">📅</div>
            <div class="kpi-data"><h3><?= $n_oggi ?></h3><p>APPUNTAMENTI OGGI</p></div>
            <div class="card-accent-line"></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon">📋</div>
            <div class="kpi-data"><h3><?= count($appuntamenti) ?></h3><p>MOSTRATI ORA</p></div>
            <div class="card-accent-line"></div>
        </div>
    </div>


    <form method="GET" class="filter-bar" style="margin-bottom:28px">
        <input type="date" name="data" value="<?= htmlspecialchars($filtro_data) ?>" aria-label="Filtra per data">
        <button type="submit" class="btn-filter">Filtra Giorno</button>
        <a href="index.php" class="btn-filter-reset">Mostra periodo predefinito</a>
    </form>

    <div class="luxury-data-card">
        <div class="card-data-header">
            <div>
                <h2>I MIEI APPUNTAMENTI</h2>
                <p>Aggiorna lo stato di ogni appuntamento a completamento del servizio.</p>
            </div>
        </div>
        <div class="table-responsive-wrapper" tabindex="0" role="region" aria-label="Tabella scorrevole">
            <table class="luxury-table">
                <thead><tr>
                    <th scope="col">DATA/ORA</th><th scope="col">CLIENTE</th><th scope="col">SERVIZIO</th><th scope="col">DURATA</th><th scope="col">INCASSO</th><th scope="col">STATO</th><th scope="col" class="text-right">AZIONI</th>
                </tr></thead>
                <tbody>
                <?php if (empty($appuntamenti)): ?>
                <tr><td colspan="7" class="empty-td">Nessun appuntamento nel periodo selezionato.</td></tr>
                <?php else: ?>
                <?php foreach ($appuntamenti as $a): ?>
                <tr class="table-row-interactive <?= in_array($a['stato'],['Annullata','No_Show']) ? 'row-faded' : '' ?>">
                    <td>
                        <strong><?= date('d/m/Y', strtotime($a['data_ora'])) ?></strong><br>
                        <span class="time-slot"><?= date('H:i', strtotime($a['data_ora'])) ?> → <?= date('H:i', strtotime($a['data_fine'])) ?></span>
                    </td>
                    <td>
                        <span class="user-fullname"><?= e($a['cliente_nome'] ?? 'Cliente rimosso') ?></span><br>
                        <small><?= htmlspecialchars($a['cliente_tel'] ?? '') ?></small>
                    </td>
                    <td class="gold-text-highlight"><?= htmlspecialchars($a['nome_servizio'] ?? '—') ?></td>
                    <td><?= $a['durata_minuti'] ?? '—' ?> min</td>
                    <td><?= staffMoney($a['prezzo_applicato'] ?? 0) ?></td>
                    <td><?= staffBookingBadge($a['stato']) ?></td>
                    <td class="text-right">
                    <?php if (!in_array($a['stato'], ['Completata','Annullata','No_Show'], true)): ?>
                        <form method="POST" class="staff-actions">
                            <?= csrfField() ?>
                            <input type="hidden" name="id_prenotazione" value="<?= (int) $a['id_prenotazione'] ?>">
                            <?php if ($a['stato'] === 'Prenotato' && $a['data_ora'] > $now): ?>
                            <button type="submit" name="action" value="Confermata" class="crud-btn btn-edit">Conferma</button>
                            <?php endif; ?>
                            <?php if ($a['data_fine'] <= $now): ?>
                            <button type="submit" name="action" value="Completata" class="action-btn-inline success-btn">Completa</button>
                            <?php endif; ?>
                            <?php if ($a['data_ora'] <= $now): ?>
                            <button type="submit" name="action" value="No_Show" class="action-btn-inline danger-btn">Segna come assente</button>
                            <?php endif; ?>
                            <?php if ($a['data_ora'] > $now): ?>
                            <span class="table-action-hint">In attesa dell’appuntamento</span>
                            <?php elseif ($a['data_fine'] > $now): ?>
                            <span class="table-action-hint">Servizio in corso</span>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <span class="table-action-hint">Nessuna azione</span>
                    <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
