<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/staff.php';
requireAuth('Admin');

$path = 'admin/barbieri.php';
$sedi = $conn->query('SELECT id_sede, nome_sede FROM SEDE ORDER BY nome_sede')->fetch_all(MYSQLI_ASSOC);
$sediIds = array_map('intval', array_column($sedi, 'id_sede'));

staffHandlePost($path, function (string $action) use ($conn, $sediIds): string {
    if ($action === 'delete') {
        $id = staffId('id_utente');
        $delete = $conn->prepare("DELETE u FROM UTENTE u JOIN BARBIERE b ON b.id_utente = u.id_utente JOIN TIPOLOGIA_UTENTE t ON t.id_tipologia = u.id_tipologia WHERE u.id_utente = ? AND t.nome_tipologia = 'Barbiere'");
        $delete->bind_param('i', $id);
        $delete->execute();
        staffValidate($delete->affected_rows === 1, 'Barbiere non trovato.');
        return 'Barbiere eliminato.';
    }
    staffValidate(in_array($action, ['create', 'update'], true), 'Operazione non valida.');
    $spec = staffText($_POST, 'specializzazione');
    $bio = staffText($_POST, 'biografia');
    $sede = staffId('id_sede');
    staffValidate(mb_strlen($spec) <= 100 && mb_strlen($bio) <= 10000, 'Specializzazione o biografia troppo lunga.');
    staffValidate(in_array($sede, $sediIds, true), 'Seleziona una sede valida.');

    if ($action === 'update') {
        $id = staffId('id_barbiere');
        $status = staffText($_POST, 'attivo');
        staffValidate(in_array($status, ['0', '1'], true), 'Stato non valido.');
        $attivo = (int) $status;
        $update = $conn->prepare('UPDATE BARBIERE SET specializzazione = ?, biografia = ?, attivo = ?, id_sede = ? WHERE id_barbiere = ?');
        $update->bind_param('ssiii', $spec, $bio, $attivo, $sede, $id);
        $update->execute();
        if ($update->affected_rows === 0) {
            $exists = $conn->prepare('SELECT id_barbiere FROM BARBIERE WHERE id_barbiere = ?');
            $exists->bind_param('i', $id);
            $exists->execute();
            staffValidate($exists->get_result()->num_rows === 1, 'Barbiere non trovato.');
        }
        return 'Profilo barbiere aggiornato.';
    }

    $nome = staffText($_POST, 'nome');
    $cognome = staffText($_POST, 'cognome');
    $email = staffText($_POST, 'email');
    $tel = staffText($_POST, 'telefono');
    $pwd = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $assunzione = staffIsoDate(staffText($_POST, 'data_assunzione'));
    staffValidate($nome !== '' && $cognome !== '' && mb_strlen($nome) <= 50 && mb_strlen($cognome) <= 50, 'Inserisci nome e cognome, massimo 50 caratteri ciascuno.');
    staffValidate(strlen($email) <= 100 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Inserisci un indirizzo email valido.');
    staffValidate(strlen($tel) <= 20 && $assunzione !== '' && $assunzione <= date('Y-m-d'), 'Controlla telefono e data di assunzione: non può essere futura.');
    staffValidate(strlen($pwd) >= 8 && strlen($pwd) <= 72 && !str_contains($pwd, "\0"), 'La password deve avere da 8 a 72 byte.');
    $role = staffRoleId($conn, 'Barbiere');
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    try {
        return staffTransaction($conn, function () use ($conn, $nome, $cognome, $email, $tel, $hash, $role, $sede, $assunzione, $spec, $bio): string {
            $account = $conn->prepare('INSERT INTO UTENTE (nome, cognome, email, telefono, password_hash, id_tipologia) VALUES (?, ?, ?, ?, ?, ?)');
            $account->bind_param('sssssi', $nome, $cognome, $email, $tel, $hash, $role);
            $account->execute();
            $id = $conn->insert_id;
            $profile = $conn->prepare('INSERT INTO BARBIERE (id_utente, id_sede, data_assunzione, specializzazione, biografia) VALUES (?, ?, ?, ?, ?)');
            $profile->bind_param('iisss', $id, $sede, $assunzione, $spec, $bio);
            $profile->execute();
            return 'Barbiere creato. Il nuovo account può accedere con l’indirizzo email indicato.';
        });
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) {
            throw new DomainException('L’indirizzo email è già associato a un account.');
        }
        throw $exception;
    }
});

