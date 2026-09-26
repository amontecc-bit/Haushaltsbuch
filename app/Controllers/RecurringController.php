<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\RecurringRepository;
use App\Services\RecurrenceService;

final class RecurringController extends Controller
{
    /** Felder, die beim Bearbeiten auf den Gegeneintrag übertragen werden (Betrag mit umgekehrtem Vorzeichen) */
    private const COUNTER_SYNC = ['payee', 'purpose', 'interval', 'day_of_month', 'start_date', 'end_date', 'active'];

    public function index(): void
    {
        $r = $this->request;
        $visible = Auth::accountIds('view');
        $filters = [
            'account_ids' => array_values(array_intersect(array_map('intval', $r->arr('account_ids')), $visible)),
            'category_id' => $r->int('category_id'),
            'type'        => in_array($r->str('type'), ['income', 'expense', 'transfer'], true) ? $r->str('type') : '',
            'status'      => in_array($r->str('status'), ['active', 'paused', 'auto', 'forecast'], true) ? $r->str('status') : '',
            'q'           => mb_substr($r->str('q'), 0, 100),
        ];
        $categories = (new CategoryRepository())->all($this->hid);
        $catIds = [];
        if ($filters['category_id']) {
            $catIds[] = $filters['category_id'];
            foreach ($categories as $c) {
                if ((int) $c['parent_id'] === $filters['category_id']) {
                    $catIds[] = (int) $c['id'];
                }
            }
        }

        $rows = array_values(array_filter(
            (new RecurringRepository())->all($this->hid, $visible),
            fn ($t) => $this->matchesFilter($t, $filters, $catIds)
        ));
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        foreach ($rows as &$t) {
            $t['next'] = $t['active'] ? RecurrenceService::nextOccurrence($t, $tomorrow) : null;
        }
        unset($t);
        $totals = RecurrenceService::monthlyTotals($rows, $filters['account_ids'], $today);

        $actions = '<a href="' . e(url('/recurring/new')) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Fixkosten / Dauerauftrag</a>';
        $this->view('recurring/index', [
            'title'      => 'Fixkosten & Daueraufträge',
            'rows'       => $rows,
            'income'     => $totals['income'],
            'expense'    => $totals['expense'],
            'filters'    => $filters,
            'accounts'   => (new AccountRepository())->options($this->hid, $visible),
            'categories' => $categories,
            'canBook'    => Auth::accountIds('book'),
            'actions'    => $actions,
        ]);
    }

    private function matchesFilter(array $t, array $f, array $catIds): bool
    {
        if ($f['account_ids'] && !in_array((int) $t['account_id'], $f['account_ids'], true)
            && !in_array((int) $t['to_account_id'], $f['account_ids'], true)) {
            return false;
        }
        if ($catIds && !in_array((int) $t['category_id'], $catIds, true)) {
            return false;
        }
        $isTransfer = (bool) $t['to_account_id'];
        $ok = match ($f['type']) {
            'transfer' => $isTransfer,
            'income'   => !$isTransfer && (float) $t['amount'] > 0,
            'expense'  => !$isTransfer && (float) $t['amount'] < 0,
            default    => true,
        } && match ($f['status']) {
            'active'   => (bool) $t['active'],
            'paused'   => !$t['active'],
            'auto'     => $t['active'] && $t['auto_book'],
            'forecast' => $t['active'] && !$t['auto_book'],
            default    => true,
        };
        if (!$ok) {
            return false;
        }
        if ($f['q'] !== '') {
            $hay = mb_strtolower($t['payee'] . ' ' . $t['purpose'] . ' ' . $t['category_name']);
            return str_contains($hay, mb_strtolower($f['q']));
        }
        return true;
    }

