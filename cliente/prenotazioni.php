<?php
require_once __DIR__ . '/../config.php';
requireAuth('Cliente');

$message = $_SESSION['cliente_prenotazioni_msg'] ?? '';
unset($_SESSION['cliente_prenotazioni_msg']);
$error = '';
$now = date('Y-m-d H:i:s');
$filterInput = filter_input(INPUT_GET, 'filtro');
$filter = is_string($filterInput) ? $filterInput : 'future';
$filters = ['future' => 'Prossimi', 'passate' => 'Storico', 'tutte' => 'Tutti'];
if (!array_key_exists($filter, $filters)) {
    $filter = 'future';
}

$stmt = $conn->prepare('SELECT id_cliente FROM CLIENTE WHERE id_utente = ?');
$stmt->bind_param('i', $_SESSION['id_utente']);
$stmt->execute();
$clientId = (int) ($stmt->get_result()->fetch_assoc()['id_cliente'] ?? 0);
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (filter_input(INPUT_POST, 'action') ?? '') === 'annulla') {
    requireCsrf();
    $bookingId = filter_input(INPUT_POST, 'id_prenotazione', FILTER_VALIDATE_INT) ?: 0;
    $canceled = false;

    if ($bookingId > 0 && $clientId > 0) {
        $conn->begin_transaction();
        try {

            $stmt = $conn->prepare('SELECT id_barbiere FROM PRENOTAZIONE WHERE id_prenotazione = ? AND id_cliente = ?');
            $stmt->bind_param('ii', $bookingId, $clientId);
            $stmt->execute();
            $bookingRef = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($bookingRef) {
                $barberId = (int) ($bookingRef['id_barbiere'] ?? 0);
                if ($barberId > 0) {
                    $lockBarber = $conn->prepare('SELECT id_barbiere FROM BARBIERE WHERE id_barbiere = ? FOR UPDATE');
                    $lockBarber->bind_param('i', $barberId);
                    $lockBarber->execute();
                    $lockBarber->get_result();
                    $lockBarber->close();
                }

                $lockClient = $conn->prepare('SELECT id_cliente FROM CLIENTE WHERE id_cliente = ? FOR UPDATE');
                $lockClient->bind_param('i', $clientId);
                $lockClient->execute();
                $lockClient->get_result();
                $lockClient->close();

                $lockBooking = $conn->prepare("SELECT data_ora, stato FROM PRENOTAZIONE
                    WHERE id_prenotazione = ? AND id_cliente = ? FOR UPDATE");
                $lockBooking->bind_param('ii', $bookingId, $clientId);
                $lockBooking->execute();
                $booking = $lockBooking->get_result()->fetch_assoc();
                $lockBooking->close();

                if ($booking) {
                    $cancelAt = date('Y-m-d H:i:s');
                }
                if ($booking && $booking['data_ora'] > ($cancelAt ?? '')
                    && in_array($booking['stato'], ['Prenotato', 'Confermata'], true)) {
                    $update = $conn->prepare("UPDATE PRENOTAZIONE SET stato = 'Annullata'
                        WHERE id_prenotazione = ? AND id_cliente = ? AND data_ora > ? AND stato IN ('Prenotato', 'Confermata')");
                    $update->bind_param('iis', $bookingId, $clientId, $cancelAt);
                    $update->execute();
                    $canceled = $update->affected_rows === 1;
                    $update->close();
                }
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Barberia Elite, annullamento prenotazione: ' . $exception->getMessage());
            $error = 'Non è stato possibile annullare la prenotazione. Riprova.';
        }
    }

    if ($canceled) {
        $_SESSION['cliente_prenotazioni_msg'] = 'Prenotazione annullata.';
        redirect('cliente/prenotazioni.php?filtro=' . $filter);
    }
    if ($error === '') {
        $error = 'Questa prenotazione non è più annullabile.';
    }
}

$timeCondition = match ($filter) {
    'future' => 'AND p.data_ora >= ?',
    'passate' => 'AND p.data_ora < ?',
    default => '',
};
$order = $filter === 'future' ? 'ASC' : 'DESC';

$stmt = $conn->prepare("
    SELECT p.id_prenotazione, p.data_ora, p.stato,
           TIMESTAMPDIFF(MINUTE, p.data_ora, p.data_fine) AS durata,
           CONCAT(u.nome, ' ', u.cognome) AS barbiere,
           GROUP_CONCAT(s.nome_servizio ORDER BY dp.id_dettaglio SEPARATOR ', ') AS servizi,
           SUM(dp.prezzo_applicato) AS totale
    FROM PRENOTAZIONE p
    LEFT JOIN BARBIERE b ON b.id_barbiere = p.id_barbiere
    LEFT JOIN UTENTE u ON u.id_utente = b.id_utente
    LEFT JOIN DETTAGLIO_PRENOTAZIONE dp ON dp.id_prenotazione = p.id_prenotazione
    LEFT JOIN SERVIZIO s ON s.id_servizio = dp.id_servizio
    WHERE p.id_cliente = ? $timeCondition
    GROUP BY p.id_prenotazione, p.data_ora, p.stato, p.data_fine, u.nome, u.cognome
    ORDER BY p.data_ora $order
");
if ($filter === 'tutte') {
    $stmt->bind_param('i', $clientId);
} else {
    $stmt->bind_param('is', $clientId, $now);
}
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$statuses = [
    'Prenotato' => ['role-prenotato', 'Prenotato'],
    'Confermata' => ['role-confermata', 'Confermata'],
    'Completata' => ['role-completata', 'Completata'],
    'Annullata' => ['role-annullata', 'Annullata'],
    'No_Show' => ['role-noshow', 'Assente'],
];

$pageTitle = 'Le mie prenotazioni';
include __DIR__ . '/../includes/header.php';
?>
<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <h1 class="section-main-title">Le mie <span class="gold-highlight">prenotazioni</span></h1>
    </div>

    <?php if ($message): ?><div class="flash flash-ok" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash flash-err" role="alert"><?= e($error) ?></div><?php endif; ?>

    <div class="tab-bar">
        <?php foreach ($filters as $key => $label): ?>
        <a href="?filtro=<?= e($key) ?>" class="tab-item <?= $key === $filter ? 'tab-active' : '' ?>"<?= $key === $filter ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
        <a href="nuova-prenotazione.php" class="tab-item tab-book">Nuovo appuntamento</a>
    </div>

    <section class="luxury-data-card">
        <div class="card-data-header">
            <div>
                <h2>Appuntamenti (<?= count($appointments) ?>)</h2>
                <p>Puoi annullare un appuntamento prenotato o confermato prima dell’orario di inizio.</p>
            </div>
        </div>
        <div class="table-responsive-wrapper">
            <table class="luxury-table" aria-label="I tuoi appuntamenti">
                <thead><tr>
                    <th scope="col">Data e ora</th><th scope="col">Barbiere</th><th scope="col">Servizio</th>
                    <th scope="col">Durata</th><th scope="col">Prezzo</th><th scope="col">Stato</th><th scope="col" class="text-right">Azioni</th>
                </tr></thead>
                <tbody>
                <?php if (!$appointments): ?>
                <tr><td colspan="7" class="empty-td">Nessun appuntamento in questa vista.</td></tr>
                <?php endif; ?>
                <?php foreach ($appointments as $appointment): ?>
                <?php
                    $start = strtotime($appointment['data_ora']);
                    [$statusClass, $statusLabel] = $statuses[$appointment['stato']] ?? ['role-cliente', '—'];
                ?>
                <tr>
                    <td><strong><?= e(date('d/m/Y', $start)) ?></strong><br><span class="time-slot"><?= e(date('H:i', $start)) ?></span></td>
                    <td><?= e($appointment['barbiere'] ?? '—') ?></td>
                    <td class="gold-text-highlight"><?= e($appointment['servizi'] ?? '—') ?></td>
                    <td><?= $appointment['durata'] !== null ? e($appointment['durata']) . ' min' : '—' ?></td>
                    <td><?= $appointment['totale'] !== null ? number_format((float) $appointment['totale'], 2, ',', '.') . ' €' : '—' ?></td>
                    <td><span class="role-badge-pill <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                    <td class="text-right">
                        <?php if (in_array($appointment['stato'], ['Prenotato', 'Confermata'], true) && $appointment['data_ora'] > $now): ?>
                        <form method="post" onsubmit="return confirm('Annullare questo appuntamento?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="annulla">
                            <input type="hidden" name="id_prenotazione" value="<?= e($appointment['id_prenotazione']) ?>">
                            <button type="submit" class="crud-btn btn-delete">Annulla</button>
                        </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
