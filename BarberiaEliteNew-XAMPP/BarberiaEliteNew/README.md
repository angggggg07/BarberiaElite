# Barberia Elite

Gestionale PHP/MySQL per XAMPP con sito pubblico, listino aggiornato dal database e aree riservate per amministratore, barbiere e cliente. Le immagini e i font sono inclusi nella cartella del progetto.

## Primo avvio con XAMPP

1. Estrai lo ZIP. Copia la cartella `BarberiaEliteNew` dentro `C:\xampp\htdocs` su Windows oppure nella cartella `htdocs` della tua installazione.
2. Nel pannello di XAMPP avvia **Apache** e **MySQL**.
3. Apri [http://localhost/BarberiaEliteNew/setup.php](http://localhost/BarberiaEliteNew/setup.php). Il progetto inizializza il database e carica la sede e i tre servizi iniziali. Scegli le credenziali del primo amministratore. La password deve avere almeno 12 caratteri.
4. Accedi da [http://localhost/BarberiaEliteNew/](http://localhost/BarberiaEliteNew/). In **Barbieri**, crea un account per ciascun barbiere e assegna una sede.
5. Accedi con l’account del barbiere, apri **Orari** e pubblica le fasce disponibili. I clienti possono registrarsi dalla pagina iniziale e scegliere un appuntamento che rientra nelle fasce pubblicate.

Usa PHP 8.2 o successivo con `mysqli`, `mysqlnd` e `mbstring`. Il progetto è stato verificato con PHP 8.2 e MariaDB 10.4, una combinazione compatibile con XAMPP, e con PHP 8.4 e MariaDB 11.8. I test sono stati eseguiti su Linux con il server PHP integrato.

## Database

La configurazione XAMPP standard usa `localhost:3306`, utente `root`, password vuota e il database `barberia_elite_db`. Per impostazioni diverse, configura le variabili `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD` nell’ambiente Apache/PHP. Ad esempio, se MySQL usa la porta 3307, imposta `DB_PORT` a `3307`.

Con i normali permessi XAMPP l’importazione manuale è facoltativa. Per eseguirla, apri [phpMyAdmin](http://localhost/phpmyadmin/), scegli **Importa** e seleziona `database.sql`. Il file crea e popola lo schema predefinito. Se usi un database con un altro nome, crealo con phpMyAdmin e imposta `DB_NAME`, quindi lascia che l’applicazione inizializzi lo schema. L’utente del database deve poter modificare lo schema durante la prima installazione o migrazione.

Per aggiornare un’installazione precedente, esporta una copia del database prima di sostituire i file e riusa lo stesso `DB_NAME`. L’applicazione mantiene i record, completa lo schema ove necessario e conserva gli account esistenti. Se l’installazione precedente conteneva account dimostrativi, cambia le loro password o rimuovili dopo aver controllato i dati associati. I nuovi database partono senza account predefiniti.

La configurazione del primo amministratore è disponibile dal computer che esegue XAMPP. Se l’archivio è già configurato, usa l’account amministratore esistente. Se Apache usa una porta diversa da 80, aggiungila anche agli URL locali, ad esempio `http://localhost:8080/BarberiaEliteNew/`.

## Struttura

| Cartella o file | Funzione |
| --- | --- |
| `index.php`, `dashboard.php`, `logout.php` | Vetrina, accesso, registrazione, smistamento e uscita |
| `setup.php`, `config.php`, `database.sql` | Primo avvio, connessione, schema e migrazioni |
| `admin/` | Dashboard, barbieri, utenti, servizi e appuntamenti |
| `barbiere/` | Agenda, disponibilità e riepilogo guadagni |
| `cliente/` | Prenotazioni, annullamenti, recensioni e profilo |
| `includes/`, `assets/` | Componenti condivisi, stili, foto e font |

## Controllo dopo l’installazione

Crea un barbiere con una fascia futura e verifica che un nuovo cliente possa prenotarla. Poi completa l’appuntamento dall’agenda e verifica che il cliente possa recensirlo. Le disponibilità seguono il fuso orario di Roma. Le immagini della vetrina sono illustrative. Sede, prezzi e durate iniziali provengono dal progetto originale.
