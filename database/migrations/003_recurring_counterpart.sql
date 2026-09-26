-- Gegeneintrag: zwei wiederkehrende Buchungen mit umgekehrtem Betrag auf verschiedenen Konten verknüpfen
-- (z. B. Haushaltsgeld: Ausgabe auf dem Gehaltskonto, Einnahme auf dem Haushaltskonto)
-- IF NOT EXISTS: darf auch laufen, wenn die Spalte schon von Hand angelegt wurde
ALTER TABLE recurring_transactions
    ADD COLUMN IF NOT EXISTS counterpart_id INT UNSIGNED NULL AFTER loan_id,
    ADD CONSTRAINT fk_rec_counterpart FOREIGN KEY IF NOT EXISTS (counterpart_id) REFERENCES recurring_transactions(id) ON DELETE SET NULL;
