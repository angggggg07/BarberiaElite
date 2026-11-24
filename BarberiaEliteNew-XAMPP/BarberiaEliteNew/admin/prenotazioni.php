<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/staff.php';
requireAuth('Admin');

$path = 'admin/prenotazioni.php';
$states = staffBookingStates();

staffHandlePost($path, function (string $action) use ($conn, $states): string {
    $id = staffId('id_prenotazione');
    if ($action === 'delete') {
        $delete = $conn->prepare('DELETE FROM PRENOTAZIONE WHERE id_prenotazione = ?');
        $delete->bind_param('i', $id);
        $delete->execute();
        staffValidate($delete->affected_rows === 1, 'Prenotazione non trovata.');
        return 'Prenotazione eliminata.';
    }

    $state = staffText($_POST, 'stato');
    staffValidate($action === 'update_stato' && isset($states[$state]), 'Seleziona uno stato valido.');

    $find = $conn->prepare('SELECT id_barbiere, id_cliente FROM PRENOTAZIONE WHERE id_prenotazione = ?');
    $find->bind_param('i', $id);
    $find->execute();
    $references = $find->get_result()->fetch_assoc();
    staffValidate($references !== null, 'Prenotazione non trovata.');

    return staffTransaction($conn, function () use ($conn, $id, $state, $states, $references): string {
        // The same order as booking creation prevents concurrent slot changes.
        $barberId = $references['id_barbiere'];
        $clientId = $references['id_cliente'];
        if ($barberId !== null) {
            $barber = $conn->prepare('SELECT id_barbiere FROM BARBIERE WHERE id_barbiere = ? FOR UPDATE');
            $barber->bind_param('i', $barberId);
            $barber->execute();
            $barber->get_result();
        }
        if ($clientId !== null) {
            $client = $conn->prepare('SELECT id_cliente FROM CLIENTE WHERE id_cliente = ? FOR UPDATE');
            $client->bind_param('i', $clientId);
            $client->execute();
            $client->get_result();
        }
        $find = $conn->prepare('SELECT id_barbiere, id_cliente, data_ora, data_fine, stato FROM PRENOTAZIONE WHERE id_prenotazione = ? FOR UPDATE');
        $find->bind_param('i', $id);
        $find->execute();
        $booking = $find->get_result()->fetch_assoc();
        staffValidate($booking !== null, 'Prenotazione non trovata.');
        staffValidate($booking['id_barbiere'] === $barberId && $booking['id_cliente'] === $clientId, 'I dati della prenotazione sono cambiati. Riprova.');

        $blocking = ['Prenotato', 'Confermata', 'Completata'];
        if (in_array($state, $blocking, true) && !in_array($booking['stato'], $blocking, true)) {
            staffValidate($barberId !== null && $clientId !== null, 'Non è possibile riattivare una prenotazione senza barbiere o cliente.');
            $conflict = $conn->prepare("SELECT id_prenotazione FROM PRENOTAZIONE
                WHERE id_prenotazione <> ? AND (id_barbiere = ? OR id_cliente = ?)
                AND data_ora < ? AND data_fine > ? AND stato IN ('Prenotato', 'Confermata', 'Completata') LIMIT 1");
            $conflict->bind_param('iiiss', $id, $barberId, $clientId, $booking['data_fine'], $booking['data_ora']);
            $conflict->execute();
            staffValidate($conflict->get_result()->num_rows === 0, 'La fascia è già occupata. La prenotazione non è stata riattivata.');
        }

        $update = $conn->prepare('UPDATE PRENOTAZIONE SET stato = ? WHERE id_prenotazione = ?');
        $update->bind_param('si', $state, $id);
        $update->execute();
        return 'Stato aggiornato: ' . $states[$state][1] . '.';
    });
});

[$msg, $err] = staffFlash($path);

$filtro_stato = staffText($_GET, 'stato');
$filtro_barbiere = staffId('barbiere', $_GET);
$filtro_dal = staffIsoDate(staffText($_GET, 'dal'));
$filtro_al = staffIsoDate(staffText($_GET, 'al'));
$where = [];
$params = [];
$types = '';
if (isset($states[$filtro_stato])) {
    $where[] = 'p.stato = ?';
    $params[] = $filtro_stato;
    $types .= 's';
}
if ($filtro_barbiere) {
    $where[] = 'p.id_barbiere = ?';
    $params[] = $filtro_barbiere;
    $types .= 'i';
}
if ($filtro_dal !== '') {
    $where[] = 'DATE(p.data_ora) >= ?';
    $params[] = $filtro_dal;
    $types .= 's';
}
if ($filtro_al !== '') {
    $where[] = 'DATE(p.data_ora) <= ?';
    $params[] = $filtro_al;
    $types .= 's';
}
$sql = "SELECT p.id_prenotazione, p.data_ora, p.stato,
               CONCAT(uc.nome, ' ', uc.cognome) AS cliente,
               CONCAT(ub.nome, ' ', ub.cognome) AS barbiere,
               ds.nome_servizio, ds.prezzo_applicato
        FROM PRENOTAZIONE p
        LEFT JOIN CLIENTE c ON c.id_cliente = p.id_cliente
        LEFT JOIN UTENTE uc ON uc.id_utente = c.id_utente
        LEFT JOIN BARBIERE b ON b.id_barbiere = p.id_barbiere
        LEFT JOIN UTENTE ub ON ub.id_utente = b.id_utente
        LEFT JOIN (" . staffBookingDetailsSql() . ") ds ON ds.id_prenotazione = p.id_prenotazione"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.data_ora DESC';
if ($params) {
    $select = $conn->prepare($sql);
    $select->bind_param($types, ...$params);
    $select->execute();
    $prenotazioni = $select->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $prenotazioni = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

$barbieri_list = $conn->query("SELECT b.id_barbiere, CONCAT(u.nome, ' ', u.cognome) AS nome
    FROM BARBIERE b JOIN UTENTE u ON b.id_utente = u.id_utente ORDER BY u.cognome")->fetch_all(MYSQLI_ASSOC);

require __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <span class="section-subtitle">Amministrazione</span>
        <h1 class="section-main-title">Prenotazioni</h1>
        <div class="title-gold-line"></div>
    </div>

    <?php if ($msg): ?><div class="flash flash-ok" role="status"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="flash flash-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <!-- Filtri -->
    <form method="GET" class="filter-bar filter-bar-wide">
        <select name="stato" aria-label="Filtra per stato">
            <option value="">Tutti gli stati</option>
            <?php foreach ($states as $st => [, $label]): ?>
            <option value="<?=$st?>" <?=$filtro_stato===$st?'selected':''?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="barbiere" aria-label="Filtra per barbiere">
            <option value="">Tutti i barbieri</option>
            <?php foreach ($barbieri_list as $b): ?>
            <option value="<?=$b['id_barbiere']?>" <?=$filtro_barbiere==$b['id_barbiere']?'selected':''?>><?= htmlspecialchars($b['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="dal" value="<?= htmlspecialchars($filtro_dal) ?>" aria-label="Dalla data">
        <input type="date" name="al"  value="<?= htmlspecialchars($filtro_al)  ?>" aria-label="Alla data">
        <button type="submit" class="btn-filter">Filtra</button>
        <a href="prenotazioni.php" class="btn-filter-reset">Reset</a>
    </form>

    <div class="luxury-data-card">
        <div class="card-data-header">
            <div>
                <h2>PRENOTAZIONI (<?= count($prenotazioni) ?>)</h2>
                <p>Modifica lo stato o elimina le prenotazioni esistenti.</p>
            </div>
        </div>
        <div class="table-responsive-wrapper" tabindex="0" role="region" aria-label="Tabella scorrevole">
            <table class="luxury-table">
                <thead><tr>
                    <th scope="col">Rif.</th><th scope="col">Data e ora</th><th scope="col">Cliente</th><th scope="col">Barbiere</th><th scope="col">Servizi</th><th scope="col">Importo</th><th scope="col">Stato</th><th scope="col" class="text-right">Azioni</th>
                </tr></thead>
                <tbody>
                <?php if (!$prenotazioni): ?><tr><td colspan="8" class="empty-td">Nessuna prenotazione corrisponde ai filtri.</td></tr><?php endif; ?>
                <?php foreach ($prenotazioni as $p): ?>
                <tr class="table-row-interactive">
                    <td><span class="id-badge"><?= $p['id_prenotazione'] ?></span></td>
                    <td><strong><?= date('d/m/Y', strtotime($p['data_ora'])) ?></strong><br><small><?= date('H:i', strtotime($p['data_ora'])) ?></small></td>
                    <td><?= htmlspecialchars($p['cliente'] ?? 'Cliente rimosso') ?></td>
                    <td><?= htmlspecialchars($p['barbiere'] ?? 'Barbiere rimosso') ?></td>
                    <td class="gold-text-highlight"><?= htmlspecialchars($p['nome_servizio'] ?? '—') ?></td>
                    <td><?= staffMoney($p['prezzo_applicato'] ?? 0) ?></td>
                    <td><?= staffBookingBadge($p['stato']) ?></td>
                    <td class="text-right">
                        <button type="button" class="crud-btn btn-edit" onclick="openStatoModal(<?= (int) $p['id_prenotazione'] ?>, <?= e(json_encode($p['stato'])) ?>)">Cambia stato</button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare questa prenotazione?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_prenotazione" value="<?= $p['id_prenotazione'] ?>">
                            <button type="submit" class="crud-btn btn-delete">Elimina</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal cambia stato -->
<div class="modal-overlay" id="modalStato" role="dialog" aria-modal="true" aria-labelledby="modalStato-title">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header"><h3 id="modalStato-title">AGGIORNA STATO</h3><button type="button" class="modal-close" aria-label="Chiudi" onclick="closeModal('modalStato')">✕</button></div>
        <form method="POST" class="modal-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_stato">
            <input type="hidden" name="id_prenotazione" id="s_id">
            <div class="form-group">
                <label for="s_stato">Nuovo Stato</label>
                <select name="stato" id="s_stato">
                    <?php foreach ($states as $st => [, $label]): ?>
                    <option value="<?=$st?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('modalStato')">Annulla</button>
                <button type="submit" class="btn-modal-save">Salva</button>
            </div>
        </form>
    </div>
</div>

<script>
function openStatoModal(id, stato) {
    document.getElementById('s_id').value   =id;
    document.getElementById('s_stato').value=stato;
    openModal('modalStato');
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>