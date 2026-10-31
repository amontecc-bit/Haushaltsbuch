-- Kalender je Haushaltsmitglied: Termine mit Vor-/Nachlauf, Teilnehmer, Wiederholung, persönliche Vorlagen
-- und Budgets je Teilnehmer (fließen in die Prognose ein, gebucht wird nur per Knopf)

-- Persönliche Terminvorlagen (Dauer, Vorlauf für Anreise, Nachlauf für Abreise, optional Budget-Vorschlag)
CREATE TABLE calendar_templates (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id        INT UNSIGNED NOT NULL,
    user_id             INT UNSIGNED NOT NULL,
    name                VARCHAR(100) NOT NULL,
    title               VARCHAR(190) NULL,
    location            VARCHAR(190) NULL,
    duration_minutes    INT NOT NULL DEFAULT 60,
    lead_minutes        INT NOT NULL DEFAULT 0,
    trail_minutes       INT NOT NULL DEFAULT 0,
    all_day             TINYINT(1) NOT NULL DEFAULT 0,
    color               VARCHAR(7) NOT NULL DEFAULT '#0d6efd',
    budget_amount       DECIMAL(12,2) NULL,
    budget_category_id  INT UNSIGNED NULL,
    budget_account_id   INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_caltpl_user (household_id, user_id),
    CONSTRAINT fk_caltpl_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_caltpl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_caltpl_category FOREIGN KEY (budget_category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_caltpl_account FOREIGN KEY (budget_account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Termine; end_at ist exklusiv (ganztägig: Folgetag 00:00). Vorlauf liegt vor start_at, Nachlauf nach end_at.
CREATE TABLE calendar_events (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id         INT UNSIGNED NOT NULL,
    created_by           INT UNSIGNED NULL,
    title                VARCHAR(190) NOT NULL,
    location             VARCHAR(190) NULL,
    note                 TEXT NULL,
    start_at             DATETIME NOT NULL,
    end_at               DATETIME NOT NULL,
    all_day              TINYINT(1) NOT NULL DEFAULT 0,
    lead_minutes         INT NOT NULL DEFAULT 0,
    trail_minutes        INT NOT NULL DEFAULT 0,
    color                VARCHAR(7) NOT NULL DEFAULT '#0d6efd',
    template_id          INT UNSIGNED NULL,
    recurrence           ENUM('none','daily','weekly','monthly','yearly') NOT NULL DEFAULT 'none',
    recurrence_interval  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    recurrence_until     DATE NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_calev_start (household_id, start_at),
    CONSTRAINT fk_calev_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_calev_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_calev_template FOREIGN KEY (template_id) REFERENCES calendar_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Teilnehmer (der Ersteller steht immer mit drin)
CREATE TABLE calendar_participants (
    event_id  INT UNSIGNED NOT NULL,
    user_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, user_id),
    KEY idx_calpart_user (user_id),
    CONSTRAINT fk_calpart_event FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_calpart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Einzelne gelöschte Vorkommen einer Terminserie
CREATE TABLE calendar_exceptions (
    event_id         INT UNSIGNED NOT NULL,
    occurrence_date  DATE NOT NULL,
    PRIMARY KEY (event_id, occurrence_date),
    CONSTRAINT fk_calexc_event FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Budget je Termin und Teilnehmer (geplante Ausgabe, positiv gespeichert; gilt je Vorkommen)
CREATE TABLE calendar_budgets (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id     INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    amount       DECIMAL(12,2) NOT NULL,
    account_id   INT UNSIGNED NULL,
    category_id  INT UNSIGNED NULL,
    note         VARCHAR(255) NULL,
    UNIQUE KEY uq_calbud (event_id, user_id),
    CONSTRAINT fk_calbud_event FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_calbud_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_calbud_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_calbud_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gebuchte Budgets je Vorkommen; Löschen der Buchung öffnet das Budget wieder
CREATE TABLE calendar_budget_bookings (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    budget_id        INT UNSIGNED NOT NULL,
    occurrence_date  DATE NOT NULL,
    transaction_id   INT UNSIGNED NOT NULL,
    booked_by        INT UNSIGNED NULL,
    booked_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_calbook (budget_id, occurrence_date),
    CONSTRAINT fk_calbook_budget FOREIGN KEY (budget_id) REFERENCES calendar_budgets(id) ON DELETE CASCADE,
    CONSTRAINT fk_calbook_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_calbook_user FOREIGN KEY (booked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gebuchte Kalender-Budgets erkennbar machen (Prognose klammert sie im variablen Ø aus)
ALTER TABLE transactions MODIFY source ENUM('manual','csv','recurring','purchase','calendar') NOT NULL DEFAULT 'manual';
