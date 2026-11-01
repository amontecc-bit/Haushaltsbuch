<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Kalender: Termine, Teilnehmer, gelöschte Vorkommen, Budgets je Teilnehmer, Budget-Buchungen und persönliche Vorlagen.
 */
final class CalendarRepository extends Repository
{
    public const EVENT_FIELDS = ['title', 'location', 'note', 'start_at', 'end_at', 'all_day', 'lead_minutes', 'trail_minutes',
                                 'color', 'template_id', 'recurrence', 'recurrence_interval', 'recurrence_until'];

    // ---- Termine ---------------------------------------------------------------------------

    public function find(int $id, int $householdId): ?array
    {
        return $this->one(
            'SELECT e.*, u.name AS creator_name FROM calendar_events e LEFT JOIN users u ON u.id = e.created_by
             WHERE e.id = ? AND e.household_id = ?',
            [$id, $householdId]
        );
    }

    public function create(int $householdId, array $data): int
    {
        return $this->insert('calendar_events', ['household_id' => $householdId] + $data);
    }

    public function update(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('calendar_events', array_intersect_key($data, array_flip(self::EVENT_FIELDS)), ['id' => $id, 'household_id' => $householdId]);
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM calendar_events WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    /** Termine, an denen der Benutzer teilnimmt und die den Zeitraum berühren können (Wiederholungen grob vorgefiltert) */
    public function eventsForUser(int $householdId, int $userId, string $from, string $to): array
    {
        return $this->many(
            "SELECT e.*, u.name AS creator_name
             FROM calendar_events e
             JOIN calendar_participants p ON p.event_id = e.id AND p.user_id = ?
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.household_id = ?
               AND DATE_SUB(e.start_at, INTERVAL e.lead_minutes MINUTE) < DATE_ADD(?, INTERVAL 1 DAY)
               AND (e.recurrence <> 'none' OR DATE_ADD(e.end_at, INTERVAL e.trail_minutes MINUTE) > ?)
               AND (e.recurrence = 'none' OR e.recurrence_until IS NULL OR e.recurrence_until >= DATE_SUB(?, INTERVAL 40 DAY))
             ORDER BY e.start_at",
            [$userId, $householdId, $to, $from, $from]
        );
    }

    /** @param int[] $ids @return array<int, string[]> Termin => gelöschte Vorkommen */
    public function exceptionsFor(array $ids): array
    {
        $ids = array_values($ids);
        $out = [];
        if ($ids) {
            foreach ($this->many('SELECT event_id, occurrence_date FROM calendar_exceptions WHERE event_id IN (' . self::in($ids) . ')', $ids) as $r) {
                $out[(int) $r['event_id']][] = $r['occurrence_date'];
            }
        }
        return $out;
    }

    public function addException(int $eventId, string $date): void
    {
        $this->exec('INSERT IGNORE INTO calendar_exceptions (event_id, occurrence_date) VALUES (?, ?)', [$eventId, $date]);
    }

    /**
     * Ausnahmen und gebuchte Budgets einer Serie um $days Tage verschieben (Serie wurde verschoben).
     * Reihenfolge so, dass der eindeutige Schlüssel unterwegs nicht kollidiert.
     */
    public function shiftOccurrenceDates(int $eventId, int $days): void
    {
        if ($days === 0) {
            return;
        }
        $order = $days > 0 ? 'DESC' : 'ASC';
        $this->exec("UPDATE calendar_exceptions SET occurrence_date = DATE_ADD(occurrence_date, INTERVAL ? DAY)
                     WHERE event_id = ? ORDER BY occurrence_date $order", [$days, $eventId]);
        $this->exec("UPDATE calendar_budget_bookings SET occurrence_date = DATE_ADD(occurrence_date, INTERVAL ? DAY)
                     WHERE budget_id IN (SELECT id FROM calendar_budgets WHERE event_id = ?) ORDER BY occurrence_date $order", [$days, $eventId]);
    }

    /** Gebuchte Budgets eines Vorkommens auf ein anderes Datum (und ggf. die Budgets eines anderen Termins) umhängen */
    public function moveBookings(int $eventId, string $fromDate, string $toDate, ?int $toEventId = null): void
    {
        foreach ($this->many(
            'SELECT k.id, b.user_id FROM calendar_budget_bookings k JOIN calendar_budgets b ON b.id = k.budget_id
             WHERE b.event_id = ? AND k.occurrence_date = ?', [$eventId, $fromDate]) as $r) {
            $budgetId = $toEventId ? $this->value('SELECT id FROM calendar_budgets WHERE event_id = ? AND user_id = ?', [$toEventId, $r['user_id']]) : null;
            $this->exec('UPDATE calendar_budget_bookings SET occurrence_date = ?' . ($budgetId ? ', budget_id = ' . (int) $budgetId : '') . ' WHERE id = ?',
                [$toDate, $r['id']]);
        }
    }

    /** Budgets aller Teilnehmer auf einen anderen Termin kopieren (abgelöstes Vorkommen einer Serie) */
    public function copyBudgets(int $fromEventId, int $toEventId): void
    {
        $this->exec('INSERT INTO calendar_budgets (event_id, user_id, amount, account_id, category_id, note)
                     SELECT ?, user_id, amount, account_id, category_id, note FROM calendar_budgets WHERE event_id = ?', [$toEventId, $fromEventId]);
    }

    // ---- Teilnehmer ------------------------------------------------------------------------

    /** @param int[] $ids @return array<int, array<int, array{id:int, name:string}>> */
    public function participantsFor(array $ids): array
    {
        $ids = array_values($ids);
        $out = [];
        if ($ids) {
            foreach ($this->many(
                'SELECT p.event_id, u.id, u.name FROM calendar_participants p JOIN users u ON u.id = p.user_id
                 WHERE p.event_id IN (' . self::in($ids) . ') ORDER BY u.name', $ids) as $r) {
                $out[(int) $r['event_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
            }
        }
        return $out;
    }

    /** @param int[] $userIds */
    public function setParticipants(int $eventId, array $userIds): void
    {
        $this->exec('DELETE FROM calendar_participants WHERE event_id = ?', [$eventId]);
        foreach ($userIds as $uid) {
            $this->exec('INSERT INTO calendar_participants (event_id, user_id) VALUES (?, ?)', [$eventId, $uid]);
        }
    }

    public function isParticipant(int $eventId, int $userId): bool
    {
        return (bool) $this->value('SELECT 1 FROM calendar_participants WHERE event_id = ? AND user_id = ?', [$eventId, $userId]);
    }

    /** Aktive Mitglieder des Haushalts (id, name) */
    public function members(int $householdId): array
    {
        return $this->many('SELECT id, name, role FROM users WHERE household_id = ? AND active = 1 ORDER BY name', [$householdId]);
    }

    /** @return int[] */
    public function householdUserIds(int $householdId): array
    {
        return array_map(fn ($r) => (int) $r['id'], $this->members($householdId));
    }

    // ---- Budgets ---------------------------------------------------------------------------

    public function budget(int $eventId, int $userId): ?array
    {
        return $this->one('SELECT * FROM calendar_budgets WHERE event_id = ? AND user_id = ?', [$eventId, $userId]);
    }

    /** @param int[] $eventIds @return array<int, array> Termin => Budget des Benutzers */
    public function budgetsFor(array $eventIds, int $userId): array
    {
        $eventIds = array_values($eventIds);
        $out = [];
        if ($eventIds) {
            foreach ($this->many(
                'SELECT b.*, a.name AS account_name, a.type AS account_type FROM calendar_budgets b LEFT JOIN accounts a ON a.id = b.account_id
                 WHERE b.user_id = ? AND b.event_id IN (' . self::in($eventIds) . ')', [$userId, ...$eventIds]) as $r) {
                $out[(int) $r['event_id']] = $r;
            }
        }
        return $out;
    }

    /** Alle Budgets eines Termins (für Admins), mit Benutzername */
    public function budgetsOfEvent(int $eventId): array
    {
        return $this->many(
            'SELECT b.*, u.name AS user_name, a.name AS account_name FROM calendar_budgets b
             JOIN users u ON u.id = b.user_id LEFT JOIN accounts a ON a.id = b.account_id
             WHERE b.event_id = ? ORDER BY u.name',
            [$eventId]
        );
    }

    public function saveBudget(int $eventId, int $userId, string $amount, ?int $accountId, ?int $categoryId, ?string $note): void
    {
        $this->exec(
            'INSERT INTO calendar_budgets (event_id, user_id, amount, account_id, category_id, note) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount), account_id = VALUES(account_id), category_id = VALUES(category_id), note = VALUES(note)',
            [$eventId, $userId, $amount, $accountId, $categoryId, $note]
        );
    }

    public function deleteBudget(int $eventId, int $userId): void
    {
        $this->exec('DELETE FROM calendar_budgets WHERE event_id = ? AND user_id = ?', [$eventId, $userId]);
    }

    /** Budgets von Nicht-(mehr-)Teilnehmern entfernen, sofern noch nichts gebucht wurde */
    public function deleteOrphanBudgets(int $eventId, array $participantIds): void
    {
        $this->exec(
            'DELETE b FROM calendar_budgets b
             WHERE b.event_id = ? AND b.user_id NOT IN (' . self::in($participantIds) . ')
               AND NOT EXISTS (SELECT 1 FROM calendar_budget_bookings k WHERE k.budget_id = b.id)',
            [$eventId, ...$participantIds]
        );
    }

    /** @param int[] $budgetIds @return array<int, array<string, array>> Budget => Vorkommen => Buchung */
    public function bookingsFor(array $budgetIds): array
    {
        $budgetIds = array_values($budgetIds);
        $out = [];
        if ($budgetIds) {
            foreach ($this->many(
                'SELECT k.*, t.amount AS tx_amount, t.account_id AS tx_account_id, a.name AS account_name, a.type AS account_type
                 FROM calendar_budget_bookings k JOIN transactions t ON t.id = k.transaction_id LEFT JOIN accounts a ON a.id = t.account_id
                 WHERE k.budget_id IN (' . self::in($budgetIds) . ')', $budgetIds) as $r) {
                $out[(int) $r['budget_id']][$r['occurrence_date']] = $r;
            }
        }
        return $out;
    }

    public function booking(int $budgetId, string $occurrence): ?array
    {
        return $this->one('SELECT * FROM calendar_budget_bookings WHERE budget_id = ? AND occurrence_date = ?', [$budgetId, $occurrence]);
    }

    public function addBooking(int $budgetId, string $occurrence, int $transactionId, int $userId): void
    {
        $this->insert('calendar_budget_bookings', [
            'budget_id' => $budgetId, 'occurrence_date' => $occurrence, 'transaction_id' => $transactionId, 'booked_by' => $userId,
        ]);
    }

    /**
     * Budgets des Haushalts auf den angegebenen Konten – für die Prognose (mit Termindaten und gebuchten Vorkommen).
     * @param int[] $accountIds
     */
    public function budgetsForForecast(int $householdId, array $accountIds): array
    {
        if (!$accountIds) {
            return [];
        }
        $rows = $this->many(
            'SELECT b.id AS budget_id, b.amount, b.account_id, b.user_id, u.name AS user_name,
                    e.id, e.title, e.start_at, e.end_at, e.recurrence, e.recurrence_interval, e.recurrence_until
             FROM calendar_budgets b
             JOIN calendar_events e ON e.id = b.event_id
             JOIN users u ON u.id = b.user_id
             WHERE e.household_id = ? AND b.amount > 0 AND b.account_id IN (' . self::in($accountIds) . ')',
            [$householdId, ...$accountIds]
        );
        if (!$rows) {
            return [];
        }
        $exceptions = $this->exceptionsFor(array_values(array_unique(array_map(fn ($r) => (int) $r['id'], $rows))));
        $booked = $this->bookingsFor(array_map(fn ($r) => (int) $r['budget_id'], $rows));
        foreach ($rows as &$r) {
            $r['exceptions'] = $exceptions[(int) $r['id']] ?? [];
            $r['booked'] = array_keys($booked[(int) $r['budget_id']] ?? []);
        }
        unset($r);
        return $rows;
    }

    // ---- Vorlagen (persönlich) -------------------------------------------------------------

    public function templates(int $householdId, int $userId): array
    {
        return $this->many('SELECT * FROM calendar_templates WHERE household_id = ? AND user_id = ? ORDER BY name', [$householdId, $userId]);
    }

    public function template(int $id, int $householdId, int $userId): ?array
    {
        return $this->one('SELECT * FROM calendar_templates WHERE id = ? AND household_id = ? AND user_id = ?', [$id, $householdId, $userId]);
    }

    public function createTemplate(int $householdId, int $userId, array $data): int
    {
        return $this->insert('calendar_templates', ['household_id' => $householdId, 'user_id' => $userId] + $data);
    }

    public function updateTemplate(int $id, int $householdId, int $userId, array $data): void
    {
        $this->updateWhere('calendar_templates', $data, ['id' => $id, 'household_id' => $householdId, 'user_id' => $userId]);
    }

    public function deleteTemplate(int $id, int $householdId, int $userId): void
    {
        $this->exec('DELETE FROM calendar_templates WHERE id = ? AND household_id = ? AND user_id = ?', [$id, $householdId, $userId]);
    }
}
