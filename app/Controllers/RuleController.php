<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\CategoryRepository;
use App\Repositories\RuleRepository;
use App\Services\CategorizationService;

final class RuleController extends Controller
{
    public function index(): void
    {
        $this->view('rules/index', [
            'title'      => 'Regeln für automatische Zuordnung',
            'back'       => '/categories',
            'rules'      => (new RuleRepository())->all($this->hid),
            'categories' => (new CategoryRepository())->all($this->hid),
        ]);
    }

    public function store(): void
    {
        if (Auth::isChild()) {
            $this->back('/rules', 'Keine Berechtigung.');
        }
        $r = $this->request;
        $target = $r->str('target') === 'item' ? 'item' : 'transaction';
        $field = in_array($r->str('field'), ['payee', 'purpose', 'any', 'name'], true) ? $r->str('field') : 'any';
        if ($target === 'item') {
            $field = 'name';
        } elseif ($field === 'name') {
            $field = 'any';
        }
        $operator = in_array($r->str('operator'), ['contains', 'equals', 'starts', 'regex'], true) ? $r->str('operator') : 'contains';
        $value = mb_substr($r->str('value'), 0, 190);
        $cat = (new CategoryRepository())->validId($r->int('category_id'), $this->hid);
        if ($value === '' || !$cat) {
            $this->back('/rules', 'Bitte Suchbegriff und Kategorie angeben.');
        }
        if ($operator === 'regex' && @preg_match('/' . str_replace('/', '\/', $value) . '/iu', '') === false) {
            $this->back('/rules', 'Der reguläre Ausdruck ist ungültig.');
        }
        (new RuleRepository())->create($this->hid, [
            'target' => $target, 'field' => $field, 'operator' => $operator,
            'value' => $operator === 'regex' ? $value : mb_strtolower($value),
            'category_id' => $cat, 'priority' => max(1, min(999, (int) $r->int('priority', 100))),
        ]);
        $this->redirect('/rules', 'Regel angelegt.');
    }

    public function delete(int $id): void
    {
        if (Auth::isChild()) {
            $this->back('/rules', 'Keine Berechtigung.');
        }
        (new RuleRepository())->delete($id, $this->hid);
        $this->redirect('/rules', 'Regel gelöscht.');
    }

    /** Wendet Regeln + Vorschläge auf alle Buchungen ohne Kategorie an */
    public function apply(): void
    {
        $ids = Auth::accountIds('book');
        if (!$ids) {
            $this->redirect('/rules');
        }
        $db = Database::connection();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT id, payee, purpose FROM transactions
                            WHERE household_id = ? AND category_id IS NULL AND transfer_group IS NULL AND account_id IN ($in)");
        $st->execute([$this->hid, ...$ids]);
        $svc = new CategorizationService($this->hid);
        $upd = $db->prepare('UPDATE transactions SET category_id = ? WHERE id = ?');
        $n = 0;
        foreach ($st->fetchAll() as $t) {
            $s = $svc->suggestForTransaction($t['payee'], $t['purpose']);
            if ($s['category_id']) {
                $upd->execute([$s['category_id'], $t['id']]);
                $n++;
            }
        }
        $this->redirect('/rules', "$n Buchungen wurden automatisch zugeordnet.");
    }
}
