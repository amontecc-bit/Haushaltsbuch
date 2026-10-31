<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\CalendarRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\UserPreferenceRepository;
use App\Services\CalendarService;
use DateTimeImmutable;

/**
 * Kalender je Haushaltsmitglied: Monats-/Wochen-/Listenansicht, Termine mit Vor-/Nachlauf und Teilnehmern,
 * persönliche Vorlagen, eigene Budgets je Termin und das Buchen eines Budgets als Ausgabe.
 */
final class CalendarController extends Controller
{
    private const VIEWS = ['month', 'week', 'list'];

    private CalendarRepository $repo;

    public function __construct(\App\Core\Request $request)
    {
        parent::__construct($request);
        $this->repo = new CalendarRepository();
    }

    public function index(): void
    {
        $view = in_array($this->request->str('view'), self::VIEWS, true) ? $this->request->str('view') : 'month';
        $members = $this->repo->members($this->hid);
        $memberIds = array_map(fn ($m) => (int) $m['id'], $members);
        $userId = (int) $this->request->int('user', Auth::id());
        $userId = in_array($userId, $memberIds, true) ? $userId : Auth::id();
        $isSelf = $userId === Auth::id();

        $date = valid_date($this->request->str('date'), date('Y-m-d'));
        $month = preg_match('/^\d{4}-\d{2}$/', $this->request->str('month')) ? $this->request->str('month') : substr($date, 0, 7);
        $d = new DateTimeImmutable($date);
        [$from, $to, $prev, $next, $heading] = match ($view) {
            'week' => (function () use ($d) {
                $mon = $d->modify('monday this week');
                $sun = $mon->modify('+6 days');
                return [$mon->format('Y-m-d'), $sun->format('Y-m-d'), ['date' => $mon->modify('-7 days')->format('Y-m-d')],
                        ['date' => $mon->modify('+7 days')->format('Y-m-d')], 'KW ' . $mon->format('W') . ' · ' . date_de($mon->format('Y-m-d')) . ' – ' . date_de($sun->format('Y-m-d'))];
            })(),
            'list' => [$date, $d->modify('+60 days')->format('Y-m-d'), ['date' => $d->modify('-60 days')->format('Y-m-d')],
                       ['date' => $d->modify('+60 days')->format('Y-m-d')], 'Ab ' . date_de($date)],
            default => (function () use ($month) {
                $first = new DateTimeImmutable($month . '-01');
                // Raster ab Montag vor dem Monatsersten bis Sonntag nach dem Monatsletzten
                $gridFrom = $first->modify('monday this week');
                $gridTo = $first->modify('last day of this month')->modify('sunday this week');
                return [$gridFrom->format('Y-m-d'), $gridTo->format('Y-m-d'), ['month' => $first->modify('-1 month')->format('Y-m')],
                        ['month' => $first->modify('+1 month')->format('Y-m')], month_de((int) $first->format('n')) . ' ' . $first->format('Y')];
            })(),
        };

        // Budgets: die eigenen; Admins sehen auch die des angezeigten Mitglieds
        $budgetUser = $isSelf || Auth::isAdmin() ? $userId : null;
        $occurrences = CalendarService::forUser($this->hid, $userId, $from, $to, $budgetUser);
        $byDay = [];
        foreach ($occurrences as $idx => $o) {
            foreach (CalendarService::segments($o, (bool) $o['event']['all_day']) as $day => $segs) {
                foreach ($segs as $s) {
                    $byDay[$day][] = $s + ['idx' => $idx];
                }
            }
        }

        $base = ['view' => $view] + ($isSelf ? [] : ['user' => $userId]);
        $this->view('calendar/index', [
            'title'       => 'Kalender',
            'actions'     => '<a class="btn btn-primary btn-sm" href="' . e(url('/calendar/new', ['date' => $date])) . '"><i class="bi bi-plus-lg"></i> Termin</a>',
            'view'        => $view,
            'base'        => $base,
            'from'        => $from,
            'to'          => $to,
            'month'       => $month,
            'date'        => $date,
            'prev'        => $base + $prev,
            'next'        => $base + $next,
            'heading'     => $heading,
            'occurrences' => $occurrences,
            'byDay'       => $byDay,
            'members'     => $members,
            'viewUser'    => $userId,
            'isSelf'      => $isSelf,
        ]);
    }

