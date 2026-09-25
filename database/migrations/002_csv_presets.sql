-- Mitgelieferte CSV-Profile (household_id NULL = für alle Haushalte sichtbar)
-- mapping: Spaltennamen der Bank-CSV je Feld. payee_in/payee_out: Gegenpartei je nach Vorzeichen.
INSERT INTO csv_profiles (household_id, name, delimiter, encoding, skip_rows, date_format, decimal_sep, mapping) VALUES
(NULL, 'Sparkasse (CSV-CAMT)', ';', 'auto', 0, 'auto', ',',
 '{"date":"Buchungstag","amount":"Betrag","payee":"Beguenstigter/Zahlungspflichtiger","purpose":"Verwendungszweck","counter_iban":"Kontonummer/IBAN","booking_text":"Buchungstext","own_iban":"Auftragskonto","status":"Info"}'),
(NULL, 'ING', ';', 'auto', 0, 'auto', ',',
 '{"date":"Buchung","amount":"Betrag","payee":"Auftraggeber/Empfänger","purpose":"Verwendungszweck","booking_text":"Buchungstext"}'),
(NULL, 'DKB', ';', 'auto', 0, 'auto', ',',
 '{"date":"Buchungsdatum","amount":"Betrag (€)","payee_in":"Zahlungspflichtige*r","payee_out":"Zahlungsempfänger*in","purpose":"Verwendungszweck","counter_iban":"IBAN","booking_text":"Umsatztyp","status":"Status"}'),
(NULL, 'DKB (alt)', ';', 'auto', 0, 'auto', ',',
 '{"date":"Buchungstag","amount":"Betrag (EUR)","payee":"Auftraggeber / Begünstigter","purpose":"Verwendungszweck","counter_iban":"Kontonummer","booking_text":"Buchungstext"}'),
(NULL, 'Volksbank / Raiffeisenbank', ';', 'auto', 0, 'auto', ',',
 '{"date":"Buchungstag","amount":"Betrag","payee":"Name Zahlungsbeteiligter","purpose":"Verwendungszweck","counter_iban":"IBAN Zahlungsbeteiligter","booking_text":"Buchungstext","own_iban":"IBAN Auftragskonto"}'),
(NULL, 'comdirect', ';', 'auto', 0, 'auto', ',',
 '{"date":"Buchungstag","amount":"Umsatz in EUR","purpose":"Buchungstext","booking_text":"Vorgang"}'),
(NULL, 'Commerzbank', ';', 'auto', 0, 'auto', ',',
 '{"date":"Buchungstag","amount":"Betrag","purpose":"Buchungstext","booking_text":"Umsatzart","own_iban":"IBAN Auftraggeberkonto"}'),
(NULL, 'Postbank', ';', 'auto', 0, 'auto', ',',
 '{"date":"Buchungstag","amount":"Betrag (€)","payee_in":"Auftraggeber","payee_out":"Empfänger","purpose":"Verwendungszweck","counter_iban":"IBAN","booking_text":"Umsatzart"}'),
(NULL, 'N26', ',', 'auto', 0, 'auto', '.',
 '{"date":"Booking Date","amount":"Amount (EUR)","payee":"Partner Name","purpose":"Payment Reference","counter_iban":"Partner Iban","booking_text":"Type"}'),
(NULL, 'N26 (alt)', ',', 'auto', 0, 'auto', '.',
 '{"date":"Datum","amount":"Betrag (EUR)","payee":"Empfänger","purpose":"Verwendungszweck","counter_iban":"Kontonummer","booking_text":"Transaktionstyp"}');
