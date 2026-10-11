-- Persönliche Einstellungen je Benutzer (z. B. Vorzugskonten für Einkauf, Import, Buchung, Umbuchung)
CREATE TABLE user_preferences (
    user_id  INT UNSIGNED NOT NULL,
    `key`    VARCHAR(64) NOT NULL,
    value    TEXT NULL,
    PRIMARY KEY (user_id, `key`),
    CONSTRAINT fk_upref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
