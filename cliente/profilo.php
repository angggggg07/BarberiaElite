<?php
require_once __DIR__ . '/../config.php';
requireAuth('Cliente');

$idUtente = (int) $_SESSION['id_utente'];
$stmt = $conn->prepare(
    'SELECT u.nome, u.cognome, u.email, u.telefono, u.password_hash, c.data_nascita, c.indirizzo
     FROM UTENTE u LEFT JOIN CLIENTE c ON c.id_utente = u.id_utente
     WHERE u.id_utente = ?'
);
$stmt->bind_param('i', $idUtente);
$stmt->execute();
$utente = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$utente) {
    appError(404, 'Profilo non disponibile', 'Il profilo cliente non è più disponibile.');
}

$msg = $_SESSION['messaggio_profilo'] ?? '';
unset($_SESSION['messaggio_profilo']);
$err = '';
$oggi = date('Y-m-d');
$dati = $utente;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $campi = ['nome', 'cognome', 'telefono', 'data_nascita', 'indirizzo'];
        foreach ($campi as $campo) {
            $valore = $_POST[$campo] ?? '';
            if (!is_string($valore)) {
                $err = 'Controlla i dati inseriti.';
                break;
            }
            $dati[$campo] = trim($valore);
        }

        $nome = $dati['nome'];
        $cognome = $dati['cognome'];
        $telefono = $dati['telefono'] === '' ? null : $dati['telefono'];
        $nascita = $dati['data_nascita'] === '' ? null : $dati['data_nascita'];
        $indirizzo = $dati['indirizzo'] === '' ? null : $dati['indirizzo'];
        $dataValida = $nascita === null
            || (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $nascita, $parti)
                && checkdate((int) $parti[2], (int) $parti[3], (int) $parti[1])
                && $nascita >= '1000-01-01' && $nascita <= $oggi);

        if ($err === '' && (!preg_match('/\A[^\p{Cc}]{1,50}\z/u', $nome)
            || !preg_match('/\A[^\p{Cc}]{1,50}\z/u', $cognome))) {
            $err = 'Inserisci nome e cognome, fino a 50 caratteri ciascuno.';
        } elseif ($err === '' && $telefono !== null && !preg_match('/\A[^\p{Cc}]{1,20}\z/u', $telefono)) {
            $err = 'Il numero di telefono può contenere fino a 20 caratteri.';
        } elseif ($err === '' && !$dataValida) {
            $err = 'Inserisci una data di nascita valida.';
        } elseif ($err === '' && $indirizzo !== null && !preg_match('/\A[^\p{Cc}]{1,255}\z/u', $indirizzo)) {
            $err = 'L’indirizzo può contenere fino a 255 caratteri.';
        }

        if ($err === '') {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('UPDATE UTENTE SET nome = ?, cognome = ?, telefono = ? WHERE id_utente = ?');
                $stmt->bind_param('sssi', $nome, $cognome, $telefono, $idUtente);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    'UPDATE CLIENTE SET data_nascita = ?, indirizzo = ? WHERE id_utente = ?'
                );
                $stmt->bind_param('ssi', $nascita, $indirizzo, $idUtente);
                $stmt->execute();
                $stmt->close();
                $conn->commit();

                $_SESSION['nome'] = $nome;
                $_SESSION['cognome'] = $cognome;
                $_SESSION['messaggio_profilo'] = 'Profilo aggiornato.';
                redirect('cliente/profilo.php');
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                error_log('Aggiornamento profilo cliente: ' . $exception->getMessage());
                $err = 'Impossibile salvare il profilo. Riprova.';
            }
        }
    } elseif ($action === 'password') {
        $attuale = $_POST['password_attuale'] ?? '';
        $nuova = $_POST['password_nuova'] ?? '';
        $conferma = $_POST['password_conf'] ?? '';

        if (!is_string($attuale) || !is_string($nuova) || !is_string($conferma)
            || $attuale === '' || $nuova === '' || $conferma === '') {
            $err = 'Compila tutti i campi della password.';
        } elseif ($nuova !== $conferma) {
            $err = 'Le nuove password non coincidono.';
        } elseif (strlen($nuova) > 72 || str_contains($nuova, "\0") || !preg_match('/\A.{8,}\z/us', $nuova)) {
            $err = 'Scegli una password di almeno 8 caratteri e al massimo 72 byte.';
        } elseif (!password_verify($attuale, $utente['password_hash'])) {
            $err = 'La password attuale non è corretta.';
        } else {
            try {
                $hash = password_hash($nuova, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE UTENTE SET password_hash = ? WHERE id_utente = ? AND BINARY password_hash = ?');
                $stmt->bind_param('sis', $hash, $idUtente, $utente['password_hash']);
                $stmt->execute();
                $aggiornata = $stmt->affected_rows === 1;
                $stmt->close();
                if ($aggiornata) {
                    session_regenerate_id(true);
                    $_SESSION['messaggio_profilo'] = 'Password aggiornata.';
                    redirect('cliente/profilo.php');
                }
                $err = 'La password è già stata modificata. Ricarica la pagina e riprova.';
            } catch (mysqli_sql_exception $exception) {
                error_log('Cambio password cliente: ' . $exception->getMessage());
                $err = 'Impossibile aggiornare la password. Riprova.';
            }
        }
    } else {
        http_response_code(400);
        $err = 'Azione non riconosciuta.';
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <span class="section-subtitle">Impostazioni account</span>
        <h1 class="section-main-title">Il mio <span class="gold-highlight">profilo</span></h1>
    </div>

    <?php if ($msg !== ''): ?><div class="flash flash-ok" role="status"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div class="flash flash-err" role="alert"><?= e($err) ?></div><?php endif; ?>

    <div class="profilo-grid">
        <section class="luxury-data-card" aria-labelledby="dati-title">
            <div class="card-data-header"><h2 id="dati-title">Dati personali</h2></div>
            <form method="post" class="modal-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <div class="form-grid-2">
                    <div class="form-group"><label for="profilo-nome">Nome</label><input id="profilo-nome" type="text" name="nome" value="<?= e($dati['nome']) ?>" required maxlength="50" autocomplete="given-name"></div>
                    <div class="form-group"><label for="profilo-cognome">Cognome</label><input id="profilo-cognome" type="text" name="cognome" value="<?= e($dati['cognome']) ?>" required maxlength="50" autocomplete="family-name"></div>
                </div>
                <div class="form-group"><label for="profilo-email">Email</label><input id="profilo-email" type="email" value="<?= e($utente['email']) ?>" readonly></div>
                <div class="form-grid-2">
                    <div class="form-group"><label for="profilo-telefono">Telefono</label><input id="profilo-telefono" type="tel" name="telefono" value="<?= e($dati['telefono'] ?? '') ?>" maxlength="20" autocomplete="tel"></div>
                    <div class="form-group"><label for="profilo-nascita">Data di nascita</label><input id="profilo-nascita" type="date" name="data_nascita" value="<?= e($dati['data_nascita'] ?? '') ?>" min="1000-01-01" max="<?= e($oggi) ?>" autocomplete="bday"></div>
                </div>
                <div class="form-group"><label for="profilo-indirizzo">Indirizzo</label><input id="profilo-indirizzo" type="text" name="indirizzo" value="<?= e($dati['indirizzo'] ?? '') ?>" maxlength="255" autocomplete="street-address"></div>
                <button type="submit" class="btn-modal-save">Salva modifiche</button>
            </form>
        </section>

        <section class="luxury-data-card" aria-labelledby="password-title">
            <div class="card-data-header"><h2 id="password-title">Cambia password</h2></div>
            <form method="post" class="modal-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="password">
                <div class="form-group"><label for="password-attuale">Password attuale</label><input id="password-attuale" type="password" name="password_attuale" required autocomplete="current-password"></div>
                <div class="form-group"><label for="password-nuova">Nuova password</label><input id="password-nuova" type="password" name="password_nuova" required minlength="8" autocomplete="new-password" aria-describedby="password-note"><small id="password-note">Usa almeno 8 caratteri.</small></div>
                <div class="form-group"><label for="password-conferma">Conferma nuova password</label><input id="password-conferma" type="password" name="password_conf" required minlength="8" autocomplete="new-password"></div>
                <button type="submit" class="btn-modal-save">Cambia password</button>
            </form>
        </section>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
