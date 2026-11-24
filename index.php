<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (isset($_SESSION['id_utente'])) {
    redirect('dashboard.php');
}

$pageTitle = 'Barberia Elite | Bari';
$pageClass = 'public-page';
$tab = ($_GET['tab'] ?? '') === 'registrati' ? 'registrati' : 'login';
$error = '';
$success = !empty($_SESSION['admin_setup_complete'])
    ? 'Amministratore configurato. Ora puoi accedere.'
    : ($_SESSION['registration_success'] ?? '');
unset($_SESSION['registration_success'], $_SESSION['admin_setup_complete']);
$values = ['nome' => '', 'cognome' => '', 'email' => '', 'telefono' => '', 'data_nascita' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $tab = ($_POST['form_type'] ?? '') === 'registrati' ? 'registrati' : 'login';
    foreach ($values as $key => $unused) {
        $values[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    $email = $values['email'];
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($tab === 'login') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100 || $password === '' || strlen($password) > 4096) {
            $error = 'Inserisci un indirizzo email valido e la tua password.';
        } else {
            $stmt = $conn->prepare("SELECT u.id_utente, u.nome, u.cognome, u.password_hash, t.nome_tipologia
                FROM UTENTE u JOIN TIPOLOGIA_UTENTE t USING (id_tipologia)
                WHERE u.email = ? AND u.stato = 'Attivo'");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION = [
                    'id_utente' => (int) $user['id_utente'],
                    'ruolo' => $user['nome_tipologia'],
                    'nome' => $user['nome'],
                    'cognome' => $user['cognome'],
                ];
                redirect('dashboard.php');
            }
            $error = 'Credenziali non valide o account sospeso.';
        }
    } else {
        $nome = $values['nome'];
        $cognome = $values['cognome'];
        $phone = $values['telefono'] ?: null;
        $birth = $values['data_nascita'] ?: null;
        $birthday = $birth !== null && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $birth)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $birth)
            : null;

        if ($nome === '' || $cognome === '' || mb_strlen($nome) > 50 || mb_strlen($cognome) > 50) {
            $error = 'Inserisci nome e cognome, fino a 50 caratteri ciascuno.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $error = 'Inserisci un indirizzo email valido.';
        } elseif ($phone !== null && !preg_match('/\A[+0-9() .\-]{5,20}\z/', $phone)) {
            $error = 'Controlla il numero di telefono.';
        } elseif ($birth !== null && (!$birthday || $birthday->format('Y-m-d') !== $birth || $birth < '1900-01-01' || $birthday > new DateTimeImmutable('today'))) {
            $error = 'Inserisci una data di nascita valida.';
        } elseif (mb_strlen($password) < 8 || strlen($password) > 72 || strpos($password, "\0") !== false) {
            $error = 'Usa almeno 8 caratteri e fino a 72 byte per la password.';
        } elseif ($password !== ($_POST['password2'] ?? null)) {
            $error = 'Le due password non coincidono.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO UTENTE (nome, cognome, email, telefono, password_hash, id_tipologia)
                    VALUES (?, ?, ?, ?, ?, (SELECT id_tipologia FROM TIPOLOGIA_UTENTE WHERE nome_tipologia = 'Cliente' LIMIT 1))");
                $stmt->bind_param('sssss', $nome, $cognome, $email, $phone, $hash);
                $stmt->execute();
                $id = (int) $conn->insert_id;
                $stmt->close();

                $stmt = $conn->prepare('INSERT INTO CLIENTE (id_utente, data_nascita) VALUES (?, ?)');
                $stmt->bind_param('is', $id, $birth);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $_SESSION['registration_success'] = 'Account creato. Ora puoi accedere.';
                redirect('index.php#accesso');
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                if ((int) $exception->getCode() === 1062) {
                    $error = 'Questa email è già registrata.';
                } else {
                    error_log($exception->getMessage());
                    $error = 'La registrazione non è riuscita. Riprova tra poco.';
                }
            }
        }
    }
}