    public function show(int $id): void
    {
        $event = $this->repo->find($id, $this->hid) ?? $this->notFound();
        $exceptions = $this->repo->exceptionsFor([$id])[$id] ?? [];
        $date = valid_date($this->request->str('date'));
        if ($date === null || !CalendarService::occurrences($event, $date, $date, $exceptions)) {
            // nächstes Vorkommen ab heute, sonst das erste
            $date = CalendarService::occurrences($event, date('Y-m-d'), date('Y-m-d', strtotime('+3 years')), $exceptions)[0]
                ?? CalendarService::occurrences($event, substr($event['start_at'], 0, 10), substr($event['start_at'], 0, 10))[0]
                ?? substr($event['start_at'], 0, 10);
        }
        $instance = CalendarService::instance($event, $date);
        $participants = $this->repo->participantsFor([$id])[$id] ?? [];
        $isParticipant = in_array(Auth::id(), array_column($participants, 'id'), true);
        $budget = $isParticipant ? $this->repo->budget($id, Auth::id()) : null;
        $bookings = $budget ? ($this->repo->bookingsFor([(int) $budget['id']])[(int) $budget['id']] ?? []) : [];

        $this->view('calendar/show', [
            'title'         => $event['title'],
            'back'          => '/calendar?date=' . $date,
            'event'         => $event,
            'instance'      => $instance,
            'date'          => $date,
            'participants'  => $participants,
            'isParticipant' => $isParticipant,
            'role'          => CalendarService::role($event, Auth::id()),
            'canEdit'       => CalendarService::canEdit($event, Auth::id(), Auth::isAdmin()),
            'budget'        => $budget,
            'booking'       => $bookings[$date] ?? null,
            'allBudgets'    => Auth::isAdmin() ? $this->repo->budgetsOfEvent($id) : [],
            'accounts'      => $this->bookableAccounts(),
            'categories'    => (new CategoryRepository())->all($this->hid, 'expense'),
            'upcoming'      => $event['recurrence'] !== 'none'
                ? CalendarService::occurrences($event, date('Y-m-d'), date('Y-m-d', strtotime('+1 year')), $exceptions) : [],
        ]);
    }

    public function create(): void
    {
        $date = valid_date($this->request->str('date'), date('Y-m-d'));
        $start = $date . ' ' . (date('Y-m-d') === $date ? date('H', strtotime('+1 hour')) . ':00' : '10:00');
        $event = ['id' => null, 'title' => '', 'location' => '', 'note' => '', 'start_at' => $start . ':00',
                  'end_at' => date('Y-m-d H:i:s', strtotime($start . ' +1 hour')), 'all_day' => 0, 'lead_minutes' => 0,
                  'trail_minutes' => 0, 'color' => '#0d6efd', 'template_id' => null, 'recurrence' => 'none',
                  'recurrence_interval' => 1, 'recurrence_until' => null];
        $budget = null;
        if (($tid = $this->request->int('template')) && ($tpl = $this->repo->template($tid, $this->hid, Auth::id()))) {
            $event = CalendarService::applyTemplate($tpl, $tpl['all_day'] ? $date : $start) + $event;
            $budget = $tpl['budget_amount'] !== null ? ['amount' => $tpl['budget_amount'], 'account_id' => $tpl['budget_account_id'],
                                                      'category_id' => $tpl['budget_category_id'], 'note' => null] : null;
        }
        $this->renderForm($event, [Auth::id()], $budget, 'Neuer Termin');
    }

    public function store(): void
    {
        [$data, $participants, $budget, $error] = $this->validated();
        if ($error) {
            $this->renderForm($data, $participants, $budget, 'Neuer Termin', $error);
            return;
        }
        $id = CalendarService::save($this->hid, Auth::id(), null, $data, $participants);
        $this->saveOwnBudget($id, $budget);
        $this->redirect('/calendar/' . $id, 'Termin gespeichert.');
    }