[$msg, $err] = staffFlash($path);

$barbieri=$conn->query("
    SELECT b.*, CONCAT(u.nome,' ',u.cognome) AS nome_completo, u.email, u.telefono, u.id_utente,
           u.stato AS stato_utente, se.nome_sede,
           (SELECT COUNT(*) FROM PRENOTAZIONE p WHERE p.id_barbiere=b.id_barbiere AND p.stato='Completata') AS tot_completate,
           (SELECT COALESCE(SUM(dp.prezzo_applicato),0) FROM PRENOTAZIONE p JOIN DETTAGLIO_PRENOTAZIONE dp ON p.id_prenotazione=dp.id_prenotazione WHERE p.id_barbiere=b.id_barbiere AND p.stato='Completata') AS tot_guadagni,
           (SELECT ROUND(AVG(r.valutazione),1) FROM RECENSIONE r WHERE r.id_barbiere=b.id_barbiere) AS rating_medio
    FROM BARBIERE b
    JOIN UTENTE u ON b.id_utente=u.id_utente
    LEFT JOIN SEDE se ON b.id_sede=se.id_sede
    ORDER BY u.cognome
")->fetch_all(MYSQLI_ASSOC);

require __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <span class="section-subtitle">Amministrazione</span>
        <h1 class="section-main-title">Barbieri</h1>
        <div class="title-gold-line"></div>
    </div>

    <?php if ($msg): ?><div class="flash flash-ok" role="status"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="flash flash-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div style="display:flex; justify-content:flex-end; margin-bottom:24px;">
        <button type="button" class="btn-action-add" onclick="openModal('modalCreate')">+ AGGIUNGI BARBIERE</button>
    </div>

    <div class="barbieri-grid">
    <?php foreach ($barbieri as $b): ?>
    <div class="barbiere-card">
        <div class="barbiere-avatar"><?= e(preg_match('/^./u', $b['nome_completo'], $initial) ? strtoupper($initial[0]) : 'B') ?></div>
        <div class="barbiere-info">
            <h3><?= htmlspecialchars($b['nome_completo']) ?></h3>
            <span class="role-badge-pill role-barbiere"><?= htmlspecialchars($b['specializzazione'] ?: 'Barbiere') ?></span>
            <div class="barbiere-meta">
                <span>📧 <?= htmlspecialchars($b['email']) ?></span>
                <?php if ($b['telefono']): ?><span>📞 <?= htmlspecialchars($b['telefono']) ?></span><?php endif; ?>
                <span>📍 <?= htmlspecialchars($b['nome_sede'] ?? '—') ?></span>
                <span>📅 Assunto: <?= date('d/m/Y', strtotime($b['data_assunzione'])) ?></span>
            </div>
            <div class="barbiere-stats">
                <div class="bstat">
                    <span class="bstat-val"><?= $b['tot_completate'] ?></span>
                    <span class="bstat-lbl">Completati</span>
                </div>
                <div class="bstat">
                    <span class="bstat-val"><?= staffMoney($b['tot_guadagni']) ?></span>
                    <span class="bstat-lbl">Guadagnato</span>
                </div>
                <div class="bstat">
                    <span class="bstat-val"><?= $b['rating_medio'] ? '⭐ ' . $b['rating_medio'] : '—' ?></span>
                    <span class="bstat-lbl">Rating</span>
                </div>
                <div class="bstat">
                    <span class="bstat-val">
                        <span class="status-dot <?= $b['attivo'] && $b['stato_utente'] === 'Attivo' ? 'dot-attivo' : 'dot-sospeso' ?>" style="width:10px;height:10px"></span>
                    </span>
                    <span class="bstat-lbl"><?= $b['stato_utente'] === 'Sospeso' ? 'Sospeso' : ($b['attivo'] ? 'Attivo' : 'Inattivo') ?></span>
                </div>
            </div>
            <?php if ($b['biografia']): ?>
            <p style="font-size:0.78rem; color:var(--text-muted); margin-top:8px; line-height:1.5;">
                <?= htmlspecialchars($b['biografia']) ?>
            </p>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;align-self:flex-start;margin-top:auto;flex-wrap:wrap">
            <button type="button" class="btn-action-add" onclick="openEdit(<?= e(json_encode($b, JSON_INVALID_UTF8_SUBSTITUTE)) ?>)">Modifica</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare questo barbiere e il suo account?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id_utente" value="<?= $b['id_utente'] ?>">
                <button type="submit" class="crud-btn btn-delete">Elimina</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <?php if (empty($barbieri)): ?>
    <div class="empty-state">
        <p>Nessun barbiere registrato. Clicca su "+ AGGIUNGI BARBIERE" per crearne uno.</p>
    </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="modalCreate" role="dialog" aria-modal="true" aria-labelledby="modalCreate-title">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalCreate-title">NUOVO BARBIERE</h3>
            <button type="button" class="modal-close" aria-label="Chiudi" onclick="closeModal('modalCreate')">✕</button>
        </div>
        <form method="POST" class="modal-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <p style="color:var(--text-muted);font-size:0.8rem;margin-bottom:12px;">
                Questo crea automaticamente un account utente con ruolo Barbiere.
            </p>
            <div class="form-grid-2">
                <div class="form-group"><label for="barbieri-field-1">Nome *</label><input id="barbieri-field-1" type="text" name="nome" maxlength="50" autocomplete="given-name" required></div>
                <div class="form-group"><label for="barbieri-field-2">Cognome *</label><input id="barbieri-field-2" type="text" name="cognome" maxlength="50" autocomplete="family-name" required></div>
            </div>
            <div class="form-grid-2">
                <div class="form-group"><label for="barbieri-field-3">Email * (per il login)</label><input id="barbieri-field-3" type="email" name="email" maxlength="100" autocomplete="email" required></div>
                <div class="form-group"><label for="barbieri-field-4">Telefono</label><input id="barbieri-field-4" type="text" name="telefono" maxlength="20" autocomplete="tel"></div>
            </div>
            <div class="form-group"><label for="barbieri-field-5">Password * (min 8 caratteri)</label><input id="barbieri-field-5" type="password" name="password" required minlength="8" maxlength="72" placeholder="Scegli una password per il barbiere" autocomplete="new-password"></div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="barbieri-field-6">Sede</label>
                    <select id="barbieri-field-6" name="id_sede" required>
                        <?php foreach ($sedi as $se): ?>
                        <option value="<?= $se['id_sede'] ?>"><?= htmlspecialchars($se['nome_sede']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label for="barbieri-field-7">Data assunzione</label><input id="barbieri-field-7" type="date" name="data_assunzione" value="<?= date('Y-m-d') ?>"></div>
            </div>
            <div class="form-group"><label for="barbieri-field-8">Specializzazione</label><input id="barbieri-field-8" type="text" name="specializzazione" placeholder="Es: Master Barber, Colorista..."></div>
            <div class="form-group"><label for="barbieri-field-9">Biografia</label><textarea id="barbieri-field-9" name="biografia" rows="3" placeholder="Descrizione del barbiere..."></textarea></div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('modalCreate')">Annulla</button>
                <button type="submit" class="btn-modal-save">Crea Barbiere</button>
            </div>
        </form>
    </div>
</div>


<div class="modal-overlay" id="modalEdit" role="dialog" aria-modal="true" aria-labelledby="modalEdit-title">
    <div class="modal-box">
        <div class="modal-header"><h3 id="modalEdit-title">MODIFICA BARBIERE</h3><button type="button" class="modal-close" aria-label="Chiudi" onclick="closeModal('modalEdit')">✕</button></div>
        <form method="POST" class="modal-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id_barbiere" id="e_id">
            <div class="form-group"><label for="e_spec">Specializzazione</label><input type="text" name="specializzazione" id="e_spec"></div>
            <div class="form-group"><label for="e_bio">Biografia</label><textarea name="biografia" id="e_bio" rows="4"></textarea></div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="e_sede">Sede</label>
                    <select name="id_sede" required id="e_sede">
                        <?php foreach ($sedi as $se): ?>
                        <option value="<?= $se['id_sede'] ?>"><?= htmlspecialchars($se['nome_sede']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="e_attivo">Stato</label>
                    <select name="attivo" id="e_attivo">
                        <option value="1">Attivo</option>
                        <option value="0">Inattivo</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('modalEdit')">Annulla</button>
                <button type="submit" class="btn-modal-save">Salva</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(b) {
    document.getElementById('e_id').value   =b.id_barbiere;
    document.getElementById('e_spec').value =b.specializzazione || '';
    document.getElementById('e_bio').value  =b.biografia || '';
    document.getElementById('e_attivo').value= b.attivo;
    document.getElementById('e_sede').value =b.id_sede || '';
    openModal('modalEdit');
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