$services = $conn->query('SELECT nome_servizio, descrizione, durata_minuti, prezzo
    FROM SERVIZIO WHERE attivo = 1 ORDER BY id_servizio')->fetch_all(MYSQLI_ASSOC);
$branches = $conn->query('SELECT nome_sede, indirizzo FROM SEDE ORDER BY id_sede')->fetch_all(MYSQLI_ASSOC);
$setupNeeded = setupAvailable();
$registering = $tab === 'registrati';

require __DIR__ . '/includes/header.php';
?>
<section class="entrance">
    <div class="entrance-visual">
        <img src="<?= e(appUrl('assets/media/taglio.webp')) ?>" alt="" fetchpriority="high">
        <div class="entrance-copy">
            <p class="eyebrow">Barberia Elite · Bari</p>
            <h1>Taglio preciso.<br><em>Barba curata.</em></h1>
            <p>Taglio su misura, rasatura a mano libera e il rituale del panno caldo.</p>
            <a class="visual-link" href="#listino">Scopri i servizi</a>
        </div>
    </div>
    <section class="entrance-account" id="accesso" aria-labelledby="access-title">
        <div class="auth-box">
            <p class="eyebrow">Area riservata</p>
            <h2 id="access-title"><?= $registering ? 'Crea il tuo account' : 'Bentornato.' ?></h2>
            <p class="auth-intro"><?= $registering ? 'Registrati per prenotare e gestire i tuoi appuntamenti.' : 'Accedi per gestire i tuoi appuntamenti.' ?></p>
            <nav class="auth-switch" aria-label="Tipo di accesso">
                <a href="<?= e(appUrl('index.php#accesso')) ?>" <?= !$registering ? 'aria-current="page"' : '' ?>>Accedi</a>
                <a href="<?= e(appUrl('index.php?tab=registrati#accesso')) ?>" <?= $registering ? 'aria-current="page"' : '' ?>>Registrati</a>
            </nav>
            <?php if ($error !== ''): ?>
                <p class="auth-alert is-error" role="alert" tabindex="-1" data-focus-alert><?= e($error) ?></p>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <p class="auth-alert" role="status"><?= e($success) ?></p>
            <?php endif; ?>
            <form method="post" class="auth-form" action="<?= e(appUrl('index.php')) ?>">
                <?= csrfField() ?>
                <input type="hidden" name="form_type" value="<?= e($tab) ?>">
                <?php if ($registering): ?>
                    <div class="field-pair">
                        <label class="field">Nome<input name="nome" required maxlength="50" autocomplete="given-name" value="<?= e($values['nome']) ?>"></label>
                        <label class="field">Cognome<input name="cognome" required maxlength="50" autocomplete="family-name" value="<?= e($values['cognome']) ?>"></label>
                    </div>
                <?php endif; ?>
                <label class="field">Email<input type="email" name="email" required maxlength="100" autocomplete="email" value="<?= e($values['email']) ?>"></label>
                <?php if ($registering): ?>
                    <div class="field-pair">
                        <label class="field">Telefono (facoltativo)<input name="telefono" type="tel" maxlength="20" autocomplete="tel" value="<?= e($values['telefono']) ?>"></label>
                        <label class="field">Data di nascita (facoltativa)<input type="date" name="data_nascita" min="1900-01-01" max="<?= date('Y-m-d') ?>" autocomplete="bday" value="<?= e($values['data_nascita']) ?>"></label>
                    </div>
                <?php endif; ?>
                <div class="password-field">
                    <label class="field" for="account-password">Password</label>
                    <input class="password-input" id="account-password" type="password" name="password" required
                        <?= $registering ? 'minlength="8" maxlength="72" autocomplete="new-password"' : 'maxlength="4096" autocomplete="current-password"' ?>>
                    <button type="button" class="password-toggle" data-password-toggle="account-password" aria-pressed="false" hidden>Mostra</button>
                </div>
                <?php if ($registering): ?>
                    <label class="field">Conferma password<input type="password" name="password2" required minlength="8" maxlength="72" autocomplete="new-password"></label>
                <?php endif; ?>
                <button type="submit" class="button-primary"><?= $registering ? 'Crea account' : 'Accedi al gestionale' ?></button>
            </form>
            <?php if ($setupNeeded): ?>
                <p class="setup-note">Prima installazione? <a href="<?= e(appUrl('setup.php')) ?>">Configura l’amministratore</a></p>
            <?php endif; ?>
        </div>
    </section>
</section>
<section class="public-services" id="listino" aria-labelledby="listino-title">
    <div class="public-width">
        <p class="eyebrow">Il listino</p>
        <h2 id="listino-title">Taglio, barba <em>e viso.</em></h2>
        <?php if (!$services): ?>
            <p>Il listino sarà disponibile a breve.</p>
        <?php else: ?>
            <dl class="rate-list">
                <?php foreach ($services as $service): ?>
                    <div>
                        <dt><?= e($service['nome_servizio']) ?></dt>
                        <dd><?= e($service['descrizione'] ?? '') ?></dd>
                        <dd class="public-rate"><strong><?= number_format((float) $service['prezzo'], 2, ',', '.') ?> €</strong><span><?= (int) $service['durata_minuti'] ?> min</span></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </div>
</section>
<?php if ($branches): ?>
    <section id="sede" class="public-location" aria-labelledby="location-title">
        <div class="public-width location-inner">
            <img src="<?= e(appUrl('assets/media/barberia.webp')) ?>" alt="Poltrona da barbiere e specchi in legno, immagine illustrativa" width="1200" height="900" loading="lazy">
            <div>
                <p class="eyebrow">Dove siamo</p>
                <h2 id="location-title">Vieni a <em>trovarci.</em></h2>
                <?php foreach ($branches as $branch): ?>
                    <address><strong><?= e($branch['nome_sede']) ?></strong><br><?= e($branch['indirizzo']) ?></address>
                    <a class="visual-link" href="<?= e('https://www.google.com/maps/search/?api=1&query=' . rawurlencode($branch['indirizzo'])) ?>">Indicazioni su Google Maps</a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
