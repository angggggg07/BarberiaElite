<?php
require_once __DIR__ . '/../config.php';
requireAuth('Cliente');

$message = $_SESSION['cliente_recensioni_msg'] ?? '';
unset($_SESSION['cliente_recensioni_msg']);
$error = '';
$bookingId = 0;
$rating = 0;
$comment = '';

$stmt = $conn->prepare('SELECT id_cliente FROM CLIENTE WHERE id_utente = ?');
$stmt->bind_param('i', $_SESSION['id_utente']);
$stmt->execute();
$clientId = (int) ($stmt->get_result()->fetch_assoc()['id_cliente'] ?? 0);
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $bookingId = filter_input(INPUT_POST, 'id_prenotazione', FILTER_VALIDATE_INT) ?: 0;
    $rating = filter_input(INPUT_POST, 'valutazione', FILTER_VALIDATE_INT) ?: 0;
    $comment = trim(filter_input(INPUT_POST, 'commento') ?? '');

    if (!$clientId || !$bookingId) {
        $error = 'Seleziona un appuntamento completato.';
    } elseif ($rating < 1 || $rating > 5) {
        $error = 'Scegli una valutazione da 1 a 5.';
    } elseif (preg_match('/\A.{1,1500}\z/us', $comment) !== 1) {
        $error = 'Inserisci un commento entro il limite indicato.';
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                SELECT id_barbiere FROM PRENOTAZIONE
                WHERE id_prenotazione = ? AND id_cliente = ? AND stato = 'Completata'
                  AND id_barbiere IS NOT NULL
                FOR UPDATE
            ");
            $stmt->bind_param('ii', $bookingId, $clientId);
            $stmt->execute();
            $appointment = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$appointment) {
                throw new DomainException('Puoi recensire soltanto i tuoi appuntamenti completati.');
            }

            $stmt = $conn->prepare('SELECT 1 FROM RECENSIONE WHERE id_prenotazione = ? LIMIT 1');
            $stmt->bind_param('i', $bookingId);
            $stmt->execute();
            $review = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($review) {
                throw new DomainException('Hai già recensito questo appuntamento.');
            }

            $stmt = $conn->prepare('
                INSERT INTO RECENSIONE (id_cliente, id_barbiere, id_prenotazione, valutazione, commento)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->bind_param('iiiis', $clientId, $appointment['id_barbiere'], $bookingId, $rating, $comment);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            $_SESSION['cliente_recensioni_msg'] = 'Recensione inviata.';
            redirect('cliente/recensioni.php');
        } catch (DomainException $failure) {
            $conn->rollback();
            $error = $failure->getMessage();
        } catch (mysqli_sql_exception $failure) {
            $conn->rollback();
            error_log($failure->getMessage());
            $error = 'Impossibile inviare la recensione. Riprova.';
        }
    }
}

