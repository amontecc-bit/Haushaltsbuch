-- Kategorien, deren Buchungen nicht in den variablen Ø der Prognose einfließen (Einmaleffekte, Anschaffungen …).
-- Gilt für eine Hauptkategorie automatisch auch für ihre Unterkategorien.
-- Ohne „IF NOT EXISTS“ (gibt es nur in MariaDB, nicht in MySQL 8 bei IONOS); Wiederholung fängt der Migrator ab.
ALTER TABLE categories
    ADD COLUMN exclude_from_forecast TINYINT(1) NOT NULL DEFAULT 0 AFTER color;
