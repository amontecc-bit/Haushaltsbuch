<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Session;
use App\Repositories\InventoryRepository;
use App\Repositories\RecipeRepository;
use App\Repositories\ShoppingRepository;
use App\Services\InventoryService;
use App\Services\RecipeService;
use App\Services\ShoppingListService;

/**
 * Rezepte mit Stückliste aus virtuellen Posten, Klassifikation und Portionen; Vorschläge nach Vorrat,
 * „Gekocht“ (Vorrat abbuchen) und fehlende Zutaten auf eine Einkaufsliste.
 */
final class RecipeController extends Controller
{
    /** Anzahl Vorschläge, die „Weitere Vorschläge“ jeweils nachlädt */
    private const PAGE = 10;

    public function index(): void
    {
        $repo = $this->repo();
        $recipes = $repo->all($this->hid);
        $evals = $this->evaluateAll($recipes, null);
        $this->view('recipes/index', [
            'title'   => 'Rezepte',
            'actions' => '<a class="btn btn-primary" href="' . e(url('/recipes/create')) . '"><i class="bi bi-plus-lg"></i> Neues Rezept</a>'
                . '<a class="btn btn-outline-primary" href="' . e(url('/recipes/suggestions')) . '"><i class="bi bi-lightbulb"></i> Vorschläge</a>'
                . '<a class="btn btn-outline-primary" href="' . e(url('/recipes/classes')) . '"><i class="bi bi-tags"></i> Klassifikation</a>',
            'groups'  => $repo->classGroups($this->hid),
            'recipes' => array_map(fn ($r) => [
                'id' => (int) $r['id'], 'name' => $r['name'], 'servings' => (int) $r['servings'],
                'ingredients' => (int) $r['ingredient_count'], 'values' => $r['value_ids'],
                'complete' => $evals[(int) $r['id']]['complete'], 'missing' => $evals[(int) $r['id']]['missing_count'],
            ], $recipes),
            'scripts' => ['js/recipes.js'],
        ]);
    }

    /** Vorschläge: zuerst mit dem Vorrat machbare Rezepte, auf Wunsch weitere mit fehlenden Zutaten */
    public function suggestions(): void
    {
        $repo = $this->repo();
        $groups = $repo->classGroups($this->hid);
        $values = $repo->validValueIds($this->hid, (array) $this->request->input('v', []));
        $servings = $this->servings($this->request->int('servings'));
        $recipes = $repo->all($this->hid);
        // Filter: je Gruppe muss einer der gewählten Werte passen (innerhalb einer Gruppe „oder“, zwischen Gruppen „und“)
        if ($values) {
            $byGroup = [];
            foreach ($groups as $g) {
                $sel = array_intersect(array_column($g['values'], 'id'), $values);
                if ($sel) {
                    $byGroup[] = $sel;
                }
            }
            $recipes = array_filter($recipes, fn ($r) => array_reduce($byGroup, fn ($ok, $sel) => $ok && array_intersect($sel, $r['value_ids']), true));
        }
        $evals = $this->evaluateAll($recipes, $servings);
        $list = RecipeService::rank(array_map(fn ($r) => ['name' => $r['name'], 'recipe' => $r, 'eval' => $evals[(int) $r['id']]], $recipes));

        $this->view('recipes/suggestions', [
            'title'    => 'Rezeptvorschläge',
            'back'     => '/recipes',
            'actions'  => '<a class="btn btn-outline-primary" href="' . e(url('/inventory')) . '"><i class="bi bi-box-seam"></i> Vorrat</a>',
            'groups'   => $groups,
            'values'   => $values,
            'servings' => $servings,
            'cfg'      => [
                'page' => self::PAGE,
                'recipes' => array_map(fn ($x) => RecipeService::card($x['recipe'], $x['eval'], $servings), $list),
                'lists' => $this->listOptions(),
            ],
            'scripts'  => ['js/recipes.js'],
        ]);
    }

    public function show(int $id): void
    {
        $repo = $this->repo();
        $r = $repo->find($id, $this->hid) ?? $this->notFound();
        $servings = $this->servings($this->request->int('servings')) ?? (int) $r['servings'];
        $eval = $this->evaluateAll([$r], $servings)[$id];
        $names = [];
        foreach ($repo->classGroups($this->hid) as $g) {
            foreach ($g['values'] as $v) {
                if (in_array($v['id'], $r['value_ids'], true)) {
                    $names[] = $v['name'];
                }
            }
        }
        $this->view('recipes/show', [
            'title'    => $r['name'],
            'back'     => '/recipes',
            'actions'  => '<a class="btn btn-outline-primary" href="' . e(url("/recipes/$id/edit")) . '"><i class="bi bi-pencil"></i> Bearbeiten</a>',
            'recipe'   => $r,
            'classes'  => $names,
            'servings' => $servings,
            'card'     => RecipeService::card($r, $eval, $servings),
            'lists'    => $this->listOptions(),
        ]);
    }