    public function edit(int $id): void
    {
        $event = $this->editable($id);
        $participants = array_column($this->repo->participantsFor([$id])[$id] ?? [], 'id');
        $this->renderForm($event, $participants, $this->repo->budget($id, Auth::id()), 'Termin bearbeiten');
    }

    public function update(int $id): void
    {
        $event = $this->editable($id);
        [$data, $participants, $budget, $error] = $this->validated();
        if ($error) {
            $this->renderForm(['id' => $id, 'created_by' => $event['created_by']] + $data, $participants, $budget, 'Termin bearbeiten', $error);
            return;
        }
        CalendarService::save($this->hid, Auth::id(), $id, $data, $participants);
        if ($this->repo->isParticipant($id, Auth::id())) {
            $this->saveOwnBudget($id, $budget);
        }
        $this->redirect('/calendar/' . $id, 'Termin gespeichert.');
    }

    /** Löschen: scope=one (nur dieses Vorkommen einer Serie) oder die ganze Serie bzw. den Einzeltermin */
    public function delete(int $id): void
    {
        $event = $this->editable($id);
        $date = valid_date($this->request->str('date'));
        if ($this->request->str('scope') === 'one' && $event['recurrence'] !== 'none' && $date) {
            $this->repo->addException($id, $date);
            $this->redirect('/calendar?date=' . $date, 'Termin am ' . date_de($date) . ' gelöscht.');
        }
        $this->repo->delete($id, $this->hid);
        $this->redirect('/calendar?date=' . ($date ?? substr($event['start_at'], 0, 10)), 'Termin gelöscht.');
    }

    /** Eigenes Budget setzen (Teilnehmer); leerer Betrag entfernt es */
    public function budget(int $id): void
    {
        $event = $this->repo->find($id, $this->hid) ?? $this->notFound();
        if (!$this->repo->isParticipant($id, Auth::id())) {
            $this->back('/calendar/' . $id, 'Budgets gibt es nur für Teilnehmer des Termins.');
        }
        $budget = $this->budgetInput();
        if (isset($budget['error'])) {
            $this->back('/calendar/' . $id, $budget['error']);
        }
        $this->saveOwnBudget($id, $budget);
        $this->back('/calendar/' . $id, $budget ? 'Budget gespeichert.' : 'Budget entfernt.', 'success');
    }

    public function bookForm(int $id): void
    {
        [$event, $budget, $date] = $this->bookable($id);
        $this->renderBook($event, $budget, $date, [
            'amount' => money_input($budget['amount']), 'account_id' => $budget['account_id'],
            'category_id' => $budget['category_id'], 'booking_date' => min($date, date('Y-m-d')), 'note' => '',
        ]);
    }

