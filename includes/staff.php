<?php
declare(strict_types=1);

function staffText(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function staffId(string $key, ?array $source = null): int
{
    $value = filter_var(staffText($source ?? $_POST, $key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? 0 : $value;
}

function staffIsoDate(string $value): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) {
        return '';
    }
    return (int) $parts[1] >= 1000 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : '';
}

function staffValidate(bool $condition, string $message): void
{
    if (!$condition) {
        throw new DomainException($message);
    }
}

function staffFlash(string $path): array
{
    $flash = $_SESSION['staff_flash'][$path] ?? ['', ''];
    unset($_SESSION['staff_flash'][$path]);
    return $flash;
}

function staffHandlePost(string $path, callable $operation): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    requireCsrf();
    try {
        $flash = [$operation(staffText($_POST, 'action')), ''];
    } catch (DomainException $exception) {
        $flash = ['', $exception->getMessage()];
    } catch (mysqli_sql_exception $exception) {
        error_log($exception->getMessage());
        $flash = ['', 'Operazione non riuscita. Aggiorna la pagina e riprova.'];
    }
    $_SESSION['staff_flash'][$path] = $flash;
    $query = $_GET ? '?' . http_build_query($_GET) : '';
    redirect($path . $query);
}

function staffTransaction(mysqli $conn, callable $operation): string
{
    $conn->begin_transaction();
    try {
        $message = $operation();
        $conn->commit();
        return $message;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function staffRoleId(mysqli $conn, string $role): int
{
    $statement = $conn->prepare('SELECT id_tipologia FROM TIPOLOGIA_UTENTE WHERE nome_tipologia = ?');
    $statement->bind_param('s', $role);
    $statement->execute();
    $id = (int) ($statement->get_result()->fetch_assoc()['id_tipologia'] ?? 0);
    staffValidate($id > 0, 'Ruolo non disponibile.');
    return $id;
}

function staffBarberId(mysqli $conn): int
{
    $id = $_SESSION['id_utente'];
    $statement = $conn->prepare('SELECT id_barbiere FROM BARBIERE WHERE id_utente = ?');
    $statement->bind_param('i', $id);
    $statement->execute();
    return (int) ($statement->get_result()->fetch_assoc()['id_barbiere'] ?? 0);
}

function staffBookingStates(): array
{
    return [
        'Prenotato' => ['role-prenotato', 'Prenotato'],
        'Confermata' => ['role-confermata', 'Confermata'],
        'Completata' => ['role-completata', 'Completata'],
        'Annullata' => ['role-annullata', 'Annullata'],
        'No_Show' => ['role-noshow', 'Assente'],
    ];
}

function staffBookingBadge(?string $state): string
{
    [$class, $label] = staffBookingStates()[$state] ?? ['role-cliente', '—'];
    return '<span class="role-badge-pill ' . $class . '">' . e($label) . '</span>';
}

function staffMoney($value): string
{
    return number_format((float) $value, 2, ',', '.') . ' €';
}

function staffBookingDetailsSql(): string
{
    return "SELECT d.id_prenotazione, GROUP_CONCAT(s.nome_servizio ORDER BY d.id_dettaglio SEPARATOR ', ') AS nome_servizio,
                   SUM(d.prezzo_applicato) AS prezzo_applicato
            FROM DETTAGLIO_PRENOTAZIONE d LEFT JOIN SERVIZIO s ON s.id_servizio = d.id_servizio
            GROUP BY d.id_prenotazione";
}
