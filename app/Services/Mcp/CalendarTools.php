<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Core\Auth;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\CalendarRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\UserPreferenceRepository;
use App\Services\CalendarService;
use DateTimeImmutable;

/**
 * MCP-Tools für den Kalender: Termine des Benutzers (eigene und als Teilnehmer), anlegen/ändern/löschen, Vorlagen.
 * Budgets nur, wenn der Benutzer sie in den Einstellungen für Claude freigegeben hat (user_preferences
 * mcp_calendar_budgets = 1); gebucht wird dann ausschließlich auf Bargeld-Konten mit Buchungsrecht, ohne Saldo in der Antwort.
 */
final class CalendarTools extends ToolSet
{
    /** Tools, die nur mit freigegebenen Budgets angeboten werden */
    public const BUDGET_TOOLS = ['set_event_budget', 'book_event_budget'];

    private ?bool $budgets = null;
    private ?CalendarRepository $repo = null;

    /** Budgets für Claude freigegeben? (persönliche Einstellung) */
    public function budgetsEnabled(): bool
    {
        return $this->budgets ??= (new UserPreferenceRepository())->get($this->userId, 'mcp_calendar_budgets') === '1';
    }

    /** Budget-Tools nur bei Freigabe (reine Funktion, testbar) */
    public static function visibleTools(array $tools, bool $budgets): array
    {
        return $budgets ? $tools : array_diff_key($tools, array_flip(self::BUDGET_TOOLS));
    }

    public function tools(): array
    {
        $budgets = $this->budgetsEnabled();
        $eventId = ['type' => 'integer', 'description' => 'ID des Termins (aus list_calendar)'];
        $occurrence = ['type' => 'string', 'description' => 'Bei Terminserien: Datum des Vorkommens JJJJ-MM-TT (aus list_calendar)'];
        $eventFields = [
            'title'               => ['type' => 'string', 'description' => 'Titel, z. B. „Zahnarzt“'],
            'start'               => ['type' => 'string', 'description' => 'Beginn „JJJJ-MM-TT HH:MM“; ganztägig nur „JJJJ-MM-TT“'],
            'end'                 => ['type' => 'string', 'description' => 'Ende „JJJJ-MM-TT HH:MM“ (ganztägig: letzter Tag JJJJ-MM-TT); alternativ duration_minutes'],
            'duration_minutes'    => ['type' => 'integer', 'minimum' => 1, 'description' => 'Dauer in Minuten statt end'],
            'all_day'             => ['type' => 'boolean', 'description' => 'ganztägig'],
            'location'            => ['type' => 'string'],
            'note'                => ['type' => 'string'],
            'lead_minutes'        => ['type' => 'integer', 'minimum' => 0, 'description' => 'Vorlauf vor dem Termin (Anreise) in Minuten'],
            'trail_minutes'       => ['type' => 'integer', 'minimum' => 0, 'description' => 'Nachlauf nach dem Termin (Abreise) in Minuten'],
            'participants'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Namen weiterer Teilnehmer (list_household_members); der Ersteller ist immer dabei'],
            'recurrence'          => ['type' => 'string', 'enum' => array_keys(CalendarService::RECURRENCE), 'description' => 'Wiederholung'],
            'recurrence_interval' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'description' => 'z. B. 2 = alle zwei Wochen'],
            'recurrence_until'    => ['type' => 'string', 'description' => 'letzte Wiederholung JJJJ-MM-TT'],
            'color'               => ['type' => 'string', 'description' => 'Farbe #rrggbb'],
        ];
        $budgetFields = $budgets ? [
            'budget_amount'   => ['type' => 'number', 'minimum' => 0, 'description' => 'eigenes Budget in Euro je Termin (0 entfernt es)'],
            'budget_category' => ['type' => 'string', 'description' => 'Ausgaben-Kategorie des Budgets'],
        ] : [];

