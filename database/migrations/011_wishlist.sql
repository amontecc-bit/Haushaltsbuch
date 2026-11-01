-- Wunschliste: künftige Anschaffungen und Weihnachts-/Geburtstagswünsche mit Bild und optionalem virtuellem Sparplan.
-- Der Sparplan ist rein rechnerisch (es wird nichts gebucht): Startguthaben + monatliche Rate ab plan_start.
-- plan_recurring_id = Posten (Fixkosten/Dauerauftrag), aus dem die Rate bezahlt wird;
-- plan_account_id = Konto, auf dem das Ersparte liegt (Einnahmeseite des Postens).
CREATE TABLE wishes (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id       INT UNSIGNED NOT NULL,
    title              VARCHAR(190) NOT NULL,
    description        TEXT NULL,
    price              DECIMAL(12,2) NULL,
    link               VARCHAR(500) NULL,
    occasion           VARCHAR(100) NULL,
    for_user_id        INT UNSIGNED NULL,
    wanted_by          DATE NULL,
    image_path         VARCHAR(255) NULL,
    status             ENUM('open','fulfilled') NOT NULL DEFAULT 'open',
    fulfilled_at       DATE NULL,
    plan_amount        DECIMAL(12,2) NULL,
    plan_start         DATE NULL,
    plan_saved         DECIMAL(12,2) NOT NULL DEFAULT 0,
    plan_recurring_id  INT UNSIGNED NULL,
    plan_account_id    INT UNSIGNED NULL,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_wishes_household (household_id, status),
    CONSTRAINT fk_wish_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_wish_for_user FOREIGN KEY (for_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_wish_recurring FOREIGN KEY (plan_recurring_id) REFERENCES recurring_transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_wish_account FOREIGN KEY (plan_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_wish_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
