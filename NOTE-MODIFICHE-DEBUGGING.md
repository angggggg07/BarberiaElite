# Barberia Elite — modifiche e verifiche

## Interventi inclusi

- **Prenotazioni cliente:** annullamento gestito in transazione con blocchi nello stesso ordine del flusso di creazione (barbiere → cliente → prenotazione). Questo riduce le condizioni di gara tra annullamenti, prenotazioni e aggiornamenti amministrativi concorrenti.
- **Agenda barbiere:** aggiornamento dello stato protetto da transazione e lock sul barbiere/appuntamento; completamento consentito solo dopo l’orario di fine e segnalazione di assenza solo dopo l’orario di inizio.
- **Pannello admin:** aggiunti controlli temporali server-side per impedire stati incoerenti (appuntamento futuro già completato, assenza prima dell’inizio, riattivazione di appuntamenti già iniziati).
- **Filtri admin:** intercettato l’intervallo data invertito e mostrato un messaggio esplicito.
- **Account e anagrafica:** uniformata a 8 caratteri la lunghezza minima delle password create/modificate dall’admin (la prima configurazione resta a 12); i nomi e i campi testuali sono validati anche in presenza di caratteri UTF-8; la data di assunzione non può essere futura.
- **Input non conformi:** protetti i campi testuali/date dalle richieste manipolate che inviano array al posto di stringhe, evitando TypeError non gestiti in PHP 8 nelle pagine prenotazioni e recensioni.
- **Interfaccia:** rifiniti header pubblico, accesso, navigazione laterale, KPI, cards, tabelle, form, modali, stati hover/focus e comportamento mobile; palette verde bosco/sabbia/oro e font locali mantenuti.

## Controlli eseguiti

- `php -l` su tutti i file PHP del progetto: nessun errore di sintassi.
- Controllo statico dei flussi di prenotazione, annullamento, cambio stato, recensione e orari.

## Test manuali consigliati su XAMPP

1. Crea due clienti e un barbiere con una fascia oraria futura.
2. Prova a prenotare lo stesso intervallo con i due clienti: la seconda richiesta deve essere rifiutata.
3. Annulla una prenotazione futura dal profilo cliente e verifica che lo slot torni prenotabile.
4. Dall’agenda barbiere, prova a completare un appuntamento futuro: il server deve rifiutarlo.
5. Dopo l’orario di fine, completa l’appuntamento e verifica che il cliente possa recensirlo.
6. Prova a segnare un cliente assente prima dell’inizio: il server deve rifiutare l’operazione.
7. Nell’admin inserisci `Dal` successivo a `Al`: deve comparire l’avviso e il filtro non deve creare un intervallo invertito.
8. Verifica le pagine a larghezza desktop, tablet e smartphone, soprattutto il menu orizzontale nelle aree riservate.

> Nota: non è stata eseguita una prova end-to-end contro un database reale, perché l’archivio non contiene credenziali né un dump dati di test. Le modifiche al database non sono necessarie.


## Organizzazione e pulizia

- Il progetto ora ha una sola cartella radice, `BarberiaElite`, da copiare direttamente in `htdocs`; gli URL locali utilizzano `/BarberiaElite/`.
- Aggiornato il README con i percorsi corretti per XAMPP e per l'installazione iniziale.
- Rimossi i commenti dal codice PHP, HTML, CSS e SQL.
- Aggiornato il caricamento automatico dello schema: `config.php` individua il comando `USE` in `database.sql` e carica le istruzioni `CREATE TABLE`, senza dipendere da un commento SQL come delimitatore.
