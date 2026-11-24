<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/staff.php';
requireAuth('Barbiere');

$path = 'barbiere/orari.php';
$id_barbiere = staffBarberId($conn);
$giorni = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];

staffHandlePost($path, function (string $action) use ($conn, $id_barbiere, $giorni): string {
    staffValidate($id_barbiere > 0 && in_array($action, ['create', 'toggle', 'delete'], true), 'Operazione non valida.');
    return staffTransaction($conn, function () use ($conn, $id_barbiere, $giorni, $action): string {

        $barber = $conn->prepare('SELECT id_barbiere FROM BARBIERE WHERE id_barbiere = ? FOR UPDATE');
        $barber->bind_param('i', $id_barbiere);
        $barber->execute();
        staffValidate($barber->get_result()->num_rows === 1, 'Profilo barbiere non disponibile.');
        $id = staffId('id_orario');
        $target = staffText($_POST, 'attivo');

        if ($action === 'delete') {
            $delete = $conn->prepare('DELETE FROM ORARIO_DISPONIBILE WHERE id_orario = ? AND id_barbiere = ?');
            $delete->bind_param('ii', $id, $id_barbiere);
            $delete->execute();
            staffValidate($delete->affected_rows === 1, 'Fascia oraria non trovata.');
            return 'Fascia rimossa.';
        }

        if ($action === 'create') {
            $day = staffId('giorno_settimana');
            $start = staffText($_POST, 'ora_inizio');
            $end = staffText($_POST, 'ora_fine');
            $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/D';
            staffValidate(isset($giorni[$day]) && preg_match($timePattern, $start) && preg_match($timePattern, $end) && $start < $end, 'Seleziona un giorno e un orario di fine successivo all’inizio.');
            $id = 0;
        } else {
            staffValidate(in_array($target, ['0', '1'], true), 'Stato della fascia non valido.');
            $select = $conn->prepare('SELECT giorno_settimana, ora_inizio, ora_fine FROM ORARIO_DISPONIBILE WHERE id_orario = ? AND id_barbiere = ?');
            $select->bind_param('ii', $id, $id_barbiere);
            $select->execute();
            $slot = $select->get_result()->fetch_assoc();
            staffValidate($slot !== null, 'Fascia oraria non trovata.');
            $day = $slot['giorno_settimana'];
            $start = $slot['ora_inizio'];
            $end = $slot['ora_fine'];
        }

        if ($action === 'create' || $target === '1') {
            $overlap = $conn->prepare('SELECT id_orario FROM ORARIO_DISPONIBILE
                WHERE id_barbiere = ? AND giorno_settimana = ? AND id_orario <> ?
                AND ((attivo = 1 AND ora_inizio < ? AND ora_fine > ?) OR (ora_inizio = ? AND ora_fine = ?)) LIMIT 1');
            $overlap->bind_param('iiissss', $id_barbiere, $day, $id, $end, $start, $start, $end);
            $overlap->execute();
            staffValidate($overlap->get_result()->num_rows === 0, 'La fascia coincide con una fascia esistente o si sovrappone a un orario attivo.');
        }

        if ($action === 'create') {
            $insert = $conn->prepare('INSERT INTO ORARIO_DISPONIBILE (id_barbiere, giorno_settimana, ora_inizio, ora_fine) VALUES (?, ?, ?, ?)');
            $insert->bind_param('iiss', $id_barbiere, $day, $start, $end);
            $insert->execute();
            return 'Fascia aggiunta.';
        }

        $state = (int) $target;
        $update = $conn->prepare('UPDATE ORARIO_DISPONIBILE SET attivo = ? WHERE id_orario = ? AND id_barbiere = ?');
        $update->bind_param('iii', $state, $id, $id_barbiere);
        $update->execute();
        return $state ? 'Fascia attivata.' : 'Fascia disattivata.';
    });
});
[$msg, $err] = staffFlash($path);

$orari = $conn->prepare('SELECT id_orario, giorno_settimana, ora_inizio, ora_fine, attivo FROM ORARIO_DISPONIBILE
    WHERE id_barbiere = ? ORDER BY giorno_settimana, ora_inizio');
$orari->bind_param('i', $id_barbiere);
$orari->execute();
$orari_list = $orari->get_result()->fetch_all(MYSQLI_ASSOC);

require __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <span class="section-subtitle">Area barbiere</span>
        <h1 class="section-main-title">Disponibilità</h1>
        <div class="title-gold-line"></div>
    </div>

    <?php if ($msg): ?><div class="flash flash-ok" role="status"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="flash flash-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="staff-split staff-schedule">

        <div class="luxury-data-card">
            <div class="card-data-header"><div><h2>AGGIUNGI FASCIA ORARIA</h2><p>Definisci le tue fasce di disponibilità settimanali.</p></div></div>
            <form method="POST" style="padding:28px 32px">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label for="orari-field-1">Giorno della settimana</label>
                    <select id="orari-field-1" name="giorno_settimana" required>
                        <?php foreach ($giorni as $n=>$nome): ?>
                        <option value="<?=$n?>"><?=$nome?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label for="orari-field-2">Inizio</label><input id="orari-field-2" type="time" name="ora_inizio" required value="09:00"></div>
                <div class="form-group"><label for="orari-field-3">Fine</label><input id="orari-field-3" type="time" name="ora_fine" required value="13:00"></div>
                <button type="submit" class="btn-modal-save" style="width:100%;margin-top:8px">+ Aggiungi Orario</button>
            </form>
        </div>


        <div class="luxury-data-card">
            <div class="card-data-header"><div><h2>FASCE ORARIE IMPOSTATE</h2><p>Le modifiche valgono per le nuove prenotazioni. Gli appuntamenti esistenti restano in agenda.</p></div></div>
            <div class="table-responsive-wrapper" tabindex="0" role="region" aria-label="Tabella scorrevole">
                <table class="luxury-table">
                    <thead><tr><th scope="col">GIORNO</th><th scope="col">DALLE</th><th scope="col">ALLE</th><th scope="col">STATO</th><th scope="col" class="text-right">AZIONI</th></tr></thead>
                    <tbody>
                    <?php if (empty($orari_list)): ?>
                    <tr><td colspan="5" class="empty-td">Nessun orario impostato.</td></tr>
                    <?php else: ?>
                    <?php foreach ($orari_list as $o): ?>
                    <tr class="table-row-interactive <?= !$o['attivo'] ? 'row-faded' : '' ?>">
                        <td><strong><?= $giorni[$o['giorno_settimana']] ?? '—' ?></strong></td>
                        <td><?= substr($o['ora_inizio'],0,5) ?></td>
                        <td><?= substr($o['ora_fine'],0,5) ?></td>
                        <td>
                            <div class="status-indicator">
                                <span class="status-dot <?= $o['attivo'] ? 'dot-attivo' : 'dot-sospeso' ?>"></span>
                                <span><?= $o['attivo'] ? 'Attivo' : 'Disattivo' ?></span>
                            </div>
                        </td>
                        <td class="text-right">
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="attivo" value="<?= (int) !$o['attivo'] ?>">
                                <input type="hidden" name="id_orario" value="<?= $o['id_orario'] ?>">
                                <button type="submit" class="crud-btn btn-edit"><?= $o['attivo'] ? 'Disattiva' : 'Attiva' ?></button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Rimuovere questa fascia oraria?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id_orario" value="<?= $o['id_orario'] ?>">
                                <button type="submit" class="crud-btn btn-delete">Rimuovi</button>
                            </form>
                        </td>
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
