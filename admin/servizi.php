<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/staff.php';
requireAuth('Admin');

$path = 'admin/servizi.php';
$categorie = $conn->query('SELECT id_categoria, nome_categoria FROM CATEGORIA_SERVIZIO ORDER BY nome_categoria')->fetch_all(MYSQLI_ASSOC);
$categoryIds = array_map('intval', array_column($categorie, 'id_categoria'));

staffHandlePost($path, function (string $action) use ($conn, $categoryIds): string {
    $id = staffId('id_servizio');
    if ($action === 'delete') {
        try {
            $delete = $conn->prepare('DELETE FROM SERVIZIO WHERE id_servizio = ?');
            $delete->bind_param('i', $id);
            $delete->execute();
            staffValidate($delete->affected_rows === 1, 'Servizio non trovato.');
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1451) {
                throw new DomainException('Il servizio è usato in una prenotazione. Disattivalo per conservarne lo storico.');
            }
            throw $exception;
        }
        return 'Servizio eliminato.';
    }

    staffValidate(in_array($action, ['create', 'update'], true), 'Operazione non valida.');
    $nome = staffText($_POST, 'nome_servizio');
    $descrizione = staffText($_POST, 'descrizione');
    $priceInput = str_replace(',', '.', staffText($_POST, 'prezzo'));
    $durata = staffId('durata_minuti');
    $categoria = staffId('id_categoria') ?: null;
    $status = staffText($_POST, 'attivo', '1');
    staffValidate($nome !== '' && mb_strlen($nome) <= 100 && strlen($descrizione) <= 10000, 'Inserisci un nome valido e una descrizione di massimo 10.000 byte.');
    staffValidate((bool) preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $priceInput) && (float) $priceInput > 0, 'Indica un prezzo maggiore di zero con al massimo due decimali.');
    staffValidate($durata > 0 && $durata < 1440, 'La durata deve essere maggiore di zero e inferiore a 24 ore.');
    staffValidate($categoria === null || in_array($categoria, $categoryIds, true), 'Seleziona una categoria valida.');
    staffValidate(in_array($status, ['0', '1'], true), 'Stato del servizio non valido.');
    $prezzo = (float) $priceInput;
    $attivo = (int) $status;

    if ($action === 'create') {
        $insert = $conn->prepare('INSERT INTO SERVIZIO (id_categoria, nome_servizio, descrizione, prezzo, durata_minuti) VALUES (?, ?, ?, ?, ?)');
        $insert->bind_param('issdi', $categoria, $nome, $descrizione, $prezzo, $durata);
        $insert->execute();
        return 'Servizio aggiunto.';
    }

    $update = $conn->prepare('UPDATE SERVIZIO SET id_categoria = ?, nome_servizio = ?, descrizione = ?, prezzo = ?, durata_minuti = ?, attivo = ? WHERE id_servizio = ?');
    $update->bind_param('issdiii', $categoria, $nome, $descrizione, $prezzo, $durata, $attivo, $id);
    $update->execute();
    if ($update->affected_rows === 0) {
        $exists = $conn->prepare('SELECT id_servizio FROM SERVIZIO WHERE id_servizio = ?');
        $exists->bind_param('i', $id);
        $exists->execute();
        staffValidate($exists->get_result()->num_rows === 1, 'Servizio non trovato.');
    }
    return 'Servizio aggiornato.';
});

[$msg, $err] = staffFlash($path);