        $tools = [
            'list_household_members' => [
                'title' => 'Haushaltsmitglieder',
                'description' => 'Namen der Haushaltsmitglieder – für Teilnehmer von Terminen.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => array_map(fn ($m) => ['user_id' => (int) $m['id'], 'name' => $m['name'],
                    'me' => (int) $m['id'] === $this->userId], $this->repo()->members($this->hid)),
            ],
            'list_calendar' => [
                'title' => 'Kalender anzeigen',
                'description' => 'Termine des Benutzers im Zeitraum: eigene (role „owner“) und solche, bei denen er Teilnehmer ist '
                    . '(role „participant“). Vorlauf (Anreise) und Nachlauf (Abreise) gehören zum Termin und stehen getrennt dabei. '
                    . 'Ohne Angaben: heute bis 14 Tage, höchstens 92 Tage.' . ($budgets ? ' Mit eigenem Budget je Termin (offen/gebucht).' : ''),
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'from' => ['type' => 'string', 'description' => 'JJJJ-MM-TT, Standard heute'],
                    'to'   => ['type' => 'string', 'description' => 'JJJJ-MM-TT, Standard in 14 Tagen'],
                ]],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->listCalendar($a),
            ],
            'get_event' => [
                'title' => 'Termin anzeigen',
                'description' => 'Details eines Termins inkl. Notiz, Teilnehmer, Wiederholung' . ($budgets ? ' und eigenem Budget' : '') . '.',
                'inputSchema' => ['type' => 'object', 'properties' => ['event_id' => $eventId, 'occurrence_date' => $occurrence], 'required' => ['event_id']],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->getEvent($a),
            ],
            'list_event_templates' => [
                'title' => 'Terminvorlagen',
                'description' => 'Eigene Terminvorlagen mit Dauer, Vorlauf (Anreise) und Nachlauf (Abreise). In create_event per template nutzbar.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->listTemplates(),
            ],
            'create_event' => [
                'title' => 'Termin anlegen',
                'description' => 'Termin im Kalender anlegen. Mit template übernimmt er Dauer, Vor-/Nachlauf, Ort und Farbe der Vorlage '
                    . '(explizite Angaben haben Vorrang). Weitere Teilnehmer per Name.',
                'inputSchema' => ['type' => 'object', 'properties' => $eventFields + [
                    'template' => ['type' => 'string', 'description' => 'Name einer Vorlage (list_event_templates)'],
                ] + $budgetFields, 'required' => ['title', 'start']],
                'annotations' => self::write(false, false),
                'handler' => fn (array $a) => $this->createEvent($a),
            ],
            'update_event' => [
                'title' => 'Termin ändern',
                'description' => 'Termin ändern (nur eigene Termine). Nur angegebene Felder ändern sich; participants ersetzt die Teilnehmerliste. '
                    . 'Wird nur start angegeben, bleibt die Dauer gleich. Änderungen gelten für die ganze Serie.',
                'inputSchema' => ['type' => 'object', 'properties' => ['event_id' => $eventId] + $eventFields + $budgetFields, 'required' => ['event_id']],
                'annotations' => self::write(false, true),
                'handler' => fn (array $a) => $this->updateEvent($a),
            ],
            'delete_event' => [
                'title' => 'Termin löschen',
                'description' => 'Eigenen Termin löschen. Mit occurrence_date nur dieses eine Vorkommen einer Serie, sonst den Termin bzw. die '
                    . 'ganze Serie. Nur nach ausdrücklicher Bestätigung aufrufen.',
                'inputSchema' => ['type' => 'object', 'properties' => ['event_id' => $eventId, 'occurrence_date' => $occurrence], 'required' => ['event_id']],
                'annotations' => self::write(true, true),
                'handler' => fn (array $a) => $this->deleteEvent($a),
            ],
            'set_event_budget' => [
                'title' => 'Budget für Termin planen',
                'description' => 'Eigenes Budget an einem Termin setzen (gilt je Vorkommen; 0 entfernt es). Es fließt in die Prognose ein, '
                    . 'gebucht wird erst mit book_event_budget.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'event_id' => $eventId,
                    'amount'   => ['type' => 'number', 'minimum' => 0, 'description' => 'Betrag in Euro'],
                    'category' => ['type' => 'string', 'description' => 'Ausgaben-Kategorie, optional'],
                    'account'  => ['type' => 'string', 'description' => 'Bargeld-Konto, optional (sonst bleibt das bisherige bzw. das Vorzugskonto)'],
                ], 'required' => ['event_id', 'amount']],
                'annotations' => self::write(false, true),
                'handler' => fn (array $a) => $this->setBudget($a),
            ],
            'book_event_budget' => [
                'title' => 'Budget als Bargeld-Ausgabe buchen',
                'description' => 'Das eigene Budget eines Termins als Ausgabe auf ein Bargeld-Konto buchen; der Betrag kann abweichen '
                    . '(amount). Nur Bargeld-Konten mit Buchungsrecht, höchstens ' . Money::format(McpTools::CASH_MAX_CENTS) . '. '
                    . 'Vorher Betrag bestätigen lassen. Die Antwort enthält keinen Kontostand.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'event_id'        => $eventId,
                    'occurrence_date' => $occurrence,
                    'amount'          => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'tatsächlicher Betrag in Euro, Standard = Budget'],
                    'account'         => ['type' => 'string', 'description' => 'Name des Bargeld-Kontos, nur nötig bei mehreren'],
                    'category'        => ['type' => 'string', 'description' => 'Kategorie, Standard = die des Budgets'],
                ], 'required' => ['event_id']],
                'annotations' => self::write(false, false),
                'handler' => fn (array $a) => $this->bookBudget($a),
            ],
        ];
        return self::visibleTools($tools, $budgets);
    }

    // ---- Lesen ------------------------------------------------------------------------------

    private function listCalendar(array $a): array
    {
        $from = self::str($a, 'from', 10) ?: date('Y-m-d');
        $to = self::str($a, 'to', 10) ?: date('Y-m-d', strtotime($from . ' +14 days'));
        if (valid_date($from) === null || valid_date($to) === null || $to < $from) {
            throw new ToolError('Zeitraum bitte als JJJJ-MM-TT angeben (from ≤ to).');
        }
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 92) {
            throw new ToolError('Höchstens 92 Tage auf einmal.');
        }
        $items = CalendarService::forUser($this->hid, $this->userId, $from, $to, $this->budgetsEnabled() ? $this->userId : null);
        return ['from' => $from, 'to' => $to, 'events' => array_map(fn ($o) => $this->occurrenceOut($o), $items)];
    }

    private function getEvent(array $a): array
    {
        $event = $this->event($a);
        $id = (int) $event['id'];
        $date = $this->occurrence($event, self::str($a, 'occurrence_date', 10), false);
        $participants = $this->repo()->participantsFor([$id])[$id] ?? [];
        $out = $this->occurrenceOut([
            'event' => $event, 'role' => CalendarService::role($event, $this->userId), 'participants' => $participants,
        ] + CalendarService::instance($event, $date));
        $out['note'] = $event['note'];
        $out['can_edit'] = CalendarService::canEdit($event, $this->userId, Auth::isAdmin());
        if ($event['recurrence'] !== 'none') {
            $exceptions = $this->repo()->exceptionsFor([$id])[$id] ?? [];
            $out['next_occurrences'] = array_slice(CalendarService::occurrences($event, date('Y-m-d'), date('Y-m-d', strtotime('+1 year')), $exceptions), 0, 10);
        }
        if ($this->budgetsEnabled()) {
            $out['my_budget'] = $this->budgetOut($id, $date);
        }
        return $out;
    }

    private function listTemplates(): array
    {
        return array_map(fn ($t) => [
            'name'          => $t['name'],
            'title'         => $t['title'] ?: $t['name'],
            'duration'      => CalendarService::formatDuration((int) $t['duration_minutes']),
            'all_day'       => (bool) $t['all_day'],
            'lead_minutes'  => (int) $t['lead_minutes'],
            'trail_minutes' => (int) $t['trail_minutes'],
            'location'      => $t['location'],
        ] + ($this->budgetsEnabled() && $t['budget_amount'] !== null ? ['budget' => Money::format(Money::toCents($t['budget_amount']))] : []),
            $this->repo()->templates($this->hid, $this->userId));
    }

    // ---- Schreiben ----------------------------------------------------------------------------

    private function createEvent(array $a): string
    {
        $tpl = null;
        if (($name = self::str($a, 'template', 100)) !== '') {
            $tpl = $this->template($name);
        }
        $start = self::str($a, 'start', 20);
        $base = [];
        if ($tpl) {
            if (CalendarService::parseDateTime($start, true) === null) {
                throw new ToolError('Beginn bitte als „JJJJ-MM-TT HH:MM“ (ganztägig „JJJJ-MM-TT“) angeben.');
            }
            $allDay = array_key_exists('all_day', $a) ? self::bool($a, 'all_day', false) : (bool) $tpl['all_day'];
            $applied = CalendarService::applyTemplate(['all_day' => $allDay] + $tpl, $allDay ? substr($start, 0, 10) : str_replace('T', ' ', $start));
            $base = self::toInput($applied);
        }
        $in = $this->eventInput($a, $base);
        $data = $this->build($in);
        $participants = $this->participantIds($a);
        $id = CalendarService::save($this->hid, $this->userId, null, $data, $participants);
        $budgetMsg = $this->budgetFromEventArgs($id, $a, $tpl);
        $msg = "Termin angelegt: „{$data['title']}“ " . self::when($data) . ($budgetMsg ? " · $budgetMsg" : '') . " (event_id $id)";
        $this->log('create_event', mb_substr($msg, 0, 255));
        return $msg;
    }

    private function updateEvent(array $a): string
    {
        $event = $this->event($a);
        $id = (int) $event['id'];
        $onlyBudget = !array_diff(array_keys($a), ['event_id', 'budget_amount', 'budget_category']);
        if ($onlyBudget && $this->budgetsEnabled() && array_key_exists('budget_amount', $a) && $this->repo()->isParticipant($id, $this->userId)) {
            // Teilnehmer dürfen ihr eigenes Budget auch ohne Bearbeitungsrecht setzen
            $msg = $this->budgetFromEventArgs($id, $a, null) . " für „{$event['title']}“";
            $this->log('update_event', mb_substr($msg, 0, 255));
            return $msg;
        }
        if (!CalendarService::canEdit($event, $this->userId, Auth::isAdmin())) {
            throw new ToolError('Nur wer den Termin angelegt hat, kann ihn ändern.' . ($this->budgetsEnabled() ? ' Das eigene Budget geht mit set_event_budget.' : ''));
        }
        $base = self::toInput($event);
        if (isset($a['start']) && !isset($a['end']) && !isset($a['duration_minutes'])) {
            // Dauer beibehalten
            $a['duration_minutes'] = (int) round((strtotime($event['end_at']) - strtotime($event['start_at'])) / 60);
            if ($event['all_day']) {
                unset($a['duration_minutes']);
                $days = (int) round((strtotime($event['end_at']) - strtotime($event['start_at'])) / 86400) - 1;
                $a['end'] = date('Y-m-d', strtotime(substr((string) $a['start'], 0, 10) . " +$days days"));
            }
        }
        $data = $this->build($this->eventInput($a, $base));
        $participants = array_key_exists('participants', $a)
            ? $this->participantIds($a)
            : array_column($this->repo()->participantsFor([$id])[$id] ?? [], 'id');
        CalendarService::save($this->hid, $this->userId, $id, $data, $participants);
        $budgetMsg = $this->repo()->isParticipant($id, $this->userId) ? $this->budgetFromEventArgs($id, $a, null) : null;
        $msg = "Termin geändert: „{$data['title']}“ " . self::when($data) . ($budgetMsg ? " · $budgetMsg" : '');
        $this->log('update_event', mb_substr($msg, 0, 255));
        return $msg;
    }

    private function deleteEvent(array $a): string
    {
        $event = $this->event($a);
        if (!CalendarService::canEdit($event, $this->userId, Auth::isAdmin())) {
            throw new ToolError('Nur wer den Termin angelegt hat, kann ihn löschen.');
        }
        $date = self::str($a, 'occurrence_date', 10);
        if ($date !== '' && $event['recurrence'] !== 'none') {
            $date = $this->occurrence($event, $date, true);
            $this->repo()->addException((int) $event['id'], $date);
            $msg = "Termin „{$event['title']}“ am " . date_de($date) . ' gelöscht (Serie bleibt).';
        } else {
            $this->repo()->delete((int) $event['id'], $this->hid);
            $msg = "Termin „{$event['title']}“ gelöscht" . ($event['recurrence'] !== 'none' ? ' (ganze Serie).' : '.');
        }
        $this->log('delete_event', mb_substr($msg, 0, 255));
        return $msg;
    }

    private function setBudget(array $a): string
    {
        $event = $this->event($a);
        $id = (int) $event['id'];
        if (!$this->repo()->isParticipant($id, $this->userId)) {
            throw new ToolError('Budgets gibt es nur für Teilnehmer des Termins.');
        }
        $msg = $this->saveBudget($id, $a['amount'] ?? null, self::str($a, 'category', 100), self::str($a, 'account', 100), null)
            . " für „{$event['title']}“";
        $this->log('set_event_budget', mb_substr($msg, 0, 255));
        return $msg;
    }

    private function bookBudget(array $a): string
    {
        $event = $this->event($a);
        $id = (int) $event['id'];
        $budget = $this->repo()->budget($id, $this->userId) ?? throw new ToolError('Für diesen Termin hast du kein Budget (set_event_budget).');
        $date = $this->occurrence($event, self::str($a, 'occurrence_date', 10), true);
        if ($this->repo()->booking((int) $budget['id'], $date)) {
            throw new ToolError('Das Budget ist für den Termin am ' . date_de($date) . ' schon gebucht.');
        }
        $raw = $a['amount'] ?? null;
        $cents = $raw === null ? Money::toCents($budget['amount']) : (is_int($raw) || is_float($raw) || is_string($raw) ? Money::parse($raw) : null);
        if ($cents === null || $cents <= 0) {
            throw new ToolError('Bitte einen positiven Betrag in Euro angeben.');
        }
        if ($cents > McpTools::CASH_MAX_CENTS) {
            throw new ToolError('Beträge über ' . Money::format(McpTools::CASH_MAX_CENTS) . ' bitte in der Web-App buchen.');
        }
        // Nur Bargeld-Konten: angegebenes, sonst das Budget-Konto (falls Bargeld), sonst das einzige
        $name = self::str($a, 'account', 100);
        if ($name === '' && $budget['account_id']) {
            foreach ($this->cashAccounts() as $acc) {
                if ((int) $acc['id'] === (int) $budget['account_id']) {
                    $name = $acc['name'];
                }
            }
        }
        $account = $this->cashAccount($name);
        $category = self::str($a, 'category', 100);
        $categoryId = $category !== '' ? $this->categoryByName($category) : (new CategoryRepository())->validId($budget['category_id'] ? (int) $budget['category_id'] : null, $this->hid);
        $bookingDate = min($date, date('Y-m-d'));
        try {
            CalendarService::bookBudget($this->hid, $this->userId, $budget, $event, $date, $cents, (int) $account['id'], $bookingDate, $categoryId, 'per Claude');
        } catch (\RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
        $msg = 'Gebucht: ' . Money::format($cents) . " „{$event['title']}“ (Termin " . date_de($date) . ') am ' . date_de($bookingDate)
            . " · Konto: {$account['name']}";
        $this->log('book_event_budget', mb_substr($msg, 0, 255));
        return $msg;
    }

    // ---- Hilfen ---------------------------------------------------------------------------------

    private function repo(): CalendarRepository
    {
        return $this->repo ??= new CalendarRepository();
    }

    /** Termin per event_id; sichtbar für alle im Haushalt */
    private function event(array $a): array
    {
        $id = self::int($a, 'event_id') ?? throw new ToolError('Bitte event_id angeben (aus list_calendar).');
        return $this->repo()->find($id, $this->hid) ?? throw new ToolError("Termin $id gibt es nicht.");
    }

    /** Gültiges Vorkommen; ohne Angabe das nächste ab heute (bzw. bei Einzelterminen der Termin selbst) */
    private function occurrence(array $event, string $date, bool $strict): string
    {
        $id = (int) $event['id'];
        $exceptions = $this->repo()->exceptionsFor([$id])[$id] ?? [];
        if ($date !== '') {
            if (valid_date($date) === null || !CalendarService::occurrences($event, $date, $date, $exceptions)) {
                throw new ToolError("Am $date findet „{$event['title']}“ nicht statt.");
            }
            return $date;
        }
        if ($event['recurrence'] === 'none') {
            return substr($event['start_at'], 0, 10);
        }
        if ($strict) {
            throw new ToolError('Bei Terminserien bitte occurrence_date angeben.');
        }
        return CalendarService::occurrences($event, date('Y-m-d'), date('Y-m-d', strtotime('+3 years')), $exceptions)[0]
            ?? substr($event['start_at'], 0, 10);
    }

    private function template(string $name): array
    {
        $all = $this->repo()->templates($this->hid, $this->userId);
        foreach ($all as $t) {
            if (mb_strtolower($t['name']) === mb_strtolower($name)) {
                return $t;
            }
        }
        $hits = array_values(array_filter($all, fn ($t) => mb_stripos($t['name'], $name) !== false));
        if (count($hits) === 1) {
            return $hits[0];
        }
        throw new ToolError("Vorlage „{$name}“ " . ($hits ? 'ist mehrdeutig' : 'gibt es nicht') . '. Vorlagen: '
            . (implode(', ', array_column($hits ?: $all, 'name')) ?: 'keine') . '.');
    }

    /** Teilnehmer-IDs aus Namen (Ersteller kommt in CalendarService::save dazu) */
    private function participantIds(array $a): array
    {
        $members = $this->repo()->members($this->hid);
        $ids = [];
        foreach (self::strings($a, 'participants') as $name) {
            $hit = null;
            foreach ($members as $m) {
                if (mb_strtolower($m['name']) === mb_strtolower($name)) {
                    $hit = $m;
                }
            }
            if (!$hit) {
                $hits = array_values(array_filter($members, fn ($m) => mb_stripos($m['name'], $name) !== false));
                if (count($hits) !== 1) {
                    throw new ToolError("Teilnehmer „{$name}“ " . ($hits ? 'ist mehrdeutig' : 'gibt es nicht') . '. Mitglieder: '
                        . implode(', ', array_column($members, 'name')) . '.');
                }
                $hit = $hits[0];
            }
            $ids[] = (int) $hit['id'];
        }
        return $ids;
    }

    /** Spalten eines Termins (bzw. einer angewendeten Vorlage) als Eingabe für buildEvent() */
    private static function toInput(array $e): array
    {
        $allDay = (bool) $e['all_day'];
        return [
            'title' => $e['title'], 'location' => $e['location'] ?? null, 'note' => $e['note'] ?? null, 'all_day' => $allDay,
            'start' => $allDay ? substr($e['start_at'], 0, 10) : substr($e['start_at'], 0, 16),
            'end'   => $allDay ? date('Y-m-d', strtotime($e['end_at'] . ' -1 day')) : substr($e['end_at'], 0, 16),
            'lead_minutes' => (int) $e['lead_minutes'], 'trail_minutes' => (int) $e['trail_minutes'], 'color' => $e['color'] ?? null,
            'recurrence' => $e['recurrence'] ?? 'none', 'recurrence_interval' => $e['recurrence_interval'] ?? 1,
            'recurrence_until' => $e['recurrence_until'] ?? null, 'template_id' => $e['template_id'] ?? null,
        ];
    }

    /** Argumente des Tools über die Basiswerte legen */
    private function eventInput(array $a, array $base): array
    {
        $in = $base;
        foreach (['title', 'location', 'note', 'color', 'recurrence', 'recurrence_until'] as $k) {
            if (array_key_exists($k, $a)) {
                $in[$k] = self::str($a, $k, $k === 'note' ? 5000 : 190);
            }
        }
        foreach (['lead_minutes', 'trail_minutes', 'recurrence_interval'] as $k) {
            if (array_key_exists($k, $a)) {
                $in[$k] = self::int($a, $k) ?? throw new ToolError("$k muss eine Zahl sein.");
            }
        }
        if (array_key_exists('all_day', $a)) {
            $in['all_day'] = self::bool($a, 'all_day', false);
        }
        $allDay = !empty($in['all_day']);
        if (array_key_exists('start', $a)) {
            $in['start'] = $allDay ? substr(self::str($a, 'start', 20), 0, 10) : self::str($a, 'start', 20);
            if (!array_key_exists('end', $a) && !array_key_exists('duration_minutes', $a) && !$base) {
                $in['end'] = ''; // Standard: 1 Stunde bzw. ein Tag
            }
        }
        if (array_key_exists('end', $a)) {
            $in['end'] = $allDay ? substr(self::str($a, 'end', 20), 0, 10) : self::str($a, 'end', 20);
        } elseif (($min = self::int($a, 'duration_minutes')) !== null) {
            $start = CalendarService::parseDateTime((string) ($in['start'] ?? ''), $allDay)
                ?? throw new ToolError('Beginn bitte als „JJJJ-MM-TT HH:MM“ angeben.');
            $in['end'] = $allDay
                ? $start->modify('+' . max(0, (int) ceil($min / 1440) - 1) . ' days')->format('Y-m-d')
                : $start->modify("+$min minutes")->format('Y-m-d H:i');
        }
        return $in;
    }

    private function build(array $in): array
    {
        try {
            return CalendarService::buildEvent($in);
        } catch (\InvalidArgumentException $e) {
            throw new ToolError($e->getMessage());
        }
    }

    /** Budget aus create/update-Argumenten bzw. Vorlage setzen; gibt eine kurze Meldung zurück */
    private function budgetFromEventArgs(int $eventId, array $a, ?array $tpl): ?string
    {
        if (!$this->budgetsEnabled()) {
            return null;
        }
        if (array_key_exists('budget_amount', $a)) {
            return $this->saveBudget($eventId, $a['budget_amount'], self::str($a, 'budget_category', 100), '', null);
        }
        if ($tpl && $tpl['budget_amount'] !== null) {
            $accountId = $tpl['budget_account_id'] && Auth::can((int) $tpl['budget_account_id'], 'book') ? (int) $tpl['budget_account_id'] : null;
            return $this->saveBudget($eventId, $tpl['budget_amount'], '', '', $accountId, $tpl['budget_category_id'] ? (int) $tpl['budget_category_id'] : null);
        }
        return null;
    }

    /** Eigenes Budget speichern (Betrag 0 entfernt es, sofern nichts gebucht ist) */
    private function saveBudget(int $eventId, mixed $amount, string $category, string $accountName, ?int $accountId, ?int $categoryId = null): string
    {
        $cents = is_int($amount) || is_float($amount) || is_string($amount) ? Money::parse($amount) : null;
        if ($cents === null || $cents < 0) {
            throw new ToolError('Bitte einen Budgetbetrag in Euro angeben (0 entfernt es).');
        }
        $existing = $this->repo()->budget($eventId, $this->userId);
        if ($cents === 0) {
            if ($existing && ($this->repo()->bookingsFor([(int) $existing['id']])[(int) $existing['id']] ?? [])) {
                throw new ToolError('Das Budget wurde schon gebucht und bleibt deshalb bestehen.');
            }
            $this->repo()->deleteBudget($eventId, $this->userId);
            return 'Budget entfernt';
        }
        if ($accountName !== '') {
            $accountId = (int) $this->cashAccount($accountName)['id'];
        }
        if ($accountId === null && !empty($existing['account_id'])) {
            $accountId = (int) $existing['account_id'];
        }
        $accountId ??= (new UserPreferenceRepository())->account($this->userId, 'account_calendar', Auth::accountIds('book'));
        if ($category !== '') {
            $categoryId = $this->categoryByName($category);
        } elseif ($categoryId === null && !empty($existing['category_id'])) {
            $categoryId = (int) $existing['category_id'];
        }
        $this->repo()->saveBudget($eventId, $this->userId, Money::toDecimal($cents), $accountId, $categoryId, $existing['note'] ?? null);
        return 'Budget ' . Money::format($cents) . ($accountId ? '' : ' (ohne Konto – erscheint nicht in der Prognose)');
    }

    /** Bargeld-Konten mit Buchungsrecht */
    private function cashAccounts(): array
    {
        return array_values(array_filter(
            (new AccountRepository())->options($this->hid, Auth::accountIds('book')),
            fn ($acc) => $acc['type'] === 'cash'
        ));
    }

    /** Budget eines Vorkommens: Betrag und Status – ohne Kontostand, Kontoname nur bei Bargeld */
    private function budgetOut(int $eventId, string $date): ?array
    {
        $budget = $this->repo()->budget($eventId, $this->userId);
        if (!$budget) {
            return null;
        }
        $booking = $this->repo()->bookingsFor([(int) $budget['id']])[(int) $budget['id']][$date] ?? null;
        return self::budgetStatus($budget, $booking);
    }

    private static function budgetStatus(array $budget, ?array $booking): array
    {
        $out = ['amount' => Money::format(Money::toCents($budget['amount'])), 'status' => $booking ? 'gebucht' : 'offen'];
        if ($booking) {
            $out['booked_amount'] = Money::format(abs(Money::toCents($booking['tx_amount'])));
            if (($booking['account_type'] ?? '') === 'cash') {
                $out['booked_account'] = $booking['account_name'];
            }
        }
        return $out;
    }

    private function occurrenceOut(array $o): array
    {
        $e = $o['event'];
        $allDay = (bool) $e['all_day'];
        $out = [
            'event_id'        => (int) $e['id'],
            'occurrence_date' => $o['date'],
            'title'           => $e['title'],
            'all_day'         => $allDay,
            'start'           => $allDay ? $o['date'] : substr($o['start'], 0, 16),
            'end'             => $allDay ? date('Y-m-d', strtotime($o['end'] . ' -1 day')) : substr($o['end'], 0, 16),
            'role'            => $o['role'],
            'created_by'      => $e['creator_name'] ?? null,
            'participants'    => array_column($o['participants'], 'name'),
        ];
        if ((int) $e['lead_minutes']) {
            $out['lead'] = ['minutes' => (int) $e['lead_minutes'], 'from' => substr($o['lead_start'], 0, 16)];
        }
        if ((int) $e['trail_minutes']) {
            $out['trail'] = ['minutes' => (int) $e['trail_minutes'], 'until' => substr($o['trail_end'], 0, 16)];
        }
        if ($e['location']) {
            $out['location'] = $e['location'];
        }
        if ($e['recurrence'] !== 'none') {
            $out['recurrence'] = CalendarService::RECURRENCE[$e['recurrence']] . ((int) $e['recurrence_interval'] > 1 ? ' (alle ' . (int) $e['recurrence_interval'] . ')' : '');
        }
        if ($this->budgetsEnabled() && !empty($o['budget'])) {
            $out['my_budget'] = self::budgetStatus($o['budget'], $o['booking'] ?? null);
        }
        return $out;
    }

    private static function when(array $data): string
    {
        if ($data['all_day']) {
            $last = date('Y-m-d', strtotime($data['end_at'] . ' -1 day'));
            return 'am ' . date_de(substr($data['start_at'], 0, 10)) . ($last !== substr($data['start_at'], 0, 10) ? ' bis ' . date_de($last) : '') . ' (ganztägig)';
        }
        return 'am ' . date_de(substr($data['start_at'], 0, 10)) . ' ' . substr($data['start_at'], 11, 5) . '–' . substr($data['end_at'], 11, 5)
            . ((int) $data['lead_minutes'] ? ', Vorlauf ' . CalendarService::formatDuration((int) $data['lead_minutes']) : '')
            . ((int) $data['trail_minutes'] ? ', Nachlauf ' . CalendarService::formatDuration((int) $data['trail_minutes']) : '');
    }
}