    public function create(): void
    {
        $this->renderForm(['id' => null, 'name' => '', 'servings' => 2, 'instructions' => '', 'value_ids' => []], []);
    }

    public function edit(int $id): void
    {
        $repo = $this->repo();
        $r = $repo->find($id, $this->hid) ?? $this->notFound();
        $this->renderForm($r, $repo->ingredients([$id])[$id] ?? []);
    }

    public function store(): void
    {
        [$data, $ingredients, $values] = $this->validated();
        $repo = $this->repo();
        $id = $repo->transaction(function () use ($repo, $data, $ingredients, $values) {
            $id = $repo->create($this->hid, $data + ['created_by' => Auth::id() ?: null]);
            $repo->replaceIngredients($id, $ingredients);
            $repo->setClasses($id, $values);
            return $id;
        });
        Session::flash('success', 'Rezept gespeichert.');
        $this->json(['ok' => true, 'redirect' => url("/recipes/$id")]);
    }

    public function update(int $id): void
    {
        $repo = $this->repo();
        $repo->find($id, $this->hid) ?? $this->json(['error' => 'Nicht gefunden'], 404);
        [$data, $ingredients, $values] = $this->validated();
        $repo->transaction(function () use ($repo, $id, $data, $ingredients, $values) {
            $repo->update($id, $this->hid, $data);
            $repo->replaceIngredients($id, $ingredients);
            $repo->setClasses($id, $values);
        });
        Session::flash('success', 'Rezept gespeichert.');
        $this->json(['ok' => true, 'redirect' => url("/recipes/$id")]);
    }

    public function delete(int $id): void
    {
        $this->repo()->delete($id, $this->hid);
        $this->redirect('/recipes', 'Rezept gelöscht.');
    }

    /** Gekocht: Zutaten (skaliert auf die gewählten Portionen) vom Vorrat abbuchen */
    public function cook(int $id): void
    {
        $repo = $this->repo();
        $r = $repo->find($id, $this->hid) ?? $this->notFound();
        $servings = $this->servings($this->request->int('servings')) ?? (int) $r['servings'];
        $short = RecipeService::cook($this->hid, $r, $servings);
        $msg = "„{$r['name']}“ für $servings " . ($servings === 1 ? 'Portion' : 'Portionen') . ' vom Vorrat abgebucht.';
        if ($short) {
            $msg .= ' Laut Vorrat war nicht genug da von: ' . implode(', ', $short) . '.';
        }
        $this->redirect("/recipes/$id?servings=$servings", $msg);
    }

    /** Fehlende Zutaten auf eine Einkaufsliste setzen (list_id = Liste oder „auto“) */
    public function toShoppingList(int $id): void
    {
        $repo = $this->repo();
        $r = $repo->find($id, $this->hid) ?? $this->notFound();
        $servings = $this->servings($this->request->int('servings')) ?? (int) $r['servings'];
        $shop = new ShoppingRepository();
        $list = $this->request->str('list_id') === 'auto' || !$this->request->int('list_id')
            ? $shop->autoList($this->hid)
            : ($shop->findList((int) $this->request->int('list_id'), $this->hid) ?? $this->notFound());
        $n = count(RecipeService::addMissingToList($this->hid, $r, $servings, (int) $list['id']));
        if (!$n) {
            $this->redirect("/recipes/$id?servings=$servings", 'Es fehlt nichts – alle Zutaten sind laut Vorrat da.', 'info');
        }
        $this->redirect("/shopping/{$list['id']}", ($n === 1 ? '1 fehlende Zutat' : "$n fehlende Zutaten") . " von „{$r['name']}“ auf die Liste gesetzt.");
    }

    // ---- Klassifikation ---------------------------------------------------------------

    public function classes(): void
    {
        $this->view('recipes/classes', [
            'title'   => 'Klassifikation der Rezepte',
            'back'    => '/recipes',
            'groups'  => $this->repo()->classGroups($this->hid),
            'scripts' => ['js/recipes.js'],
        ]);
    }

