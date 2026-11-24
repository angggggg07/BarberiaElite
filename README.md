# Barberia Elite

Barberia Elite è un gestionale per barberia sviluppato in **PHP e MySQL**, configurato per funzionare in locale con **XAMPP**. Include un sito pubblico con listino aggiornato dal database e tre aree riservate per amministratori, barbieri e clienti.

## Funzionalità

| Area | Funzionalità |
| --- | --- |
| **Sito pubblico** | Presentazione della barberia, listino con prezzi e durate, sede, accesso e registrazione |
| **Amministratore** | Gestione di utenti, barbieri, servizi e appuntamenti |
| **Barbiere** | Agenda, fasce di disponibilità, gestione degli appuntamenti e riepilogo dei guadagni |
| **Cliente** | Prenotazione e annullamento, storico degli appuntamenti, recensioni e modifica del profilo |

Il sistema verifica che ogni prenotazione rientri nelle fasce orarie del barbiere e impedisce appuntamenti sovrapposti. I clienti possono recensire un appuntamento completato una sola volta.

## Requisiti

- XAMPP con Apache e MySQL o MariaDB
- PHP 8.2 o successivo con `mysqli`, `mysqlnd` e `mbstring`

## Installazione con XAMPP

1. Scarica il repository e copia la cartella del progetto in `htdocs`. Su Windows il percorso tipico è `C:\xampp\htdocs\BarberiaEliteNew`.
2. Avvia **Apache** e **MySQL** dal pannello di controllo di XAMPP.
3. Apri `http://localhost/BarberiaEliteNew/setup.php` per inizializzare il database e creare il primo amministratore. Scegli una password di almeno 12 caratteri.
4. Accedi da `http://localhost/BarberiaEliteNew/`. Nell’area **Barbieri**, crea un account e assegnagli una sede.
5. Accedi con l’account del barbiere e aggiungi le fasce orarie nella sezione **Orari**. A quel punto i clienti registrati possono prenotare.

Se hai rinominato la cartella o usi una porta Apache diversa da `80`, aggiorna gli indirizzi di conseguenza.

## Database

La configurazione predefinita di XAMPP usa `localhost:3306`, l’utente `root`, una password vuota e il database `barberia_elite_db`.

Al primo avvio, il progetto crea lo schema e carica una sede e tre servizi iniziali. Puoi anche importare `database.sql` manualmente dalla scheda **Importa** di phpMyAdmin. Le credenziali dell’amministratore si impostano da `setup.php`.

Per una configurazione diversa, imposta le variabili d’ambiente `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD`. Se stai aggiornando un’installazione esistente, esporta una copia del database prima di sostituire i file del progetto.

## Struttura del progetto

| Percorso | Contenuto |
| --- | --- |
| `index.php` | Sito pubblico, accesso e registrazione |
| `setup.php` | Creazione del primo amministratore |
| `config.php` | Connessione, inizializzazione del database e funzioni condivise |
| `database.sql` | Schema MySQL e dati iniziali |
| `admin/` | Area amministratore |
| `barbiere/` | Agenda, orari e guadagni |
| `cliente/` | Prenotazioni, recensioni e profilo |
| `includes/` | Componenti condivisi delle pagine |
| `assets/` | CSS, JavaScript, immagini e font |

## Collaudo

Il progetto è stato verificato su Linux con **PHP 8.2 e MariaDB 10.4** e con **PHP 8.4 e MariaDB 11.8**. I controlli hanno coperto il primo avvio, l’accesso per ruolo, le prenotazioni concorrenti, gli annullamenti, il completamento degli appuntamenti e le recensioni.