$servizi=$conn->query("
    SELECT s.*, c.nome_categoria
    FROM SERVIZIO s
    LEFT JOIN CATEGORIA_SERVIZIO c ON s.id_categoria=c.id_categoria
    ORDER BY c.nome_categoria, s.nome_servizio
")->fetch_all(MYSQLI_ASSOC);

require __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <span class="section-subtitle">Amministrazione</span>
        <h1 class="section-main-title">Catalogo servizi</h1>
        <div class="title-gold-line"></div>
    </div>

    <?php if ($msg): ?><div class="flash flash-ok" role="status"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="flash flash-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="luxury-data-card">
        <div class="card-data-header">
            <div>
                <h2>LISTINO SERVIZI (<?= count($servizi) ?>)</h2>
                <p>Aggiorna prezzi, durate e disponibilità dei trattamenti offerti.</p>
            </div>
            <button type="button" class="btn-action-add" onclick="openModal('modalCreate')">+ NUOVO SERVIZIO</button>
        </div>
        <div class="table-responsive-wrapper" tabindex="0" role="region" aria-label="Tabella scorrevole">
            <table class="luxury-table">
                <thead><tr>
                    <th scope="col">Rif.</th><th scope="col">Servizio</th><th scope="col">Categoria</th><th scope="col">Descrizione</th><th scope="col">Prezzo</th><th scope="col">Durata</th><th scope="col">Stato</th><th scope="col" class="text-right">Azioni</th>
                </tr></thead>
                <tbody>
                <?php if (!$servizi): ?><tr><td colspan="8" class="empty-td">Il catalogo è vuoto.</td></tr><?php endif; ?>
                <?php foreach ($servizi as $s): ?>
                <tr class="table-row-interactive">
                    <td><span class="id-badge"><?= $s['id_servizio'] ?></span></td>
                    <td class="user-fullname"><?= htmlspecialchars($s['nome_servizio']) ?></td>
                    <td><span class="role-badge-pill role-barbiere"><?= htmlspecialchars($s['nome_categoria'] ?? '—') ?></span></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($s['descrizione'] ?? '') ?></td>
                    <td class="gold-text-highlight"><?= staffMoney($s['prezzo']) ?></td>
                    <td><?= $s['durata_minuti'] ?> min</td>
                    <td>
                        <div class="status-indicator">
                            <span class="status-dot <?= $s['attivo'] ? 'dot-attivo' : 'dot-sospeso' ?>"></span>
                            <span><?= $s['attivo'] ? 'Attivo' : 'Inattivo' ?></span>
                        </div>
                    </td>
                    <td class="text-right">
                        <button type="button" class="crud-btn btn-edit" onclick="openEdit(<?= e(json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE)) ?>)">Modifica</button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare il servizio?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_servizio" value="<?= $s['id_servizio'] ?>">
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


<div class="modal-overlay" id="modalCreate" role="dialog" aria-modal="true" aria-labelledby="modalCreate-title">
    <div class="modal-box">
        <div class="modal-header"><h3 id="modalCreate-title">NUOVO SERVIZIO</h3><button type="button" class="modal-close" aria-label="Chiudi" onclick="closeModal('modalCreate')">✕</button></div>
        <form method="POST" class="modal-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group"><label for="servizi-field-1">Nome Servizio *</label><input id="servizi-field-1" type="text" name="nome_servizio" required></div>
            <div class="form-group"><label for="servizi-field-2">Descrizione</label><textarea id="servizi-field-2" name="descrizione" rows="3"></textarea></div>
            <div class="form-grid-2">
                <div class="form-group"><label for="servizi-field-3">Prezzo (€) *</label><input id="servizi-field-3" type="number" name="prezzo" step="0.01" min="0.01" required></div>
                <div class="form-group"><label for="servizi-field-4">Durata (min) *</label><input id="servizi-field-4" type="number" name="durata_minuti" min="1" max="1439" required></div>
            </div>
            <div class="form-group">
                <label for="servizi-field-5">Categoria</label>
                <select id="servizi-field-5" name="id_categoria">
                    <option value="">— Nessuna —</option>
                    <?php foreach ($categorie as $c): ?><option value="<?= $c['id_categoria'] ?>"><?= e($c['nome_categoria']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('modalCreate')">Annulla</button>
                <button type="submit" class="btn-modal-save">Aggiungi</button>
            </div>
        </form>
    </div>
</div>


<div class="modal-overlay" id="modalEdit" role="dialog" aria-modal="true" aria-labelledby="modalEdit-title">
    <div class="modal-box">
        <div class="modal-header"><h3 id="modalEdit-title">MODIFICA SERVIZIO</h3><button type="button" class="modal-close" aria-label="Chiudi" onclick="closeModal('modalEdit')">✕</button></div>
        <form method="POST" class="modal-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id_servizio" id="e_id">
            <div class="form-group"><label for="e_nome">Nome Servizio *</label><input type="text" name="nome_servizio" id="e_nome" required></div>
            <div class="form-group"><label for="e_desc">Descrizione</label><textarea name="descrizione" id="e_desc" rows="3"></textarea></div>
            <div class="form-grid-2">
                <div class="form-group"><label for="e_prezzo">Prezzo (€)</label><input type="number" name="prezzo" id="e_prezzo" step="0.01" min="0.01" required></div>
                <div class="form-group"><label for="e_durata">Durata (min)</label><input type="number" name="durata_minuti" id="e_durata" min="1" max="1439" required></div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="e_cat">Categoria</label>
                    <select name="id_categoria" id="e_cat">
                        <option value="">— Nessuna —</option>
                        <?php foreach ($categorie as $c): ?><option value="<?= $c['id_categoria'] ?>"><?= e($c['nome_categoria']) ?></option><?php endforeach; ?>
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
function openEdit(s) {
    document.getElementById('e_id').value   =s.id_servizio;
    document.getElementById('e_nome').value =s.nome_servizio;
    document.getElementById('e_desc').value =s.descrizione || '';
    document.getElementById('e_prezzo').value= s.prezzo;
    document.getElementById('e_durata').value= s.durata_minuti;
    document.getElementById('e_cat').value  =s.id_categoria || '';
    document.getElementById('e_attivo').value= s.attivo;
    openModal('modalEdit');
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
