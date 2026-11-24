# BarberiaElite

Gestionale PHP e MySQL per una barberia, compatibile con XAMPP. Include il sito pubblico, il listino collegato al database e le aree riservate per amministratore, barbiere e cliente. Immagini e font sono inclusi nel progetto.

## Installazione con XAMPP

1. Estrai l'archivio e copia la cartella `BarberiaElite` in `C:\xampp\htdocs` su Windows oppure nella cartella `htdocs` della tua installazione XAMPP.
2. Avvia **Apache** e **MySQL** dal pannello di XAMPP.
3. Apri [http://localhost/BarberiaElite/setup.php](http://localhost/BarberiaElite/setup.php). La procedura inizializza il database e inserisce la sede e i servizi iniziali. Crea le credenziali del primo amministratore; la password deve avere almeno 12 caratteri.
4. Accedi da [http://localhost/BarberiaElite/](http://localhost/BarberiaElite/).
5. Nell'area **Barbieri**, crea gli account dello staff e assegna una sede. Ogni barbiere può poi pubblicare le proprie fasce di disponibilità dall'area **Orari**.
6. I clienti possono registrarsi dalla pagina iniziale e prenotare negli orari pubblicati.

Se Apache utilizza una porta diversa da 80, includila nell'indirizzo. Per esempio: `http://localhost:8080/BarberiaElite/`.

## Requisiti

Usa PHP 8.2 o successivo con le estensioni `mysqli`, `mysqlnd` e `mbstring`. Il progetto utilizza MySQL o MariaDB ed è progettato per l'ambiente locale XAMPP.

## Database

La configurazione standard XAMPP utilizza `localhost:3306`, utente `root`, password vuota e database `barberia_elite_db`. Per impostazioni differenti, configura le variabili `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD` nell'ambiente Apache/PHP. Per esempio, se MySQL usa la porta 3307, imposta `DB_PORT` a `3307`.

L'inizializzazione automatica del progetto è preferibile. L'importazione manuale è facoltativa: apri [phpMyAdmin](http://localhost/phpmyadmin/), seleziona **Importa** e carica `database.sql`. Il file contiene lo schema e i dati iniziali. L'utente MySQL deve poter creare o modificare le tabelle durante la prima installazione e gli aggiornamenti dello schema.

Prima di aggiornare un'installazione esistente, esporta una copia del database e riutilizza lo stesso `DB_NAME`. L'applicazione mantiene i record esistenti e completa lo schema quando necessario. Controlla gli eventuali account dimostrativi presenti nelle installazioni precedenti e cambia le relative password o rimuovili.

## Struttura del progetto

| Cartella o file | Funzione |
| --- | --- |
| `index.php`, `dashboard.php`, `logout.php` | Sito pubblico, accesso, registrazione e navigazione dopo il login |
| `setup.php`, `config.php`, `database.sql` | Prima configurazione, connessione, schema e aggiornamenti |
| `admin/` | Gestione di utenti, barbieri, servizi e prenotazioni |
| `barbiere/` | Agenda, orari e riepilogo dei guadagni |
| `cliente/` | Prenotazioni, recensioni e profilo |
| `includes/`, `assets/` | Componenti condivisi, stili, script, immagini e font |

## Verifica dopo l'installazione

Crea un barbiere, assegna una sede e pubblica una fascia oraria futura. Verifica che un cliente possa prenotare uno slot disponibile, che un secondo cliente non possa prenotare lo stesso intervallo e che gli stati dell'appuntamento possano essere aggiornati solo nei momenti consentiti. Dopo il completamento dell'appuntamento, verifica la possibilità di inserire una recensione.

Le disponibilità seguono il fuso orario `Europe/Rome`. Le immagini della vetrina sono illustrative; sede, prezzi e durate iniziali provengono dai dati del progetto.