    /** AJAX: alle Gruppen mit Werten speichern */
    public function saveClasses(): void
    {
        $groups = [];
        foreach ($this->request->arr('groups') as $g) {
            $name = is_array($g) ? mb_substr(trim((string) ($g['name'] ?? '')), 0, 80) : '';
            if ($name === '') {
                continue;
            }
            $values = [];
            foreach ((array) ($g['values'] ?? []) as $v) {
                $vn = is_array($v) ? mb_substr(trim((string) ($v['name'] ?? '')), 0, 80) : '';
                if ($vn !== '') {
                    $values[] = ['id' => (int) ($v['id'] ?? 0), 'name' => $vn];
                }
            }
            $groups[] = ['id' => (int) ($g['id'] ?? 0), 'name' => $name, 'values' => $values];
        }
        $this->repo()->saveClasses($this->hid, $groups);
        Session::flash('success', 'Klassifikation gespeichert.');
        $this->json(['ok' => true, 'redirect' => url('/recipes')]);
    }

    // ---------------------------------------------------------------------

    private ?RecipeRepository $recipes = null;

    /** Repository; legt beim allerersten Aufruf die Standard-Klassifikation (Mahlzeit) an */
    private function repo(): RecipeRepository
    {
        if (!$this->recipes) {
            $this->recipes = new RecipeRepository();
            $this->recipes->ensureDefaultClasses($this->hid);
        }
        return $this->recipes;
    }

    /** @return array<int, array> Rezept-ID => Ergebnis von RecipeService::evaluate() (null Portionen = wie im Rezept) */
    private function evaluateAll(array $recipes, ?int $servings): array
    {
        $this->repo(); // Standard-Klassifikation sicherstellen
        return RecipeService::evaluateAll($this->hid, $recipes, $servings);
    }

    /** Einkaufslisten zur Auswahl; die automatische Liste steht immer vorne (wird bei Bedarf angelegt) */
    private function listOptions(): array
    {
        $out = [['id' => 'auto', 'name' => 'Automatisch']];
        foreach ((new ShoppingRepository())->lists($this->hid) as $l) {
            if (!$l['is_auto']) {
                $out[] = ['id' => (int) $l['id'], 'name' => $l['name']];
            }
        }
        return $out;
    }

    private function servings(?int $v): ?int
    {
        return $v !== null && $v > 0 ? min($v, 99) : null;
    }

    private function renderForm(array $r, array $ingredients): void
    {
        $shop = new ShoppingRepository();
        $this->view('recipes/form', [
            'title'   => $r['id'] ? 'Rezept bearbeiten' : 'Neues Rezept',
            'back'    => $r['id'] ? "/recipes/{$r['id']}" : '/recipes',
            'recipe'  => $r,
            'cfg'     => [
                'id'           => $r['id'] ? (int) $r['id'] : null,
                'name'         => $r['name'],
                'servings'     => (int) $r['servings'],
                'instructions' => (string) $r['instructions'],
                'values'       => $r['value_ids'],
                'ingredients'  => array_map(fn ($i) => [
                    'item_id' => (int) $i['shopping_item_id'], 'name' => $i['name'],
                    'quantity' => rtrim(rtrim(number_format((float) $i['quantity'], 3, ',', ''), '0'), ','),
                    'unit' => (string) $i['unit'], 'note' => (string) $i['note'],
                ], $ingredients),
                'items'        => array_map(fn ($i) => [
                    'id' => (int) $i['id'], 'name' => $i['name'], 'ref_unit' => (string) InventoryService::unit($i['ref_unit']),
                    'staple' => (bool) $i['staple'],
                ], $shop->items($this->hid)),
                'units'        => InventoryService::UNITS,
            ],
            'groups'  => $this->repo()->classGroups($this->hid),
            'scripts' => ['js/recipes.js'],
        ]);
    }

    /**
     * Formular prüfen, Zutaten siehe RecipeService::ingredientRows().
     * @return array{0:array, 1:array, 2:int[]} Rezeptdaten, Zutaten, Klassenwert-IDs
     */
    private function validated(): array
    {
        $r = $this->request;
        $name = mb_substr($r->str('name'), 0, 190);
        if ($name === '') {
            $this->json(['error' => 'Bitte einen Namen eingeben.'], 422);
        }
        $data = [
            'name'         => $name,
            'servings'     => max(1, min((int) $r->int('servings', 2), 99)),
            'instructions' => mb_substr($r->str('instructions'), 0, 60000) ?: null,
        ];

        try {
            $ingredients = RecipeService::ingredientRows($this->hid, $r->arr('ingredients'));
        } catch (\DomainException $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
        $values = $this->repo()->validValueIds($this->hid, $r->arr('values'));
        return [$data, $ingredients, $values];
    }
}