    public function book(int $id): void
    {
        [$event, $budget, $date] = $this->bookable($id);
        $r = $this->request;
        $input = ['amount' => $r->str('amount'), 'account_id' => $r->int('account_id'), 'category_id' => $r->int('category_id'),
                  'booking_date' => $r->str('booking_date'), 'note' => trim(mb_substr($r->str('note'), 0, 120))];
        $cents = Money::parse($input['amount']);
        $bookingDate = valid_date($input['booking_date']);
        $error = match (true) {
            $cents === null || $cents <= 0 => 'Bitte einen positiven Betrag angeben.',
            $bookingDate === null          => 'Bitte ein gültiges Buchungsdatum angeben.',
            !Auth::can($input['account_id'], 'book') => 'Bitte ein Konto wählen, auf das du buchen darfst.',
            default => null,
        };
        if (!$error) {
            try {
                CalendarService::bookBudget($this->hid, Auth::id(), $budget, $event, $date, $cents, (int) $input['account_id'],
                    $bookingDate, (new CategoryRepository())->validId($input['category_id'], $this->hid), $input['note']);
                $this->redirect('/calendar/' . $id . '?date=' . $date, 'Gebucht: ' . Money::format($cents) . ' als Ausgabe.');
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
        $this->renderBook($event, $budget, $date, $input, $error);
    }

    // ---- Vorlagen ----------------------------------------------------------------------------

    public function templates(): void
    {
        $templates = $this->repo->templates($this->hid, Auth::id());
        $editId = $this->request->int('edit');
        $current = $editId ? $this->repo->template($editId, $this->hid, Auth::id()) : null;
        $this->view('calendar/templates', [
            'title'      => 'Terminvorlagen',
            'back'       => '/calendar',
            'templates'  => $templates,
            'tpl'        => $current ?? ['id' => null, 'name' => '', 'title' => '', 'location' => '', 'duration_minutes' => 60,
                                         'lead_minutes' => 0, 'trail_minutes' => 0, 'all_day' => 0, 'color' => '#0d6efd',
                                         'budget_amount' => null, 'budget_account_id' => null, 'budget_category_id' => null],
            'accounts'   => $this->bookableAccounts(),
            'categories' => (new CategoryRepository())->all($this->hid, 'expense'),
            'error'      => null,
        ]);
    }

    public function storeTemplate(): void
    {
        $data = $this->templateInput();
        if (is_string($data)) {
            $this->back('/calendar/templates', $data);
        }
        $this->repo->createTemplate($this->hid, Auth::id(), $data);
        $this->redirect('/calendar/templates', 'Vorlage „' . $data['name'] . '“ gespeichert.');
    }

    public function updateTemplate(int $id): void
    {
        $this->repo->template($id, $this->hid, Auth::id()) ?? $this->notFound();
        $data = $this->templateInput();
        if (is_string($data)) {
            $this->back('/calendar/templates?edit=' . $id, $data);
        }
        $this->repo->updateTemplate($id, $this->hid, Auth::id(), $data);
        $this->redirect('/calendar/templates', 'Vorlage gespeichert.');
    }

    public function deleteTemplate(int $id): void
    {
        $this->repo->deleteTemplate($id, $this->hid, Auth::id());
        $this->redirect('/calendar/templates', 'Vorlage gelöscht.');
    }

    // ---- intern ------------------------------------------------------------------------------

    /** @return array|string Spalten oder Fehlermeldung */
    private function templateInput(): array|string
    {
        $r = $this->request;
        $name = trim(mb_substr($r->str('name'), 0, 100));
        $duration = CalendarService::parseDuration($r->str('duration'));
        $lead = CalendarService::parseDuration($r->str('lead'));
        $trail = CalendarService::parseDuration($r->str('trail'));
        $allDay = $r->bool('all_day');
        $amountRaw = $r->str('budget_amount');
        $amount = $amountRaw === '' ? null : Money::parse($amountRaw);
        $account = $r->int('budget_account_id');
        return match (true) {
            $name === ''                         => 'Bitte einen Namen für die Vorlage angeben.',
            $duration === null || ($duration === 0 && !$allDay) => 'Bitte eine gültige Dauer angeben, z. B. „1:30“, „2 h“ oder „1 Tag“.',
            $lead === null || $trail === null    => 'Vor- bzw. Nachlauf ist ungültig, z. B. „30 min“ oder „1:15“.',
            $amountRaw !== '' && ($amount === null || $amount < 0) => 'Der Budgetbetrag ist ungültig.',
            default => [
                'name'               => $name,
                'title'              => trim(mb_substr($r->str('title'), 0, 190)) ?: null,
                'location'           => trim(mb_substr($r->str('location'), 0, 190)) ?: null,
                'duration_minutes'   => $allDay ? max(1440, (int) ceil($duration / 1440) * 1440) : $duration,
                'lead_minutes'       => $lead,
                'trail_minutes'      => $trail,
                'all_day'            => $allDay ? 1 : 0,
                'color'              => preg_match('/^#[0-9a-fA-F]{6}$/', $r->str('color')) ? strtolower($r->str('color')) : '#0d6efd',
                'budget_amount'      => $amount ? Money::toDecimal($amount) : null,
                'budget_account_id'  => $account && Auth::can($account, 'book') ? $account : null,
                'budget_category_id' => (new CategoryRepository())->validId($r->int('budget_category_id'), $this->hid),
            ],
        };
    }

    /**
     * Formular lesen: Termindaten, Teilnehmer, eigenes Budget, Fehler.
     * @return array{0:array, 1:int[], 2:?array, 3:?string}
     */
    private function validated(): array
    {
        $r = $this->request;
        $allDay = $r->bool('all_day');
        $lead = CalendarService::parseDuration($r->str('lead'));
        $trail = CalendarService::parseDuration($r->str('trail'));
        $in = [
            'title' => $r->str('title'), 'location' => $r->str('location'), 'note' => $r->str('note'), 'all_day' => $allDay,
            'start' => $r->str('start_date') . ($allDay ? '' : ' ' . $r->str('start_time')),
            'end'   => $r->str('end_date') . ($allDay || $r->str('end_date') === '' ? '' : ' ' . $r->str('end_time')),
            'lead_minutes' => $lead ?? 0, 'trail_minutes' => $trail ?? 0, 'color' => $r->str('color'),
            'recurrence' => $r->str('recurrence', 'none'), 'recurrence_interval' => $r->int('recurrence_interval', 1),
            'recurrence_until' => $r->str('recurrence_until'), 'template_id' => $r->int('template_id'),
        ];
        $participants = array_map('intval', $r->arr('participants'));
        $budget = $this->budgetInput();
        $error = null;
        if ($lead === null || $trail === null) {
            $error = 'Vor- bzw. Nachlauf ist ungültig, z. B. „30 min“, „1:15“ oder „2 h“.';
        } elseif (isset($budget['error'])) {
            $error = $budget['error'];
        }
        try {
            $data = CalendarService::buildEvent($in);
        } catch (\InvalidArgumentException $e) {
            $error ??= $e->getMessage();
            // Formular mit den Eingaben erneut zeigen
            $start = valid_date($r->str('start_date'), date('Y-m-d')) . ' ' . (preg_match('/^\d{1,2}:\d{2}$/', $r->str('start_time')) ? $r->str('start_time') : '10:00');
            $end = valid_date($r->str('end_date'), substr($start, 0, 10)) . ' ' . (preg_match('/^\d{1,2}:\d{2}$/', $r->str('end_time')) ? $r->str('end_time') : '11:00');
            $data = ['title' => $in['title'], 'location' => $in['location'], 'note' => $in['note'], 'all_day' => $allDay ? 1 : 0,
                     'start_at' => $start . ':00', 'end_at' => $allDay ? date('Y-m-d H:i:s', strtotime(substr($end, 0, 10) . ' +1 day')) : $end . ':00',
                     'lead_minutes' => $lead ?? 0, 'trail_minutes' => $trail ?? 0, 'color' => $in['color'] ?: '#0d6efd',
                     'template_id' => $in['template_id'], 'recurrence' => $in['recurrence'], 'recurrence_interval' => $in['recurrence_interval'],
                     'recurrence_until' => $in['recurrence_until'] ?: null];
        }
        if (isset($data['template_id']) && $data['template_id'] && !$this->repo->template((int) $data['template_id'], $this->hid, Auth::id())) {
            $data['template_id'] = null;
        }
        return [$data, $participants, isset($budget['error']) ? null : $budget, $error];
    }

    /** Budgetfelder: null = kein Budget, ['error' => …] bei Fehlern */
    private function budgetInput(): ?array
    {
        $r = $this->request;
        $raw = $r->str('budget_amount');
        if ($raw === '') {
            return null;
        }
        $cents = Money::parse($raw);
        if ($cents === null || $cents < 0) {
            return ['error' => 'Der Budgetbetrag ist ungültig.'];
        }
        if ($cents === 0) {
            return null;
        }
        $account = $r->int('budget_account_id');
        return [
            'amount'      => Money::toDecimal($cents),
            'account_id'  => $account && Auth::can($account, 'book') ? $account : null,
            'category_id' => (new CategoryRepository())->validId($r->int('budget_category_id'), $this->hid),
            'note'        => trim(mb_substr($r->str('budget_note'), 0, 255)) ?: null,
        ];
    }

    private function saveOwnBudget(int $eventId, ?array $budget): void
    {
        if ($budget) {
            $this->repo->saveBudget($eventId, Auth::id(), $budget['amount'], $budget['account_id'], $budget['category_id'], $budget['note']);
            return;
        }
        $existing = $this->repo->budget($eventId, Auth::id());
        // Ein Budget mit Buchungen bleibt erhalten (sonst ginge die Verknüpfung verloren)
        if ($existing && !($this->repo->bookingsFor([(int) $existing['id']])[(int) $existing['id']] ?? [])) {
            $this->repo->deleteBudget($eventId, Auth::id());
        }
    }

    private function editable(int $id): array
    {
        $event = $this->repo->find($id, $this->hid) ?? $this->notFound();
        if (!CalendarService::canEdit($event, Auth::id(), Auth::isAdmin())) {
            $this->redirect('/calendar/' . $id, 'Nur wer den Termin angelegt hat, kann ihn ändern oder löschen.', 'danger');
        }
        return $event;
    }

    /** @return array{0:array, 1:array, 2:string} Termin, eigenes Budget, Vorkommen */
    private function bookable(int $id): array
    {
        $event = $this->repo->find($id, $this->hid) ?? $this->notFound();
        $budget = $this->repo->budget($id, Auth::id());
        if (!$budget) {
            $this->redirect('/calendar/' . $id, 'Für diesen Termin hast du kein Budget.', 'danger');
        }
        $date = valid_date($this->request->str('date'));
        $exceptions = $this->repo->exceptionsFor([$id])[$id] ?? [];
        if ($date === null || !CalendarService::occurrences($event, $date, $date, $exceptions)) {
            $this->redirect('/calendar/' . $id, 'Diesen Termin gibt es an dem Tag nicht.', 'danger');
        }
        if ($this->repo->booking((int) $budget['id'], $date)) {
            $this->redirect('/calendar/' . $id . '?date=' . $date, 'Das Budget ist für diesen Termin schon gebucht.', 'warning');
        }
        return [$event, $budget, $date];
    }

    private function bookableAccounts(): array
    {
        return (new AccountRepository())->options($this->hid, Auth::accountIds('book'));
    }

    private function renderBook(array $event, array $budget, string $date, array $input, ?string $error = null): void
    {
        $this->view('calendar/book', [
            'title'      => 'Budget buchen',
            'back'       => '/calendar/' . $event['id'] . '?date=' . $date,
            'event'      => $event,
            'budget'     => $budget,
            'date'       => $date,
            'input'      => $input,
            'accounts'   => $this->bookableAccounts(),
            'categories' => (new CategoryRepository())->all($this->hid, 'expense'),
            'error'      => $error,
        ]);
    }

    private function renderForm(array $event, array $participants, ?array $budget, string $title, ?string $error = null): void
    {
        $accounts = $this->bookableAccounts();
        $defaultAccount = (new UserPreferenceRepository())->account(Auth::id(), 'account_calendar', array_map(fn ($a) => (int) $a['id'], $accounts));
        $this->view('calendar/form', [
            'title'          => $title,
            'back'           => !empty($event['id']) ? '/calendar/' . $event['id'] : '/calendar',
            'event'          => $event,
            'participants'   => $participants,
            'budget'         => $budget,
            'members'        => $this->repo->members($this->hid),
            'templates'      => $this->repo->templates($this->hid, Auth::id()),
            'accounts'       => $accounts,
            'defaultAccount' => $defaultAccount,
            'categories'     => (new CategoryRepository())->all($this->hid, 'expense'),
            'error'          => $error,
            'scripts'        => ['js/calendar.js'],
        ]);
    }
}
