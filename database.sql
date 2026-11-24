CREATE DATABASE IF NOT EXISTS barberia_elite_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barberia_elite_db;
CREATE TABLE IF NOT EXISTS TIPOLOGIA_UTENTE (
    id_tipologia INT AUTO_INCREMENT PRIMARY KEY,
    nome_tipologia VARCHAR(50) NOT NULL,
    descrizione TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS UTENTE (
    id_utente INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    cognome VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    telefono VARCHAR(20),
    password_hash VARCHAR(255) NOT NULL,
    data_registrazione DATETIME DEFAULT CURRENT_TIMESTAMP,
    stato ENUM('Attivo', 'Sospeso') DEFAULT 'Attivo',
    id_tipologia INT,
    FOREIGN KEY (id_tipologia) REFERENCES TIPOLOGIA_UTENTE(id_tipologia) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS SEDE (
    id_sede INT AUTO_INCREMENT PRIMARY KEY,
    nome_sede VARCHAR(100) NOT NULL,
    indirizzo VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ADMIN (
    id_admin INT AUTO_INCREMENT PRIMARY KEY,
    id_utente INT UNIQUE,
    data_nomina DATE NOT NULL,
    note TEXT,
    FOREIGN KEY (id_utente) REFERENCES UTENTE(id_utente) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS BARBIERE (
    id_barbiere INT AUTO_INCREMENT PRIMARY KEY,
    id_utente INT UNIQUE,
    id_sede INT,
    data_assunzione DATE NOT NULL,
    specializzazione VARCHAR(100),
    biografia TEXT,
    attivo BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (id_utente) REFERENCES UTENTE(id_utente) ON DELETE CASCADE,
    FOREIGN KEY (id_sede) REFERENCES SEDE(id_sede) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS CLIENTE (
    id_cliente INT AUTO_INCREMENT PRIMARY KEY,
    id_utente INT UNIQUE,
    data_nascita DATE,
    indirizzo VARCHAR(255),
    note TEXT,
    FOREIGN KEY (id_utente) REFERENCES UTENTE(id_utente) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS CATEGORIA_SERVIZIO (
    id_categoria INT AUTO_INCREMENT PRIMARY KEY,
    nome_categoria VARCHAR(50) NOT NULL,
    descrizione TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS SERVIZIO (
    id_servizio INT AUTO_INCREMENT PRIMARY KEY,
    id_categoria INT,
    nome_servizio VARCHAR(100) NOT NULL,
    descrizione TEXT,
    prezzo DECIMAL(10,2) NOT NULL,
    durata_minuti INT NOT NULL,
    attivo BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (id_categoria) REFERENCES CATEGORIA_SERVIZIO(id_categoria) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS PRENOTAZIONE (
    id_prenotazione INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT,
    id_barbiere INT,
    id_sede INT,
    data_ora DATETIME NOT NULL,
    data_fine DATETIME NOT NULL,
    stato ENUM('Prenotato', 'Confermata', 'Completata', 'Annullata', 'No_Show') DEFAULT 'Prenotato',
    note TEXT,
    KEY idx_prenotazione_barbiere_ora (id_barbiere, data_ora),
    KEY idx_prenotazione_cliente_ora (id_cliente, data_ora),
    FOREIGN KEY (id_cliente) REFERENCES CLIENTE(id_cliente) ON DELETE SET NULL,
    FOREIGN KEY (id_barbiere) REFERENCES BARBIERE(id_barbiere) ON DELETE SET NULL,
    FOREIGN KEY (id_sede) REFERENCES SEDE(id_sede) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS DETTAGLIO_PRENOTAZIONE (
    id_dettaglio INT AUTO_INCREMENT PRIMARY KEY,
    id_prenotazione INT,
    id_servizio INT,
    prezzo_applicato DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (id_prenotazione) REFERENCES PRENOTAZIONE(id_prenotazione) ON DELETE CASCADE,
    FOREIGN KEY (id_servizio) REFERENCES SERVIZIO(id_servizio) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ORARIO_DISPONIBILE (
    id_orario INT AUTO_INCREMENT PRIMARY KEY,
    id_barbiere INT NOT NULL,
    giorno_settimana TINYINT NOT NULL COMMENT '1=Lunedi, 7=Domenica',
    ora_inizio TIME NOT NULL,
    ora_fine TIME NOT NULL,
    attivo BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (id_barbiere) REFERENCES BARBIERE(id_barbiere) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS RECENSIONE (
    id_recensione INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT NOT NULL,
    id_barbiere INT NOT NULL,
    id_prenotazione INT DEFAULT NULL,
    valutazione TINYINT NOT NULL CHECK (valutazione BETWEEN 1 AND 5),
    commento TEXT,
    data_recensione DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recensione_prenotazione (id_prenotazione),
    FOREIGN KEY (id_cliente) REFERENCES CLIENTE(id_cliente) ON DELETE CASCADE,
    FOREIGN KEY (id_barbiere) REFERENCES BARBIERE(id_barbiere) ON DELETE CASCADE,
    FOREIGN KEY (id_prenotazione) REFERENCES PRENOTAZIONE(id_prenotazione) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS APP_SCHEMA (
    id TINYINT PRIMARY KEY,
    versione INT NOT NULL
) ENGINE=InnoDB;
START TRANSACTION;
SET @elite_installazione_iniziale = NOT EXISTS (SELECT 1 FROM TIPOLOGIA_UTENTE);

INSERT INTO TIPOLOGIA_UTENTE (id_tipologia, nome_tipologia, descrizione)
SELECT 1, 'Admin', 'Amministratore' FROM DUAL WHERE @elite_installazione_iniziale
UNION ALL SELECT 2, 'Barbiere', 'Staff' FROM DUAL WHERE @elite_installazione_iniziale
UNION ALL SELECT 3, 'Cliente', 'Cliente' FROM DUAL WHERE @elite_installazione_iniziale;

INSERT INTO SEDE (id_sede, nome_sede, indirizzo)
SELECT 1, 'Sede Luxury Bari', 'Via Sannicandro 45, Bari' FROM DUAL
WHERE @elite_installazione_iniziale AND NOT EXISTS (SELECT 1 FROM SEDE);

INSERT INTO CATEGORIA_SERVIZIO (id_categoria, nome_categoria, descrizione)
SELECT 1, 'Capelli', 'Cura e modellazione capelli' FROM DUAL WHERE @elite_installazione_iniziale
UNION ALL SELECT 2, 'Barba', 'Regolazione e rasatura' FROM DUAL WHERE @elite_installazione_iniziale
UNION ALL SELECT 3, 'Trattamenti', 'Benessere viso' FROM DUAL WHERE @elite_installazione_iniziale;

INSERT INTO SERVIZIO (id_servizio, id_categoria, nome_servizio, descrizione, prezzo, durata_minuti)
SELECT 1, 1, 'Taglio Executive', 'Shampoo specifico, taglio su misura e finitura modellante', 28.00, 30 FROM DUAL WHERE @elite_installazione_iniziale
UNION ALL SELECT 2, 2, 'Rituale Barba Elite', 'Regolazione a forbice, rasoio a mano libera e doppio panno caldo', 22.00, 35 FROM DUAL WHERE @elite_installazione_iniziale
UNION ALL SELECT 3, 3, 'Trattamento Viso Purificante', 'Maschera all’argilla nera e massaggio lenitivo con acido ialuronico', 18.00, 20 FROM DUAL WHERE @elite_installazione_iniziale;
INSERT INTO ADMIN (id_utente, data_nomina)
SELECT u.id_utente, COALESCE(DATE(u.data_registrazione), CURRENT_DATE())
FROM UTENTE u JOIN TIPOLOGIA_UTENTE t ON t.id_tipologia = u.id_tipologia
LEFT JOIN ADMIN a ON a.id_utente = u.id_utente
WHERE t.nome_tipologia = 'Admin' AND a.id_admin IS NULL;

INSERT INTO BARBIERE (id_utente, data_assunzione)
SELECT u.id_utente, COALESCE(DATE(u.data_registrazione), CURRENT_DATE())
FROM UTENTE u JOIN TIPOLOGIA_UTENTE t ON t.id_tipologia = u.id_tipologia
LEFT JOIN BARBIERE b ON b.id_utente = u.id_utente
WHERE t.nome_tipologia = 'Barbiere' AND b.id_barbiere IS NULL;

INSERT INTO CLIENTE (id_utente)
SELECT u.id_utente
FROM UTENTE u JOIN TIPOLOGIA_UTENTE t ON t.id_tipologia = u.id_tipologia
LEFT JOIN CLIENTE c ON c.id_utente = u.id_utente
WHERE t.nome_tipologia = 'Cliente' AND c.id_cliente IS NULL;

INSERT INTO APP_SCHEMA (id, versione)
SELECT 1, 1 FROM DUAL
WHERE EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND UPPER(TABLE_NAME) = 'RECENSIONE'
      AND COLUMN_NAME = 'id_prenotazione'
)
ON DUPLICATE KEY UPDATE versione = GREATEST(versione, 1);
COMMIT;
