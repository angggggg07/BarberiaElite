<?php
require_once __DIR__ . '/../config.php';
requireAuth('Cliente');

$userId = (int) $_SESSION['id_utente'];
$stmt = $conn->prepare('SELECT id_cliente FROM CLIENTE WHERE id_utente = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$cliente = $stmt->get_result()->fetch_assoc();
$stmt->close();

$error = $cliente ? '' : 'Profilo cliente non disponibile.';
$barberId = 0;
$serviceId = filter_input(INPUT_GET, 'servizio', FILTER_VALIDATE_INT) ?: 0;
$requestedAt = '';
$notes = '';
$notesInputValid = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $barberId = filter_input(INPUT_POST, 'id_barbiere', FILTER_VALIDATE_INT) ?: 0;
    $serviceId = filter_input(INPUT_POST, 'id_servizio', FILTER_VALIDATE_INT) ?: 0;
    $requestedAtInput = filter_input(INPUT_POST, 'data_ora');
    $requestedAt = is_string($requestedAtInput) ? $requestedAtInput : '';
    $notesInput = filter_input(INPUT_POST, 'note');
    $notesInputValid = $notesInput === null || is_string($notesInput);
    $notes = is_string($notesInput) ? trim($notesInput) : '';
    $start = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $requestedAt)
        ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $requestedAt)
        : false;

    if ($error === '' && ($barberId < 1 || $serviceId < 1 || !$start || $start->format('Y-m-d\TH:i') !== $requestedAt)) {
        $error = 'Seleziona il barbiere, il servizio e una data valida.';
    }
    if ($error === '' && $start <= new DateTimeImmutable()) {
        $error = 'Scegli un orario futuro.';
    }
    if ($error === '' && !$notesInputValid) {
        $error = 'Il campo note non è valido.';
    }
    if ($error === '' && preg_match('/\A.{0,1000}\z/us', $notes) !== 1) {
        $error = 'Le note sono troppo lunghe.';
    }

    if ($error === '') {
        $clientId = (int) $cliente['id_cliente'];
        $conn->begin_transaction();
        try {

            $stmt = $conn->prepare("
                SELECT b.id_sede
                FROM BARBIERE b JOIN UTENTE u ON u.id_utente = b.id_utente
                WHERE b.id_barbiere = ? AND b.attivo = 1 AND u.stato = 'Attivo'
                FOR UPDATE
            ");
            $stmt->bind_param('i', $barberId);
            $stmt->execute();
            $barber = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$barber || $barber['id_sede'] === null) {
                throw new DomainException('Il barbiere selezionato non è disponibile per le prenotazioni.');
            }
            $locationId = (int) $barber['id_sede'];

            $stmt = $conn->prepare('SELECT id_cliente FROM CLIENTE WHERE id_cliente = ? FOR UPDATE');
            $stmt->bind_param('i', $clientId);
            $stmt->execute();
            $lockedClient = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$lockedClient) {
                throw new DomainException('Profilo cliente non disponibile.');
            }

            $stmt = $conn->prepare('SELECT durata_minuti, prezzo FROM SERVIZIO WHERE id_servizio = ? AND attivo = 1');
            $stmt->bind_param('i', $serviceId);
            $stmt->execute();
            $service = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$service || (int) $service['durata_minuti'] < 1) {
                throw new DomainException('Il servizio selezionato non è più disponibile.');
            }

            $end = $start->add(new DateInterval('PT' . (int) $service['durata_minuti'] . 'M'));
            if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
                throw new DomainException('Il trattamento deve concludersi nello stesso giorno.');
            }

            $weekday = (int) $start->format('N');
            $startTime = $start->format('H:i:s');
            $endTime = $end->format('H:i:s');
            $stmt = $conn->prepare("
                SELECT id_orario FROM ORARIO_DISPONIBILE
                WHERE id_barbiere = ? AND giorno_settimana = ? AND attivo = 1
                  AND ora_inizio <= ? AND ora_fine >= ?
                LIMIT 1
            ");
            $stmt->bind_param('iiss', $barberId, $weekday, $startTime, $endTime);
            $stmt->execute();
            $availability = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$availability) {
                throw new DomainException('Il trattamento deve rientrare in una fascia oraria del barbiere.');
            }

            $startSql = $start->format('Y-m-d H:i:s');
            $endSql = $end->format('Y-m-d H:i:s');
            $stmt = $conn->prepare("
                SELECT id_barbiere FROM PRENOTAZIONE
                WHERE (id_barbiere = ? OR id_cliente = ?)
                  AND stato IN ('Prenotato', 'Confermata', 'Completata')
                  AND data_ora < ? AND data_fine > ?
                LIMIT 1
            ");
            $stmt->bind_param('iiss', $barberId, $clientId, $endSql, $startSql);
            $stmt->execute();
            $conflict = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($conflict) {
                throw new DomainException("L'orario si sovrappone a un appuntamento esistente. Scegli un altro orario.");
            }

            $stmt = $conn->prepare("
                INSERT INTO PRENOTAZIONE (id_cliente, id_barbiere, id_sede, data_ora, data_fine, stato, note)
                VALUES (?, ?, ?, ?, ?, 'Prenotato', ?)
            ");
            $stmt->bind_param('iiisss', $clientId, $barberId, $locationId, $startSql, $endSql, $notes);
            $stmt->execute();
            $bookingId = $stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO DETTAGLIO_PRENOTAZIONE (id_prenotazione, id_servizio, prezzo_applicato)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param('iis', $bookingId, $serviceId, $service['prezzo']);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            $_SESSION['cliente_prenotazioni_msg'] = 'Appuntamento prenotato.';
            redirect('cliente/prenotazioni.php');
        } catch (DomainException $failure) {
            $conn->rollback();
            $error = $failure->getMessage();
        } catch (mysqli_sql_exception $failure) {
            $conn->rollback();
            error_log($failure->getMessage());
            $error = 'Impossibile registrare la prenotazione. Riprova.';
        }
    }
}

