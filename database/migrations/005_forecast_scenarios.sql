-- Prognose-Szenarien: je Konto ein von Hand festgelegter variabler Monatssaldo.
-- Konten ohne Eintrag im Szenario verwenden den aus den Buchungen berechneten Ø.
CREATE TABLE forecast_scenarios (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id  INT UNSIGNED NOT NULL,
    name          VARCHAR(100) NOT NULL,
    note          VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fscen_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forecast_scenario_values (
    scenario_id     INT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NOT NULL,
    monthly_amount  DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (scenario_id, account_id),
    CONSTRAINT fk_fscenval_scenario FOREIGN KEY (scenario_id) REFERENCES forecast_scenarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_fscenval_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
