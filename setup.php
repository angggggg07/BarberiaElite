<?php

declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (!setupAvailable()) {
    appError(404, 'Configurazione non disponibile', 'La creazione del primo amministratore si esegue da localhost su una nuova installazione.');
}

function setupValue(string $key, bool $trim = true): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? ($trim ? trim($value) : $value) : '';
}

$nome = $cognome = $email = $errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $nome = setupValue('nome');
    $cognome = setupValue('cognome');
    $email = setupValue('email');
    $password = setupValue('password', false);
    $conferma = setupValue('password2', false);

    if (!preg_match('/\A.{1,50}\z/us', $nome) || !preg_match('/\A.{1,50}\z/us', $cognome)) {
        $errore = 'Inserisci nome e cognome, ciascuno fino a 50 caratteri.';
    } elseif (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Inserisci un indirizzo email valido.';
    } elseif (!preg_match('/\A.{12,}\z/us', $password) || strlen($password) > 72 || strpos($password, "\0") !== false) {
        $errore = 'Usa almeno 12 caratteri e fino a 72 byte per la password.';
    } elseif ($password !== $conferma) {
        $errore = 'Le due password devono coincidere.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        try {
            $role = $conn->query(
                "SELECT id_tipologia FROM TIPOLOGIA_UTENTE WHERE nome_tipologia = 'Admin' FOR UPDATE"
            )->fetch_assoc();
            if (!$role) {
                throw new RuntimeException('Ruolo di amministratore mancante.');
            }
            if (!firstAdminMissing()) {
                $conn->rollback();
                redirect('index.php');
            }
            $roleId = (int) $role['id_tipologia'];
            $createUser = $conn->prepare(
                'INSERT INTO UTENTE (nome, cognome, email, password_hash, id_tipologia) VALUES (?, ?, ?, ?, ?)'
            );
            $createUser->bind_param('ssssi', $nome, $cognome, $email, $hash, $roleId);
            $createUser->execute();
            $adminId = $conn->insert_id;
            $createUser->close();

            $createAdmin = $conn->prepare('INSERT INTO ADMIN (id_utente, data_nomina) VALUES (?, CURRENT_DATE)');
            $createAdmin->bind_param('i', $adminId);
            $createAdmin->execute();
            $createAdmin->close();
            $conn->commit();
            $_SESSION['admin_setup_complete'] = true;
            redirect('index.php');
        } catch (Throwable $exception) {
            $conn->rollback();
            if ((int) $exception->getCode() === 1062) {
                $errore = 'L’indirizzo email è già associato a un account.';
            } else {
                error_log('Barberia Elite, configurazione: ' . $exception->getMessage());
                $errore = 'La configurazione non è riuscita. Riprova.';
            }
        }
    }
}

$pageTitle = 'Prima configurazione';
$pageClass = 'setup-page';
require __DIR__ . '/includes/header.php';
?>
<section class="setup-panel" aria-labelledby="setup-title">
    <h1 id="setup-title">Crea l’amministratore</h1>
    <p>Imposta le credenziali con cui accederai al gestionale di Barberia Elite.</p>
    <?php if ($errore !== ''): ?>
        <p class="auth-alert is-error" role="alert"><?= e($errore) ?></p>
    <?php endif; ?>
    <form class="auth-form" method="post">
        <?= csrfField() ?>
        <div class="field">
            <label for="setup-nome">Nome</label>
            <input id="setup-nome" name="nome" value="<?= e($nome) ?>" autocomplete="given-name" required maxlength="50">
        </div>
        <div class="field">
            <label for="setup-cognome">Cognome</label>
            <input id="setup-cognome" name="cognome" value="<?= e($cognome) ?>" autocomplete="family-name" required maxlength="50">
        </div>
        <div class="field">
            <label for="setup-email">Email</label>
            <input id="setup-email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" required maxlength="100">
        </div>
        <div class="field">
            <label for="setup-password">Password</label>
            <input id="setup-password" name="password" type="password" autocomplete="new-password" aria-describedby="setup-password-help" required minlength="12" maxlength="72">
            <small id="setup-password-help">Almeno 12 caratteri.</small>
        </div>
        <div class="field">
            <label for="setup-conferma">Conferma password</label>
            <input id="setup-conferma" name="password2" type="password" autocomplete="new-password" required minlength="12" maxlength="72">
        </div>
        <button class="button-primary" type="submit">Crea amministratore</button>
    </form>
    <p><a href="<?= e(appUrl('index.php')) ?>">Torna all’accesso</a></p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