$stmt = $conn->prepare("
    SELECT p.id_prenotazione, p.data_ora, CONCAT(u.nome, ' ', u.cognome) AS barbiere,
           GROUP_CONCAT(s.nome_servizio ORDER BY dp.id_dettaglio SEPARATOR ', ') AS servizi
    FROM PRENOTAZIONE p
    JOIN BARBIERE b ON b.id_barbiere = p.id_barbiere
    JOIN UTENTE u ON u.id_utente = b.id_utente
    LEFT JOIN DETTAGLIO_PRENOTAZIONE dp ON dp.id_prenotazione = p.id_prenotazione
    LEFT JOIN SERVIZIO s ON s.id_servizio = dp.id_servizio
    WHERE p.id_cliente = ? AND p.stato = 'Completata'
      AND NOT EXISTS (SELECT 1 FROM RECENSIONE r WHERE r.id_prenotazione = p.id_prenotazione)
    GROUP BY p.id_prenotazione, p.data_ora, u.nome, u.cognome
    ORDER BY p.data_ora DESC
");
$stmt->bind_param('i', $clientId);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT r.valutazione, r.commento, r.data_recensione,
           CONCAT(u.nome, ' ', u.cognome) AS barbiere, p.data_ora
    FROM RECENSIONE r
    JOIN BARBIERE b ON b.id_barbiere = r.id_barbiere
    JOIN UTENTE u ON u.id_utente = b.id_utente
    LEFT JOIN PRENOTAZIONE p ON p.id_prenotazione = r.id_prenotazione
    WHERE r.id_cliente = ?
    ORDER BY r.data_recensione DESC
");
$stmt->bind_param('i', $clientId);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'Le mie recensioni';
include __DIR__ . '/../includes/header.php';
?>
<div class="dashboard-container page-enter">
    <div class="section-title-wrapper">
        <h1 class="section-main-title">Le mie <span class="gold-highlight">recensioni</span></h1>
    </div>

    <?php if ($message): ?><div class="flash flash-ok" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash flash-err" role="alert"><?= e($error) ?></div><?php endif; ?>

    <div class="booking-layout">
        <section class="luxury-data-card">
            <div class="card-data-header"><div><h2>Scrivi una recensione</h2><p>Puoi lasciare un giudizio per ogni appuntamento completato.</p></div></div>
            <?php if ($appointments): ?>
            <form method="post" class="modal-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="crea">
                <div class="form-group">
                    <label for="review-booking">Appuntamento</label>
                    <select id="review-booking" name="id_prenotazione" required>
                        <option value="">Seleziona un appuntamento</option>
                        <?php foreach ($appointments as $appointment): ?>
                        <option value="<?= e($appointment['id_prenotazione']) ?>" <?= $bookingId === (int) $appointment['id_prenotazione'] ? 'selected' : '' ?>>
                            <?= e(date('d/m/Y', strtotime($appointment['data_ora'])) . ' · ' . $appointment['barbiere']) ?><?= $appointment['servizi'] ? ' · ' . e($appointment['servizi']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="review-rating">Valutazione</label>
                    <select id="review-rating" name="valutazione" required>
                        <option value="">Scegli da 1 a 5 stelle</option>
                        <?php for ($stars = 5; $stars >= 1; $stars--): ?>
                        <option value="<?= $stars ?>" <?= $rating === $stars ? 'selected' : '' ?>><?= $stars ?> <?= $stars === 1 ? 'stella' : 'stelle' ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="review-comment">Commento</label>
                    <textarea id="review-comment" name="commento" rows="4" maxlength="1500" required><?= e($comment) ?></textarea>
                </div>
                <button type="submit" class="btn-modal-save">Invia recensione</button>
            </form>
            <?php else: ?>
            <div class="modal-form"><p>Nessun appuntamento da recensire.</p></div>
            <?php endif; ?>
        </section>

        <section aria-labelledby="review-history">
            <div class="section-title-wrapper">
                <h2 id="review-history" class="section-main-title">Recensioni inviate</h2>
            </div>
            <?php if (!$reviews): ?>
            <div class="empty-state"><p>Non hai ancora inviato recensioni.</p></div>
            <?php endif; ?>
            <?php foreach ($reviews as $review): ?>
            <article class="mia-recensione-card">
                <div class="mia-rec-header">
                    <strong class="mia-rec-barber"><?= e($review['barbiere']) ?></strong>
                    <span class="mia-rec-date"><?= e(date('d/m/Y', strtotime($review['data_recensione']))) ?></span>
                </div>
                <?php $stars = max(0, min(5, (int) $review['valutazione'])); ?>
                <div class="mia-rec-stars">
                    <span class="star-filled" aria-hidden="true"><?= str_repeat('★', $stars) ?></span>
                    <span class="star-num"><?= $stars ?>/5</span>
                </div>
                <p class="mia-rec-commento"><?= nl2br(e($review['commento'] ?? '')) ?></p>
                <?php if ($review['data_ora']): ?><span class="mia-rec-appuntamento">Appuntamento del <?= e(date('d/m/Y', strtotime($review['data_ora']))) ?></span><?php endif; ?>
            </article>
            <?php endforeach; ?>
        </section>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