    public function create(): void
    {
        $tpl = [
            'id' => null, 'kind' => 'expense', 'account_id' => null, 'to_account_id' => null, 'category_id' => null, 'amount' => '',
            'payee' => '', 'purpose' => '', 'interval' => 'monthly', 'start_date' => date('Y-m-d'), 'end_date' => '',
            'auto_book' => 1, 'active' => 1, 'last_booked_date' => null, 'counterpart_id' => null,
        ];
        $this->renderForm($tpl, 'Neue Fixkosten');
    }

    public function store(): void
    {
        [$data, $error] = $this->validated();
        $counter = $error ? null : $this->validatedCounter($data, null, $error);
        if ($error) {
            $this->renderForm($this->formFromData($data), 'Neue Fixkosten', $error);
            return;
        }
        $repo = new RecurringRepository();
        $data['created_by'] = Auth::id();
        // Vergangene Termine nur buchen, wenn gewünscht
        if (!$this->request->bool('book_past')) {
            $data['last_booked_date'] = self::bookFromToday($data['start_date']);
        }
        $id = $repo->transaction(function () use ($repo, $data, $counter) {
            $id = $repo->create($this->hid, $data);
            if ($counter) {
                $this->createCounterpart($repo, $id, $data, $counter);
            }
            return $id;
        });
        $n = RecurrenceService::materializeDue($this->hid);
        RecurrenceService::linkExisting($this->hid, $id);
        $this->redirect('/recurring', 'Gespeichert' . ($counter ? ' (mit Gegeneintrag)' : '') . ($n ? " – $n fällige Buchungen wurden angelegt." : '.'));
    }

