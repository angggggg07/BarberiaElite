<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/staff.php';
requireAuth('Admin');

$tipologie = $conn->query('SELECT id_tipologia, nome_tipologia FROM TIPOLOGIA_UTENTE ORDER BY id_tipologia')->fetch_all(MYSQLI_ASSOC);
$ruoli = array_column($tipologie, 'nome_tipologia', 'id_tipologia');
$path = 'admin/utenti.php';

staffHandlePost($path, function (string $action) use ($conn, $ruoli): string {
    staffValidate(in_array($action, ['create', 'update', 'delete'], true), 'Operazione non valida.');
    $actingId = (int) $_SESSION['id_utente'];
    $id = staffId('id_utente');
    $nome = staffText($_POST, 'nome');
    $cognome = staffText($_POST, 'cognome');
    $email = staffText($_POST, 'email');
    $tel = staffText($_POST, 'telefono');
    $pwd = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $tipo = staffId('id_tipologia');
    $stato = staffText($_POST, 'stato', 'Attivo');

    if ($action !== 'delete') {
        staffValidate($nome !== '' && $cognome !== '' && mb_strlen($nome) <= 50 && mb_strlen($cognome) <= 50, 'Inserisci nome e cognome, massimo 50 caratteri ciascuno.');
        staffValidate(strlen($email) <= 100 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Inserisci un indirizzo email valido.');
        staffValidate(strlen($tel) <= 20 && in_array($stato, ['Attivo', 'Sospeso'], true), 'Telefono o stato non valido.');
        staffValidate(($action === 'update' && $pwd === '') || (strlen($pwd) >= 8 && strlen($pwd) <= 72 && !str_contains($pwd, "\0")), 'La password deve avere da 8 a 72 byte.');
    }

    if ($action === 'create') {
        staffValidate(in_array($ruoli[$tipo] ?? null, ['Admin', 'Barbiere', 'Cliente'], true), 'Seleziona un ruolo valido.');
    }

    try {
        return staffTransaction($conn, function () use ($conn, $ruoli, $action, $actingId, $id, $nome, $cognome, $email, $tel, $pwd, $tipo, $stato): string {

            $adminRole = $conn->query("SELECT id_tipologia FROM TIPOLOGIA_UTENTE WHERE nome_tipologia = 'Admin' FOR UPDATE")->fetch_assoc();
            $active = $conn->prepare("SELECT id_utente FROM UTENTE WHERE id_utente = ? AND id_tipologia = ? AND stato = 'Attivo' FOR UPDATE");
            $adminId = $adminRole['id_tipologia'] ?? 0;
            $active->bind_param('ii', $actingId, $adminId);
            $active->execute();
            staffValidate($active->get_result()->num_rows === 1, 'Il tuo account non consente più questa operazione.');

            if ($action === 'create') {
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $insert = $conn->prepare('INSERT INTO UTENTE (nome, cognome, email, telefono, password_hash, id_tipologia, stato) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $insert->bind_param('sssssis', $nome, $cognome, $email, $tel, $hash, $tipo, $stato);
                $insert->execute();
                $newId = $conn->insert_id;

                switch ($ruoli[$tipo]) {
                    case 'Admin':
                        $profile = $conn->prepare('INSERT INTO ADMIN (id_utente, data_nomina) VALUES (?, CURDATE())');
                        break;
                    case 'Barbiere':
                        $profile = $conn->prepare('INSERT INTO BARBIERE (id_utente, id_sede, data_assunzione) VALUES (?, (SELECT MIN(id_sede) FROM SEDE), CURDATE())');
                        break;
                    default:
                        $profile = $conn->prepare('INSERT INTO CLIENTE (id_utente) VALUES (?)');
                }
                $profile->bind_param('i', $newId);
                $profile->execute();
                return 'Utente creato.';
            }

            $select = $conn->prepare('SELECT id_utente FROM UTENTE WHERE id_utente = ? FOR UPDATE');
            $select->bind_param('i', $id);
            $select->execute();
            staffValidate($select->get_result()->num_rows === 1, 'Utente non trovato.');
            staffValidate($id !== $actingId || ($action !== 'delete' && $stato === 'Attivo'), 'Non puoi eliminare o sospendere il tuo account mentre lo stai usando.');

            if ($action === 'delete') {
                $delete = $conn->prepare('DELETE FROM UTENTE WHERE id_utente = ?');
                $delete->bind_param('i', $id);
                $delete->execute();
                return 'Utente eliminato.';
            }

            if ($pwd !== '') {
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $update = $conn->prepare('UPDATE UTENTE SET nome = ?, cognome = ?, email = ?, telefono = ?, stato = ?, password_hash = ? WHERE id_utente = ?');
                $update->bind_param('ssssssi', $nome, $cognome, $email, $tel, $stato, $hash, $id);
            } else {
                $update = $conn->prepare('UPDATE UTENTE SET nome = ?, cognome = ?, email = ?, telefono = ?, stato = ? WHERE id_utente = ?');
                $update->bind_param('sssssi', $nome, $cognome, $email, $tel, $stato, $id);
            }
            $update->execute();
            return 'Utente aggiornato.';
        });
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) {
            throw new DomainException('L’indirizzo email è già associato a un account.');
        }
        throw $exception;
    }
});

[$msg, $err] = staffFlash($path);

$ricerca = staffText($_GET, 'q');
$filtro = staffText($_GET, 'tipo');
$where = [];
$params = [];
$types = '';
if ($ricerca !== '') {
    $where[] = '(u.nome LIKE ? OR u.cognome LIKE ? OR u.email LIKE ?)';
    $like = '%' . $ricerca . '%';
    $params = [$like, $like, $like];
    $types = 'sss';
}
if (in_array($filtro, $ruoli, true)) {
    $where[] = 't.nome_tipologia = ?';
    $params[] = $filtro;
    $types .= 's';
}
$sql = 'SELECT u.id_utente, u.nome, u.cognome, u.email, u.telefono, u.stato, u.data_registrazione, t.nome_tipologia
        FROM UTENTE u JOIN TIPOLOGIA_UTENTE t ON u.id_tipologia = t.id_tipologia'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY u.id_utente';
if ($params) {
    $select = $conn->prepare($sql);
    $select->bind_param($types, ...$params);
    $select->execute();
    $utenti = $select->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $utenti = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

require __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <span class="section-subtitle">Amministrazione</span>
        <h1 class="section-main-title">Utenti</h1>
        <div class="title-gold-line"></div>
    </div>

    <?php if ($msg): ?><div class="flash flash-ok" role="status"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="flash flash-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>


    <form method="GET" class="filter-bar">
        <input type="search" name="q" aria-label="Cerca utenti" placeholder="Cerca per nome, cognome o email…" value="<?= htmlspecialchars($ricerca) ?>">
        <select name="tipo" aria-label="Filtra per ruolo">
            <option value="">Tutti i ruoli</option>
            <?php foreach ($tipologie as $t): ?>
                <option value="<?= e($t['nome_tipologia']) ?>" <?= $filtro===$t['nome_tipologia']?'selected':'' ?>><?= e($t['nome_tipologia']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-filter">Filtra</button>
        <a href="utenti.php" class="btn-filter-reset">Reset</a>
    </form>

    <div class="luxury-data-card">
        <div class="card-data-header">
            <div>
                <h2>REGISTRO UTENTI (<?= count($utenti) ?>)</h2>
                <p>Gestione completa degli accessi, dei ruoli e dello stato degli account.</p>
            </div>
            <button type="button" class="btn-action-add" onclick="openModal('modalCreate')">+ NUOVO UTENTE</button>
        </div>
        <div class="table-responsive-wrapper" tabindex="0" role="region" aria-label="Tabella scorrevole">
            <table class="luxury-table">
                <thead><tr>
                    <th scope="col">ID</th><th scope="col">NOMINATIVO</th><th scope="col">EMAIL</th><th scope="col">TELEFONO</th><th scope="col">RUOLO</th><th scope="col">REGISTRATO</th><th scope="col">STATO</th><th scope="col" class="text-right">AZIONI</th>
                </tr></thead>
                <tbody>
                <?php if (!$utenti): ?><tr><td colspan="8" class="empty-td">Nessun utente corrisponde ai filtri.</td></tr><?php endif; ?>
                <?php foreach ($utenti as $u): ?>
                <tr class="table-row-interactive">
                    <td><span class="id-badge"><?= $u['id_utente'] ?></span></td>
                    <td class="user-fullname"><?= htmlspecialchars($u['nome'].' '.$u['cognome']) ?></td>
                    <td><code class="email-cell"><?= htmlspecialchars($u['email']) ?></code></td>
                    <td><?= htmlspecialchars($u['telefono'] ?? '—') ?></td>
                    <td><span class="role-badge-pill role-<?= e(strtolower($u['nome_tipologia'])) ?>"><?= e($u['nome_tipologia']) ?></span></td>
                    <td><?= date('d/m/Y', strtotime($u['data_registrazione'])) ?></td>
                    <td>
                        <div class="status-indicator">
                            <span class="status-dot dot-<?= strtolower($u['stato']) ?>"></span>
                            <span><?= $u['stato'] ?></span>
                        </div>
                    </td>
                    <td class="text-right">
                        <button type="button" class="crud-btn btn-edit" onclick="openEdit(<?= e(json_encode($u, JSON_INVALID_UTF8_SUBSTITUTE)) ?>)">Modifica</button>
                        <?php if ((int) $u['id_utente'] !== (int) $_SESSION['id_utente']): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare definitivamente questo utente?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_utente" value="<?= $u['id_utente'] ?>">
                            <button type="submit" class="crud-btn btn-delete">Elimina</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<div class="modal-overlay" id="modalCreate" role="dialog" aria-modal="true" aria-labelledby="modalCreate-title">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalCreate-title">NUOVO UTENTE</h3>
            <button type="button" class="modal-close" aria-label="Chiudi" onclick="closeModal('modalCreate')">✕</button>
        </div>
        <form method="POST" class="modal-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-grid-2">
                <div class="form-group"><label for="utenti-field-1">Nome *</label><input id="utenti-field-1" type="text" name="nome" maxlength="50" autocomplete="given-name" required></div>
                <div class="form-group"><label for="utenti-field-2">Cognome *</label><input id="utenti-field-2" type="text" name="cognome" maxlength="50" autocomplete="family-name" required></div>
            </div>
            <div class="form-group"><label for="utenti-field-3">Email *</label><input id="utenti-field-3" type="email" name="email" maxlength="100" autocomplete="email" required></div>
            <div class="form-group"><label for="utenti-field-4">Telefono</label><input id="utenti-field-4" type="text" name="telefono" maxlength="20" autocomplete="tel"></div>
            <div class="form-group"><label for="utenti-field-5">Password * (min 8 caratteri)</label><input id="utenti-field-5" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"></div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="utenti-field-6">Ruolo *</label>
                    <select id="utenti-field-6" name="id_tipologia" required>
                        <?php foreach ($tipologie as $t): ?>
                            <option value="<?= $t['id_tipologia'] ?>"><?= e($t['nome_tipologia']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="utenti-field-7">Stato</label>
                    <select id="utenti-field-7" name="stato">
                        <option value="Attivo">Attivo</option>
                        <option value="Sospeso">Sospeso</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('modalCreate')">Annulla</button>
                <button type="submit" class="btn-modal-save">Crea Utente</button>
            </div>
        </form>
    </div>
</div>


<div class="modal-overlay" id="modalEdit" role="dialog" aria-modal="true" aria-labelledby="modalEdit-title">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalEdit-title">MODIFICA UTENTE</h3>
            <button type="button" class="modal-close" aria-label="Chiudi" onclick="closeModal('modalEdit')">✕</button>
        </div>
        <form method="POST" class="modal-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id_utente" id="edit_id">
            <div class="form-grid-2">
                <div class="form-group"><label for="edit_nome">Nome *</label><input type="text" name="nome" id="edit_nome" required></div>
                <div class="form-group"><label for="edit_cognome">Cognome *</label><input type="text" name="cognome" id="edit_cognome" required></div>
            </div>
            <div class="form-group"><label for="edit_email">Email *</label><input type="email" name="email" id="edit_email" required></div>
            <div class="form-group"><label for="edit_telefono">Telefono</label><input type="text" name="telefono" id="edit_telefono" maxlength="20" autocomplete="tel"></div>
            <div class="form-group"><label for="utenti-field-12">Nuova Password <small>(lascia vuoto per non cambiare)</small></label><input id="utenti-field-12" type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password"></div>
            <div class="form-group">
                <label for="edit_stato">Stato</label>
                <select name="stato" id="edit_stato">
                    <option value="Attivo">Attivo</option>
                    <option value="Sospeso">Sospeso</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('modalEdit')">Annulla</button>
                <button type="submit" class="btn-modal-save">Salva Modifiche</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(u) {
    document.getElementById('edit_id').value     =u.id_utente;
    document.getElementById('edit_nome').value   =u.nome;
    document.getElementById('edit_cognome').value=u.cognome;
    document.getElementById('edit_email').value  =u.email;
    document.getElementById('edit_telefono').value= u.telefono || '';
    document.getElementById('edit_stato').value  =u.stato;
    openModal('modalEdit');
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