$barbers = $conn->query("
    SELECT b.id_barbiere, CONCAT(u.nome, ' ', u.cognome) AS nome, b.specializzazione
    FROM BARBIERE b JOIN UTENTE u ON b.id_utente = u.id_utente
    WHERE b.attivo = 1 AND u.stato = 'Attivo' AND b.id_sede IS NOT NULL
      AND EXISTS (SELECT 1 FROM ORARIO_DISPONIBILE o WHERE o.id_barbiere = b.id_barbiere AND o.attivo = 1)
    ORDER BY u.cognome, u.nome
")->fetch_all(MYSQLI_ASSOC);

$weeklyHours = [];
foreach ($conn->query("
    SELECT id_barbiere, giorno_settimana, ora_inizio, ora_fine FROM ORARIO_DISPONIBILE
    WHERE attivo = 1 ORDER BY giorno_settimana, ora_inizio
")->fetch_all(MYSQLI_ASSOC) as $hours) {
    $weeklyHours[$hours['id_barbiere']][$hours['giorno_settimana']][] =
        substr($hours['ora_inizio'], 0, 5) . '–' . substr($hours['ora_fine'], 0, 5);
}
$weekdays = [1 => 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica'];

$services = [];
foreach ($conn->query("
    SELECT s.id_servizio, s.nome_servizio, s.prezzo, s.durata_minuti, c.nome_categoria
    FROM SERVIZIO s LEFT JOIN CATEGORIA_SERVIZIO c ON s.id_categoria = c.id_categoria
    WHERE s.attivo = 1 ORDER BY c.nome_categoria, s.nome_servizio
")->fetch_all(MYSQLI_ASSOC) as $service) {
    $services[$service['nome_categoria'] ?? 'Altri servizi'][] = $service;
}

$pageTitle = 'Nuovo appuntamento';
include __DIR__ . '/../includes/header.php';
?>
<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <h1 class="section-main-title">Nuovo <span class="gold-highlight">appuntamento</span></h1>
    </div>

    <?php if ($error !== ''): ?>
    <div class="flash flash-err" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$barbers || !$services): ?>
    <div class="empty-state"><p>Le prenotazioni non sono ancora disponibili. Riprova quando saranno pubblicati i servizi e gli orari dello staff.</p></div>
    <?php endif; ?>

    <div class="booking-layout">
        <section class="luxury-data-card">
            <div class="card-data-header"><div><h2>Dettagli appuntamento</h2><p>Scegli un orario in cui il servizio possa concludersi nella fascia di lavoro.</p></div></div>
            <form method="post" class="modal-form">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="sel_barbiere">Barbiere</label>
                    <select name="id_barbiere" required id="sel_barbiere">
                        <option value="">Seleziona un barbiere</option>
                        <?php foreach ($barbers as $barber): ?>
                        <option value="<?= e($barber['id_barbiere']) ?>" <?= $barberId === (int) $barber['id_barbiere'] ? 'selected' : '' ?>>
                            <?= e($barber['nome']) ?><?= $barber['specializzazione'] ? ' · ' . e($barber['specializzazione']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sel_servizio">Servizio</label>
                    <select name="id_servizio" id="sel_servizio" required>
                        <option value="">Seleziona un servizio</option>
                        <?php foreach ($services as $category => $items): ?>
                        <optgroup label="<?= e($category) ?>">
                            <?php foreach ($items as $service): ?>
                            <option value="<?= e($service['id_servizio']) ?>" <?= $serviceId === (int) $service['id_servizio'] ? 'selected' : '' ?>>
                                <?= e($service['nome_servizio']) ?> · <?= number_format((float) $service['prezzo'], 2, ',', '.') ?> € · <?= e($service['durata_minuti']) ?> min
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="data_ora">Data e ora</label>
                    <input type="datetime-local" name="data_ora" id="data_ora" required
                           min="<?= e(date('Y-m-d\TH:i', time() + 60)) ?>" value="<?= e($requestedAt) ?>">
                </div>

                <div class="form-group">
                    <label for="booking_note">Note (facoltativo)</label>
                    <textarea name="note" id="booking_note" rows="3" maxlength="1000" placeholder="Preferenze per il servizio"><?= e($notes) ?></textarea>
                </div>

                <button type="submit" class="btn-premium" <?= !$barbers || !$services || !$cliente ? 'disabled' : '' ?>>Prenota appuntamento</button>
            </form>
        </section>

        <section class="luxury-data-card" aria-labelledby="orari-title">
            <div class="card-data-header"><div><h2 id="orari-title">Orari dei barbieri</h2><p>Fasce di lavoro settimanali. La disponibilità effettiva viene verificata al momento della prenotazione.</p></div></div>
            <div class="modal-form" id="barber-hours">
                <?php foreach ($barbers as $barber): ?>
                <section data-barber="<?= e($barber['id_barbiere']) ?>">
                    <h3 class="service-cat-title"><?= e($barber['nome']) ?></h3>
                    <dl>
                        <?php foreach ($weeklyHours[$barber['id_barbiere']] ?? [] as $day => $hours): ?>
                        <div class="service-list-item">
                            <dt class="sli-name"><?= e($weekdays[$day] ?? '') ?></dt>
                            <dd class="sli-meta"><?= e(implode(' · ', $hours)) ?></dd>
                        </div>
                        <?php endforeach; ?>
                    </dl>
                </section>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<script>
const barberSelect = document.getElementById('sel_barbiere');
const barberSchedules = document.querySelectorAll('#barber-hours [data-barber]');
const showSchedules = () => barberSchedules.forEach(schedule => {
    schedule.hidden = barberSelect.value !== '' && schedule.dataset.barber !== barberSelect.value;
});
barberSelect.addEventListener('change', showSchedules);
showSchedules();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