    public function edit(int $id): void
    {
        $tpl = (new RecurringRepository())->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tpl['account_id'], 'view');
        $this->renderForm($this->formFromData($tpl), 'Fixkosten bearbeiten');
    }

    public function update(int $id): void
    {
        $repo = new RecurringRepository();
        $tpl = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tpl['account_id'], 'book');
        [$data, $error] = $this->validated();
        $counter = $error ? null : $this->validatedCounter($data, $tpl, $error);
        if ($error) {
            $this->renderForm($this->formFromData(['id' => $id, 'counterpart_id' => $tpl['counterpart_id'], 'last_booked_date' => $tpl['last_booked_date']] + $data), 'Fixkosten bearbeiten', $error);
            return;
        }
        // Wird der Start nach vorn verlegt, keine alten Termine nachbuchen
        if ($tpl['last_booked_date'] && $data['start_date'] > $tpl['last_booked_date']) {
            $data['last_booked_date'] = null;
        }
        $data += self::resumeFields($tpl, $data);
        $repo->transaction(function () use ($repo, $id, $tpl, $data, $counter) {
            $repo->update($id, $this->hid, $data);
            if ($counter) {
                $this->createCounterpart($repo, $id, array_replace($data, ['last_booked_date' => self::bookFromToday($data['start_date'])]), $counter);
            } elseif ($tpl['counterpart_id'] && $this->request->bool('counter_sync')) {
                $other = $repo->find((int) $tpl['counterpart_id'], $this->hid);
                if ($other && Auth::can((int) $other['account_id'], 'book')) {
                    $sync = array_intersect_key($data, array_flip(self::COUNTER_SYNC));
                    $sync['amount'] = Money::toDecimal(-Money::toCents($data['amount']));
                    $repo->update((int) $other['id'], $this->hid, $sync + self::resumeFields($other, $sync));
                }
            }
        });
        RecurrenceService::materializeDue($this->hid);
        RecurrenceService::linkExisting($this->hid, $id);
        $this->redirect('/recurring', 'Gespeichert. Änderungen gelten für künftige Buchungen.');
    }

    public function delete(int $id): void
    {
        $repo = new RecurringRepository();
        $tpl = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tpl['account_id'], 'book');
        $repo->delete($id, $this->hid);
        $this->redirect('/recurring', 'Gelöscht. Bereits erzeugte Buchungen bleiben erhalten.'
            . ($tpl['counterpart_id'] ? ' Der Gegeneintrag bleibt bestehen.' : ''));
    }

    /** Mehrere Vorlagen auf einmal: nur Prognose / automatisch buchen / pausieren / aktivieren / Konto ändern / löschen */
    public function bulk(): void
    {
        $ids = array_map('intval', $this->request->arr('ids'));
        $action = $this->request->str('action');
        $target = (int) $this->request->int('account_id');
        if ($action === 'move' && !Auth::can($target, 'book')) {
            $this->back('/recurring', 'Bitte ein Konto wählen, auf das du buchen darfst.');
        }
        $repo = new RecurringRepository();
        $n = 0;
        foreach ($ids as $id) {
            $tpl = $repo->find($id, $this->hid);
            if (!$tpl || !Auth::can((int) $tpl['account_id'], 'book')) {
                continue;
            }
            $data = match ($action) {
                'forecast' => ['auto_book' => 0],
                'auto'     => ['auto_book' => 1],
                'pause'    => ['active' => 0],
                'activate' => ['active' => 1],
                'move'     => (int) $tpl['to_account_id'] === $target ? null : ['account_id' => $target],
                default    => null,
            };
            if ($action === 'delete') {
                $repo->delete($id, $this->hid);
            } elseif ($data === null) {
                continue;
            } else {
                $repo->update($id, $this->hid, $data + self::resumeFields($tpl, $data));
            }
            $n++;
        }
        if ($action === 'move') {
            RecurrenceService::linkExisting($this->hid);
        }
        $messages = [
            'forecast' => '%d Einträge erscheinen jetzt nur noch in der Prognose.',
            'auto'     => '%d Einträge werden ab heute automatisch gebucht.',
            'pause'    => '%d Einträge pausiert.',
            'activate' => '%d Einträge aktiviert.',
            'move'     => '%d Einträge auf das neue Konto verschoben. Bereits erzeugte Buchungen bleiben unverändert.',
            'delete'   => '%d Einträge gelöscht. Bereits erzeugte Buchungen bleiben erhalten.',
        ];
        $this->back('/recurring', sprintf($messages[$action] ?? '%d Einträge geändert.', $n), 'success');
    }

    /**
     * Wird eine Vorlage (wieder) automatisch gebucht, sollen Termine aus der Zeit „nur Prognose“ oder „pausiert“
     * nicht nachträglich gebucht werden – sonst entstünden Doppelungen zu importierten Buchungen.
     */
    private static function resumeFields(array $old, array $new): array
    {
        $wasBooking = $old['active'] && $old['auto_book'];
        $willBook = ($new['active'] ?? $old['active']) && ($new['auto_book'] ?? $old['auto_book']);
        if ($wasBooking || !$willBook) {
            return [];
        }
        $from = self::bookFromToday($new['start_date'] ?? $old['start_date']);
        return $from && $from > (string) $old['last_booked_date'] ? ['last_booked_date' => $from] : [];
    }

    /** last_booked_date so setzen, dass erst ab heute gebucht wird (keine vergangenen Termine) */
    private static function bookFromToday(string $start): ?string
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        return $start > $yesterday ? null : $yesterday;
    }

    private function validated(): array
    {
        $r = $this->request;
        $kind = in_array($r->str('kind'), ['expense', 'income', 'transfer'], true) ? $r->str('kind') : 'expense';
        $cents = abs((int) Money::parse($r->str('amount')));
        $interval = array_key_exists($r->str('interval'), RecurrenceService::STEPS) ? $r->str('interval') : 'monthly';
        $start = valid_date($r->str('start_date'), date('Y-m-d'));
        $data = [
            'account_id'    => (int) $r->int('account_id'),
            'to_account_id' => $kind === 'transfer' ? (int) $r->int('to_account_id') : null,
            'category_id'   => $kind === 'transfer' ? null : (new CategoryRepository())->validId($r->int('category_id'), $this->hid),
            'amount'        => Money::toDecimal($kind === 'income' ? $cents : -$cents),
            'payee'         => mb_substr($r->str('payee'), 0, 190) ?: null,
            'purpose'       => mb_substr($r->str('purpose'), 0, 255) ?: null,
            'interval'      => $interval,
            'day_of_month'  => (int) substr($start, 8, 2),
            'start_date'    => $start,
            'end_date'      => valid_date($r->str('end_date')),
            'auto_book'     => $r->bool('auto_book') ? 1 : 0,
            'active'        => $r->bool('active') ? 1 : 0,
        ];
        $error = null;
        if ($cents === 0) {
            $error = 'Bitte einen Betrag eingeben.';
        } elseif (!Auth::can($data['account_id'], 'book')) {
            $error = 'Auf dieses Konto darfst du nicht buchen.';
        } elseif ($kind === 'transfer' && (!Auth::can($data['to_account_id'], 'book') || $data['to_account_id'] === $data['account_id'])) {
            $error = 'Bitte ein anderes Zielkonto wählen.';
        } elseif ($data['end_date'] && $data['end_date'] < $start) {
            $error = 'Das Enddatum liegt vor dem Start.';
        }
        return [$data, $error];
    }

    /**
     * Gewünschter neuer Gegeneintrag (Konto + Kategorie) oder null.
     * @return array{account_id:int, category_id:?int}|null
     */
    private function validatedCounter(array $data, ?array $tpl, ?string &$error): ?array
    {
        $r = $this->request;
        if (!$r->bool('counter') || $data['to_account_id'] || !empty($tpl['counterpart_id'])) {
            return null;
        }
        $account = (int) $r->int('counter_account_id');
        if (!Auth::can($account, 'book') || $account === $data['account_id']) {
            $error = 'Bitte für den Gegeneintrag ein anderes Konto wählen, auf das du buchen darfst.';
            return null;
        }
        return ['account_id' => $account, 'category_id' => (new CategoryRepository())->validId($r->int('counter_category_id'), $this->hid)];
    }

    private function createCounterpart(RecurringRepository $repo, int $id, array $data, array $counter): void
    {
        $other = $repo->create($this->hid, [
            'account_id'       => $counter['account_id'],
            'category_id'      => $counter['category_id'],
            'amount'           => Money::toDecimal(-Money::toCents($data['amount'])),
            'counterpart_id'   => $id,
            'created_by'       => Auth::id(),
        ] + array_intersect_key($data, array_flip([...self::COUNTER_SYNC, 'auto_book', 'last_booked_date'])));
        $repo->update($id, $this->hid, ['counterpart_id' => $other]);
    }

    private function formFromData(array $d): array
    {
        $cents = Money::toCents($d['amount']);
        return [
            'id' => $d['id'] ?? null,
            'kind' => $d['to_account_id'] ? 'transfer' : ($cents > 0 ? 'income' : 'expense'),
            'amount' => $cents ? money_input(abs($cents) / 100) : '',
            'counterpart_id' => $d['counterpart_id'] ?? null,
        ] + $d;
    }

    private function renderForm(array $tpl, string $title, ?string $error = null): void
    {
        $accounts = (new AccountRepository())->options($this->hid, Auth::accountIds('book'));
        if (!$accounts) {
            $this->redirect('/accounts', 'Es gibt noch kein Konto, auf das du buchen darfst.', 'warning');
        }
        $counterpart = null;
        if (!empty($tpl['counterpart_id'])) {
            $counterpart = (new RecurringRepository())->find((int) $tpl['counterpart_id'], $this->hid);
            if ($counterpart) {
                $names = array_column((new AccountRepository())->options($this->hid, Auth::accountIds('view')), 'name', 'id');
                $counterpart['account_name'] = $names[$counterpart['account_id']] ?? '?';
            }
        }
        $this->view('recurring/form', [
            'title' => $title, 'back' => '/recurring', 'tpl' => $tpl, 'error' => $error, 'counterpart' => $counterpart,
            'accounts' => $accounts, 'categories' => (new CategoryRepository())->all($this->hid),
        ]);
    }
}
