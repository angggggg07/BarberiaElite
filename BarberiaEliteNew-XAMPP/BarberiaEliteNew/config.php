<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Rome');

function appUrl(string $path = ''): string
{
    static $base;
    if ($base === null) {
        $project = str_replace('\\', '/', __DIR__);
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        $route = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $relativeScript = substr($script, strlen($project) + 1);
        $directory = dirname($route, 1 + substr_count($relativeScript, '/'));
        $base = rtrim(str_replace('\\', '/', $directory), '/');
        if ($base === '.') {
            $base = '';
        }
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    $status = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 303 : 302;
    header('Location: ' . appUrl($path), true, $status);
    exit;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function requireCsrf(): void
{
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        appError(403, 'Richiesta scaduta', 'Ricarica la pagina e invia di nuovo il modulo.');
    }
}

function appError(int $status, string $title, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="it"><meta charset="UTF-8">',
         '<meta name="viewport" content="width=device-width, initial-scale=1">',
         '<title>', e($title), ' | Barberia Elite</title>',
         '<link rel="stylesheet" href="', e(appUrl('assets/css/style.css')), '">',
         '<body><main class="setup-panel"><h1>', e($title), '</h1><p>', e($message),
         '</p><a href="', e(appUrl('index.php')), '">Torna all’inizio</a></main></body></html>';
    exit;
}

function firstAdminMissing(): bool
{
    global $conn;
    $result = $conn->query(
        "SELECT 1 FROM UTENTE u JOIN TIPOLOGIA_UTENTE t ON t.id_tipologia = u.id_tipologia
         WHERE t.nome_tipologia = 'Admin' LIMIT 1"
    );
    return $result->num_rows === 0;
}

function setupAvailable(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && firstAdminMissing();
}

function requireAuth(?string $ruolo = null): void
{
    global $conn;
    $id = (int) ($_SESSION['id_utente'] ?? 0);
    if ($id < 1) {
        redirect('index.php');
    }
    $statement = $conn->prepare(
        "SELECT u.nome, u.cognome, t.nome_tipologia
         FROM UTENTE u JOIN TIPOLOGIA_UTENTE t ON t.id_tipologia = u.id_tipologia
         LEFT JOIN BARBIERE b ON b.id_utente = u.id_utente
         LEFT JOIN CLIENTE c ON c.id_utente = u.id_utente
         WHERE u.id_utente = ? AND u.stato = 'Attivo' AND (
             t.nome_tipologia = 'Admin'
             OR (t.nome_tipologia = 'Barbiere' AND b.id_barbiere IS NOT NULL)
             OR (t.nome_tipologia = 'Cliente' AND c.id_cliente IS NOT NULL)
         )"
    );
    $statement->bind_param('i', $id);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$user) {
        unset($_SESSION['id_utente'], $_SESSION['nome'], $_SESSION['cognome'], $_SESSION['ruolo']);
        redirect('index.php');
    }
    $_SESSION['nome'] = $user['nome'];
    $_SESSION['cognome'] = $user['cognome'];
    $_SESSION['ruolo'] = $user['nome_tipologia'];
    if ($ruolo !== null && $ruolo !== $_SESSION['ruolo']) {
        redirect('dashboard.php');
    }
}

function dbEnvironment(string $key, string $default): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function databaseReady(mysqli $connection): bool
{
    try {
        $result = $connection->query('SELECT versione FROM APP_SCHEMA WHERE id = 1');
        return (int) ($result->fetch_assoc()['versione'] ?? 0) >= 1;
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() !== 1146) {
            throw $exception;
        }
        return false;
    }
}

function databaseInstall(mysqli $connection, string $database): void
{
    $lockName = 'elite_' . substr(hash('sha256', $database), 0, 48);
    $lock = $connection->query("SELECT GET_LOCK('$lockName', 15) AS acquired")->fetch_assoc();
    if ((int) $lock['acquired'] !== 1) {
        throw new RuntimeException('Timeout durante la preparazione dello schema.');
    }
    try {
        if (databaseReady($connection)) {
            return;
        }
        $definition = file_get_contents(__DIR__ . '/database.sql');
        $parts = explode('-- INIZIO SCHEMA', $definition ?: '', 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('database.sql mancante o incompleto.');
        }
        $connection->multi_query($parts[1]);
        do {
            $result = $connection->store_result();
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        } while ($connection->more_results() && $connection->next_result());

        // Gli archivi precedenti possono avere recensioni senza il legame alla prenotazione.
        if (!databaseReady($connection)) {
            $column = $connection->query("SHOW COLUMNS FROM RECENSIONE LIKE 'id_prenotazione'");
            if ($column->num_rows === 0) {
                $connection->query(
                    'ALTER TABLE RECENSIONE
                     ADD COLUMN id_prenotazione INT DEFAULT NULL AFTER id_barbiere,
                     ADD CONSTRAINT fk_rec_prn FOREIGN KEY (id_prenotazione)
                        REFERENCES PRENOTAZIONE(id_prenotazione) ON DELETE SET NULL'
                );
            }
            $connection->query('INSERT INTO APP_SCHEMA (id, versione) VALUES (1, 1)');
        }
    } finally {
        $connection->query("DO RELEASE_LOCK('$lockName')");
    }
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'path' => appUrl(),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!extension_loaded('mysqli')) {
    appError(503, 'Estensione MySQL non disponibile', 'Attiva mysqli in PHP e riavvia Apache.');
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = dbEnvironment('DB_NAME', 'barberia_elite_db');
    if (!preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $database)) {
        throw new RuntimeException('DB_NAME non valido.');
    }
    $conn = new mysqli(
        dbEnvironment('DB_HOST', 'localhost'),
        dbEnvironment('DB_USER', 'root'),
        dbEnvironment('DB_PASSWORD', ''),
        null,
        (int) dbEnvironment('DB_PORT', '3306')
    );
    $conn->set_charset('utf8mb4');
    $offset = date('P');
    $conn->query("SET time_zone = '$offset'");
    try {
        $conn->select_db($database);
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() !== 1049) {
            throw $exception;
        }
        $conn->query("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->select_db($database);
    }
    if (!databaseReady($conn)) {
        databaseInstall($conn, $database);
    }
} catch (Throwable $exception) {
    error_log('Barberia Elite, database: ' . $exception->getMessage());
    appError(503, 'Database non disponibile', 'Avvia MySQL dal pannello XAMPP e controlla i parametri di connessione. Se il database esiste già, verifica di aver importato database.sql.');
}
