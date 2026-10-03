<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\RecurringRepository;
use App\Repositories\RuleRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserPreferenceRepository;
use App\Repositories\UserRepository;
use App\Services\CategorizationService;
use App\Services\RecurrenceService;
use App\Services\TransactionService;

final class TransactionController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): void
    {
        $r = $this->request;
        $ids = Auth::accountIds('view');
        $accountIds = array_map('intval', $r->arr('account_ids'));
        if ($r->int('account_id')) {
            $accountIds[] = (int) $r->int('account_id'); // Links aus Übersicht/Konten/Import
        }
        $filters = [
            'account_ids' => array_values(array_unique(array_intersect($accountIds, $ids))),
            'category_id' => $r->str('category_id') === 'none' ? 'none' : $r->int('category_id'),
            'q'           => mb_substr($r->str('q'), 0, 100),
            'type'        => in_array($r->str('type'), ['income', 'expense', 'transfer', 'fixed'], true) ? $r->str('type') : '',
            'user_id'     => $r->int('user_id'),
        ];
        [$filters['period'], $filters['from'], $filters['to']] = $this->period('all', true);
        $page = max(1, (int) $r->int('page', 1));
        $repo = new TransactionRepository();
        $rows = $repo->search($this->hid, $ids, $filters, self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE);
        $hasMore = count($rows) > self::PER_PAGE;
        $rows = array_slice($rows, 0, self::PER_PAGE);

        $actions = '<a href="' . e(url('/transactions/new')) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Buchung</a>'
            . '<a href="' . e(url('/import')) . '" class="btn btn-outline-secondary"><i class="bi bi-upload"></i> CSV-Import</a>';

        $this->view('transactions/index', [
            'title'      => 'Buchungen',
            'actions'    => $actions,
            'rows'       => $rows,
            'filters'    => $filters,
            'page'       => $page,
            'hasMore'    => $hasMore,
            'summary'    => $repo->summary($this->hid, $ids, $filters),
            'accounts'   => (new AccountRepository())->options($this->hid, $ids),
            'categories' => (new CategoryRepository())->all($this->hid),
            'users'      => (new UserRepository())->all($this->hid),
            'canBook'    => Auth::accountIds('book'),
        ]);
    }

    public function create(): void
    {
        $type = in_array($this->request->str('type'), ['expense', 'income', 'transfer'], true) ? $this->request->str('type') : 'expense';
        $accounts = (new AccountRepository())->options($this->hid, Auth::accountIds('book'));
        if (!$accounts) {
            $this->redirect('/accounts', 'Es gibt noch kein Konto, auf das du buchen darfst.', 'warning');
        }
        $ids = array_map('intval', array_column($accounts, 'id'));
        $prefs = new UserPreferenceRepository();
        $from = $this->request->int('account_id') ?? $prefs->account(Auth::id(), 'account_booking', $ids) ?? $ids[0];
        $to = $prefs->account(Auth::id(), 'account_transfer', $ids);
        if ($to === null || $to === $from) {
            // sonst erstes anderes Konto – Von und Auf sollen nicht gleich vorbelegt sein
            $to = array_values(array_diff($ids, [$from]))[0] ?? null;
        }
        $tx = [
            'id' => null, 'kind' => $type, 'amount' => '', 'account_id' => $from,
            'to_account_id' => $to, 'category_id' => null, 'booking_date' => date('Y-m-d'), 'payee' => '', 'purpose' => '', 'note' => '',
        ];
        $this->renderForm($tx, 'Neue Buchung');
    }

    public function store(): void
    {
        [$data, $error] = $this->validated();
        if ($error) {
            $this->renderForm($data, 'Neue Buchung', $error);
            return;
        }
        $service = new TransactionService();
        $cents = $data['cents'];
        $common = [
            'booking_date' => $data['booking_date'],
            'payee'        => $data['payee'] ?: null,
            'purpose'      => $data['purpose'] ?: null,
            'note'         => $data['note'] ?: null,
            'created_by'   => Auth::id(),
        ];

        if ($this->request->bool('repeat')) {
            $interval = $this->request->str('interval');
            $interval = array_key_exists($interval, RecurrenceService::STEPS) ? $interval : 'monthly';
            (new RecurringRepository())->create($this->hid, [
                'account_id'    => $data['account_id'],
                'to_account_id' => $data['kind'] === 'transfer' ? $data['to_account_id'] : null,
                'category_id'   => $data['kind'] === 'transfer' ? null : $data['category_id'],
                'amount'        => Money::toDecimal($data['kind'] === 'income' ? $cents : -$cents),
                'payee'         => $common['payee'],
                'purpose'       => $common['purpose'],
                'interval'      => $interval,
                'day_of_month'  => (int) substr($data['booking_date'], 8, 2),
                'start_date'    => $data['booking_date'],
                'end_date'      => valid_date($this->request->str('end_date')),
                'created_by'    => Auth::id(),
            ]);
            $n = RecurrenceService::materializeDue($this->hid);
            $this->maybeCreateRule($data);
            $this->redirect('/recurring', 'Wiederkehrende Buchung angelegt' . ($n ? " ($n bereits fällig und gebucht)." : '.'));
        }

        if ($data['kind'] === 'transfer') {
            $service->createTransfer($this->hid, $data['account_id'], $data['to_account_id'], $cents, $data['booking_date'], $common);
        } else {
            $service->create($this->hid, $common + [
                'account_id'  => $data['account_id'],
                'amount'      => Money::toDecimal($data['kind'] === 'income' ? $cents : -$cents),
                'category_id' => $data['category_id'],
            ]);
        }
        $this->maybeCreateRule($data);

        if ($this->request->bool('another')) {
            $this->redirect('/transactions/new?type=' . $data['kind'] . '&account_id=' . $data['account_id'], 'Gespeichert. Nächste Buchung:');
        }
        $this->redirect('/transactions', 'Buchung gespeichert.');
    }

    public function edit(int $id): void
    {
        $repo = new TransactionRepository();
        $tx = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tx['account_id'], 'view');
        $partner = $repo->transferPartner($tx);
        $cents = Money::toCents($tx['amount']);
        $form = [
            'id' => $id, 'amount' => money_input(abs($cents) / 100), 'booking_date' => $tx['booking_date'],
            'payee' => $tx['payee'], 'purpose' => $tx['purpose'], 'note' => $tx['note'], 'category_id' => $tx['category_id'],
            'source' => $tx['source'], 'recurring_id' => $tx['recurring_id'],
            'recurring_payee' => $tx['recurring_id'] ? ((new RecurringRepository())->find((int) $tx['recurring_id'], $this->hid)['payee'] ?? null) : null,
        ];
        if ($partner) {
            $out = $cents < 0 ? $tx : $partner;
            $in = $cents < 0 ? $partner : $tx;
            $form += ['kind' => 'transfer', 'account_id' => $out['account_id'], 'to_account_id' => $in['account_id']];
        } else {
            $form += ['kind' => $cents >= 0 ? 'income' : 'expense', 'account_id' => $tx['account_id'], 'to_account_id' => null];
        }
        $this->renderForm($form, 'Buchung bearbeiten');
    }

    public function update(int $id): void
    {
        $repo = new TransactionRepository();
        $tx = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tx['account_id'], 'book');
        [$data, $error] = $this->validated();
        $data['id'] = $id;
        if ($error) {
            $this->renderForm($data, 'Buchung bearbeiten', $error);
            return;
        }
        $common = [
            'booking_date' => $data['booking_date'],
            'payee'        => $data['payee'] ?: null,
            'purpose'      => $data['purpose'] ?: null,
            'note'         => $data['note'] ?: null,
        ];
        $joined = null;
        $repo->transaction(function () use ($repo, $tx, $data, $common, $id, &$joined) {
            $wasTransfer = (bool) $tx['transfer_group'];
            if ($wasTransfer || $data['kind'] === 'transfer') {
                $service = new TransactionService($repo);
                $amount = Money::toDecimal($data['kind'] === 'income' ? $data['cents'] : -$data['cents']);
                // Wird eine Buchung zur Umbuchung und steht die Gegenbuchung schon auf dem anderen Konto (z. B. aus dessen
                // Kontoauszug), wird diese übernommen statt eine zweite anzulegen
                if (!$wasTransfer && $data['kind'] === 'transfer' && in_array((int) $tx['account_id'], [$data['account_id'], $data['to_account_id']], true)) {
                    $isOut = (int) $tx['account_id'] === $data['account_id'];
                    $other = $isOut ? $data['to_account_id'] : $data['account_id'];
                    $joined = $repo->transferCounterparts($this->hid, [$other], Money::toDecimal($isOut ? $data['cents'] : -$data['cents']), $data['booking_date'])[0] ?? null;
                    if ($joined) {
                        $repo->update($id, $this->hid, $common + ['amount' => Money::toDecimal($isOut ? -$data['cents'] : $data['cents'])]);
                        $service->joinAsTransfer($this->hid, $id, (int) $joined['id']);
                        return;
                    }
                }
                // Umbuchungen werden neu aufgebaut (beide Seiten). Import-Kennung, Import-Lauf und Fixkosten-Zuordnung
                // gehören zum jeweiligen Konto und bleiben dort – sonst erkennt der nächste CSV-Import die Seite nicht mehr.
                $legs = [$tx];
                if ($wasTransfer) {
                    $legs[] = $repo->transferPartner($tx);
                    $repo->deleteTransferGroup($tx['transfer_group'], $this->hid);
                } else {
                    $repo->delete($id, $this->hid);
                }
                $keep = function (int $accountId) use ($legs): array {
                    foreach (array_filter($legs) as $leg) {
                        if ((int) $leg['account_id'] === $accountId) {
                            return ['import_hash' => $leg['import_hash'], 'import_batch_id' => $leg['import_batch_id'], 'recurring_id' => $leg['recurring_id']];
                        }
                    }
                    return [];
                };
                $extra = $common + ['created_by' => $tx['created_by'], 'source' => $tx['source']];
                if ($data['kind'] === 'transfer') {
                    $service->createTransfer($this->hid, $data['account_id'], $data['to_account_id'], $data['cents'], $data['booking_date'], $extra,
                        $keep($data['account_id']), $keep($data['to_account_id']));
                } else {
                    $repo->create($this->hid, $keep($data['account_id']) + $extra + [
                        'account_id' => $data['account_id'], 'category_id' => $data['category_id'], 'amount' => $amount,
                    ]);
                }
                return;
            }
            $repo->update($id, $this->hid, $common + [
                'account_id'  => $data['account_id'],
                'category_id' => $data['category_id'],
                'amount'      => Money::toDecimal($data['kind'] === 'income' ? $data['cents'] : -$data['cents']),
            ]);
        });
        $this->maybeCreateRule($data);
        $return = $this->request->str('return');
        $msg = $joined ? 'Umbuchung gespeichert – mit der vorhandenen Gegenbuchung auf „' . $joined['account_name'] . '“ vom ' . date_de($joined['booking_date']) . ' verknüpft.'
            : 'Buchung gespeichert.';
        $this->redirect(str_starts_with($return, '/transactions') ? $return : '/transactions', $msg);
    }

    public function delete(int $id): void
    {
        $repo = new TransactionRepository();
        $tx = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tx['account_id'], 'book');
        if ($tx['transfer_group']) {
            $repo->deleteTransferGroup($tx['transfer_group'], $this->hid);
        } else {
            $repo->delete($id, $this->hid);
        }
        $this->redirect('/transactions', 'Buchung gelöscht.');
    }

    /** AJAX: Kategorie einer Buchung setzen */
    public function setCategory(int $id): void
    {
        $repo = new TransactionRepository();
        $tx = $repo->find($id, $this->hid);
        if (!$tx || !Auth::can((int) $tx['account_id'], 'book')) {
            $this->json(['error' => 'Kein Zugriff'], 403);
        }
        $cat = (new CategoryRepository())->validId($this->request->int('category_id'), $this->hid);
        $repo->update($id, $this->hid, ['category_id' => $cat]);
        $this->json(['ok' => true]);
    }

    /** Mehrere Buchungen auf einmal kategorisieren, einem anderen Konto zuweisen, als Fixkosten übernehmen oder löschen */
    public function bulk(): void
    {
        $ids = array_map('intval', $this->request->arr('ids'));
        $repo = new TransactionRepository();
        $action = $this->request->str('action');
        if ($action === 'recurring') {
            $txs = array_filter(array_map(fn ($id) => $repo->find($id, $this->hid), $ids));
            [$n, $linked] = $this->createTemplates($txs, $this->request->str('interval'), $this->request->bool('auto_book'));
            $this->back('/transactions', "$n Fixkosten angelegt, $linked Buchungen zugeordnet.", 'success');
        }
        $cat = (new CategoryRepository())->validId($this->request->int('category_id'), $this->hid);
        $target = (int) $this->request->int('account_id');
        if ($action === 'move' && !Auth::can($target, 'book')) {
            $this->back('/transactions', 'Bitte ein Konto wählen, auf das du buchen darfst.');
        }
        $n = 0;
        foreach ($ids as $id) {
            $tx = $repo->find($id, $this->hid);
            if (!$tx || !Auth::can((int) $tx['account_id'], 'book')) {
                continue;
            }
            if ($action === 'delete') {
                $tx['transfer_group'] ? $repo->deleteTransferGroup($tx['transfer_group'], $this->hid) : $repo->delete($id, $this->hid);
            } elseif ($action === 'move') {
                // Bei Umbuchungen nur diese Seite verschieben – nie auf das Konto der Gegenseite
                $partner = $repo->transferPartner($tx);
                if ((int) $tx['account_id'] === $target || ($partner && (int) $partner['account_id'] === $target)) {
                    continue;
                }
                $repo->moveToAccount($id, $this->hid, $target);
            } elseif (!$tx['transfer_group']) {
                $repo->update($id, $this->hid, ['category_id' => $cat]);
            } else {
                continue;
            }
            $n++;
        }
        $messages = ['delete' => '%d Buchungen gelöscht.', 'move' => '%d Buchungen dem neuen Konto zugewiesen.'];
        $this->back('/transactions', sprintf($messages[$action] ?? '%d Buchungen kategorisiert.', $n), 'success');
    }

    /** Eine Buchung als Fixkosten übernehmen (aus dem Bearbeiten-Formular) */
    public function makeRecurring(int $id): void
    {
        $repo = new TransactionRepository();
        $tx = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tx['account_id'], 'book');
        [$n] = $this->createTemplates([$tx], $this->request->str('interval'), $this->request->bool('auto_book'));
        $tpl = $repo->find($id, $this->hid)['recurring_id'] ?? null;
        if (!$n || !$tpl) {
            $this->redirect("/transactions/$id/edit", 'Die Buchung gehört bereits zu Fixkosten.', 'warning');
        }
        $this->redirect("/recurring/$tpl/edit", 'Als Fixkosten übernommen – bitte kurz prüfen.');
    }

    /**
     * Legt aus Buchungen wiederkehrende Vorlagen an. Gleiche Zahlungen (Konto, Gegenkonto, Empfänger, Betrag)
     * ergeben eine Vorlage; sie startet mit der frühesten Buchung und gilt bis zur letzten als gebucht.
     * Die Buchungen (bei Umbuchungen beide Seiten) werden der Vorlage zugeordnet.
     *
     * @return array{0:int, 1:int} angelegte Vorlagen, zugeordnete Buchungen
     */
    private function createTemplates(array $txs, string $interval, bool $autoBook): array
    {
        $interval = array_key_exists($interval, RecurrenceService::STEPS) ? $interval : 'monthly';
        $repo = new TransactionRepository();
        $groups = [];
        foreach ($txs as $tx) {
            if ($tx['recurring_id'] || !Auth::can((int) $tx['account_id'], 'book')) {
                continue;
            }
            $partner = $repo->transferPartner($tx);
            if ($tx['transfer_group'] && (!$partner || !Auth::can((int) $partner['account_id'], 'book'))) {
                continue;
            }
            // Umbuchung: Vorlage gehört zur abgebenden Seite
            [$out, $in] = $partner && (float) $tx['amount'] > 0 ? [$partner, $tx] : [$tx, $partner];
            $key = implode('|', [$out['account_id'], $in['account_id'] ?? '', mb_strtolower((string) $out['payee']), $out['amount']]);
            $groups[$key]['out'] ??= $out;
            $groups[$key]['to'] = $in ? (int) $in['account_id'] : null;
            $groups[$key]['dates'][] = $tx['booking_date'];
            $groups[$key]['ids'][] = (int) $tx['id'];
            if ($partner) {
                $groups[$key]['ids'][] = (int) $partner['id'];
            }
        }
        $templates = new RecurringRepository();
        $created = $linked = 0;
        foreach ($groups as $g) {
            sort($g['dates']);
            $last = end($g['dates']);
            $out = $g['out'];
            $id = $templates->create($this->hid, [
                'account_id'       => (int) $out['account_id'],
                'to_account_id'    => $g['to'],
                'category_id'      => $g['to'] ? null : $out['category_id'],
                'amount'           => $out['amount'],
                'payee'            => mb_substr((string) $out['payee'], 0, 190) ?: null,
                'purpose'          => mb_substr((string) $out['purpose'], 0, 255) ?: null,
                'interval'         => $interval,
                'day_of_month'     => (int) substr($last, 8, 2),
                'start_date'       => $g['dates'][0],
                'last_booked_date' => $last,
                'auto_book'        => $autoBook ? 1 : 0,
                'active'           => 1,
                'created_by'       => Auth::id(),
            ]);
            $linked += $repo->linkRecurring($this->hid, array_values(array_unique($g['ids'])), $id);
            $linked += RecurrenceService::linkExisting($this->hid, $id);
            $created++;
        }
        if ($created) {
            RecurrenceService::materializeDue($this->hid);
        }
        return [$created, $linked];
    }

    /** AJAX: Kategorie-Vorschlag zu Empfänger/Verwendungszweck */
    public function suggest(): void
    {
        $s = (new CategorizationService($this->hid))->suggestForTransaction($this->request->str('payee'), $this->request->str('purpose'));
        $this->json($s);
    }

    private function validated(): array
    {
        $r = $this->request;
        $kind = in_array($r->str('kind'), ['expense', 'income', 'transfer'], true) ? $r->str('kind') : 'expense';
        $cents = Money::parse($r->str('amount'));
        $data = [
            'kind'          => $kind,
            'amount'        => $r->str('amount'),
            'cents'         => $cents === null ? 0 : abs($cents),
            'account_id'    => (int) $r->int('account_id'),
            'to_account_id' => $kind === 'transfer' ? (int) $r->int('to_account_id') : null,
            'category_id'   => $kind === 'transfer' ? null : (new CategoryRepository())->validId($r->int('category_id'), $this->hid),
            'booking_date'  => valid_date($r->str('booking_date'), date('Y-m-d')),
            'payee'         => mb_substr($r->str('payee'), 0, 190),
            'purpose'       => mb_substr($r->str('purpose'), 0, 2000),
            'note'          => mb_substr($r->str('note'), 0, 255),
        ];
        $error = null;
        if (!$cents) {
            $error = 'Bitte einen Betrag eingeben.';
        } elseif (!Auth::can($data['account_id'], 'book')) {
            $error = 'Auf dieses Konto darfst du nicht buchen.';
        } elseif ($kind === 'transfer' && (!Auth::can($data['to_account_id'], 'book') || $data['to_account_id'] === $data['account_id'])) {
            $error = 'Bitte ein anderes Zielkonto wählen.';
        }
        return [$data, $error];
    }

    private function maybeCreateRule(array $data): void
    {
        if (!$this->request->bool('create_rule') || !$data['payee'] || !$data['category_id']) {
            return;
        }
        $rules = new RuleRepository();
        $value = mb_strtolower($data['payee']);
        if (!$rules->exists($this->hid, 'transaction', 'payee', 'contains', $value)) {
            $rules->create($this->hid, [
                'target' => 'transaction', 'field' => 'payee', 'operator' => 'contains', 'value' => $value,
                'category_id' => $data['category_id'], 'priority' => 50, 'learned' => 1,
            ]);
        }
    }

    private function renderForm(array $tx, string $title, ?string $error = null): void
    {
        $repo = new TransactionRepository();
        $this->view('transactions/form', [
            'title'      => $title,
            'back'       => '/transactions',
            'tx'         => $tx,
            'error'      => $error,
            'accounts'   => (new AccountRepository())->options($this->hid, Auth::accountIds('book')),
            'categories' => (new CategoryRepository())->all($this->hid),
            'payees'     => $repo->recentPayees($this->hid, Auth::accountIds('view')),
        ]);
    }
}
