<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Core\Money;
use App\Repositories\CategoryRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\RecipeRepository;
use App\Repositories\ShoppingRepository;
use App\Services\CategorizationService;
use App\Services\InventoryService;
use App\Services\RecipeService;
use App\Services\ShoppingListService;
use App\Services\TransactionService;

/**
 * Die Tools des MCP-Servers: Einkaufslisten, Vorrat, Rezepte und Bargeld-Ausgaben (Kalender: CalendarTools).
 *
 * Bewusst KEINE Finanzdaten: keine Kontostände, Umsätze, Preise, Geschäfte oder Einkäufe. Die einzige Ausnahme ist
 * das Buchen einer Bargeld-Ausgabe bzw. eines Kalender-Budgets auf ein Bargeld-Konto – die Antwort bestätigt nur die
 * Buchung selbst. Neue Tools nur nach diesem Prinzip.
 * Der aufrufende Benutzer ist per Auth::actAs() gesetzt (Rechte auf Konten wie in der Web-Oberfläche).
 */
final class McpTools extends ToolSet
{
    /** Höchstbetrag einer Bargeld-Buchung über Claude (Schutz vor Hörfehlern wie „1250“ statt „12,50“) */
    public const CASH_MAX_CENTS = 500000;

    private const STATUS = [
        'ok' => 'vorrätig', 'low' => 'knapp', 'out' => 'aufgebraucht', 'expired' => 'abgelaufen',
        'staple' => 'Grundvorrat (Menge wird nicht geführt)', 'none' => 'nie im Vorrat',
    ];

    private ?RecipeRepository $recipes = null;

    public static function instructions(): string
    {
        return 'Haushaltsbuch einer Familie (deutschsprachig). Über diesen Zugang gibt es nur Einkaufslisten, Vorrat, Rezepte, '
            . 'den Kalender und das Buchen von Bargeld-Ausgaben. Kontostände, Umsätze, Preise und Auswertungen sind bewusst nicht abrufbar – '
            . 'bei solchen Fragen auf die Web-App verweisen. Posten heißen „virtuelle Posten“ (z. B. „Milch“) und bündeln '
            . 'gekaufte Produkte; vorhandene Namen bevorzugen (get_inventory mit filter=all), statt ähnliche neu anzulegen. '
            . 'Vor Bargeld-Buchungen Betrag und Zweck kurz bestätigen lassen, wenn sie nicht eindeutig sind. '
            . 'Beim Aufräumen der Posten (list_items, list_products) Änderungen zuerst als Vorschlag zeigen; '
            . 'merge_items und delete_item nur nach ausdrücklicher Bestätigung. '
            . 'Kalender: Termine mit Vorlauf (Anreise) und Nachlauf (Abreise); role „owner“ = eigener Termin, „participant“ = nur Teilnehmer. '
            . 'Zeiten als „JJJJ-MM-TT HH:MM“ in Ortszeit. delete_event nur nach Bestätigung; Budgets (falls freigegeben) vor dem Buchen bestätigen lassen.';
    }

    /** Alle Tools: diese Gruppe plus Pflege der virtuellen Posten und Produkte sowie der Kalender */
    public function all(): array
    {
        return $this->tools() + (new ItemTools($this->hid, $this->userId, $this->clientName))->tools()
            + (new CalendarTools($this->hid, $this->userId, $this->clientName))->tools();
    }

    public function tools(): array
    {
        $listId = ['type' => 'integer', 'description' => 'ID der Einkaufsliste (aus list_shopping_lists). Ohne Angabe: die zuletzt angelegte eigene Liste.'];
        $recipeId = ['type' => 'integer', 'description' => 'ID des Rezepts (aus search_recipes oder suggest_recipes)'];
        $servings = ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'description' => 'Portionen; ohne Angabe wie im Rezept'];
        $entrySelect = [
            'list_id'   => $listId,
            'entry_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'IDs der Einträge (aus get_shopping_list)'],
            'names'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'alternativ Namen der Posten, z. B. ["Milch"]'],
        ];

        return [
            // ---- Einkaufslisten ----------------------------------------------------------------
            'list_shopping_lists' => [
                'title' => 'Einkaufslisten anzeigen',
                'description' => 'Alle Einkaufslisten des Haushalts mit Anzahl offener und erledigter Einträge. '
                    . 'Die Liste „Automatisch“ füllt das Haushaltsbuch selbst mit Posten, die im Vorrat fehlen.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->listShoppingLists(),
            ],
            'get_shopping_list' => [
                'title' => 'Einkaufsliste anzeigen',
                'description' => 'Einträge einer Einkaufsliste (Name, Menge, Notiz), nach Kategorie sortiert.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'list_id'      => $listId,
                    'include_done' => ['type' => 'boolean', 'description' => 'auch abgehakte Einträge zeigen (Standard: nein)'],
                ]],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->getShoppingList($a),
            ],
            'create_shopping_list' => [
                'title' => 'Einkaufsliste anlegen',
                'description' => 'Neue, leere Einkaufsliste anlegen (z. B. „Wochenende“, „Drogerie“).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Name der Liste'],
                ], 'required' => ['name']],
                'annotations' => self::write(false, false),
                'handler' => fn (array $a) => $this->createShoppingList($a),
            ],
            'add_to_shopping_list' => [
                'title' => 'Auf die Einkaufsliste setzen',
                'description' => 'Posten auf eine Einkaufsliste setzen. Steht ein Posten schon offen drauf, wird die Menge erhöht. '
                    . 'Unbekannte Namen werden als neuer virtueller Posten angelegt (new_item=true in der Antwort). '
                    . 'Allgemeine Namen verwenden („Milch“, nicht „Weihenstephan H-Milch 1,5 %“); Marke oder Sorte gehört in note.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'list_id' => $listId,
                    'items'   => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name'     => ['type' => 'string', 'description' => 'z. B. „Milch“'],
                            'quantity' => ['type' => 'number', 'description' => 'Menge, Standard 1'],
                            'unit'     => ['type' => 'string', 'enum' => ['Stück', 'kg', 'g', 'l', 'ml', 'Pck'], 'description' => 'Standard Stück'],
                            'note'     => ['type' => 'string', 'description' => 'z. B. „laktosefrei“'],
                        ],
                        'required' => ['name'],
                    ]],
                ], 'required' => ['items']],
                'annotations' => self::write(false, false),
                'handler' => fn (array $a) => $this->addToShoppingList($a),
            ],
            'check_shopping_entries' => [
                'title' => 'Einträge abhaken',
                'description' => 'Einträge einer Einkaufsliste als gekauft abhaken (oder mit done=false wieder öffnen). '
                    . 'Einträge per entry_ids oder names auswählen.',
                'inputSchema' => ['type' => 'object', 'properties' => $entrySelect + [
                    'done' => ['type' => 'boolean', 'description' => 'true = abhaken (Standard), false = wieder öffnen'],
                ]],
                'annotations' => self::write(false, true),
                'handler' => fn (array $a) => $this->checkEntries($a),
            ],
            'remove_shopping_entries' => [
                'title' => 'Einträge entfernen',
                'description' => 'Einträge von einer Einkaufsliste entfernen (z. B. versehentlich hinzugefügt). Einträge per entry_ids oder names.',
                'inputSchema' => ['type' => 'object', 'properties' => $entrySelect],
                'annotations' => self::write(true, true),
                'handler' => fn (array $a) => $this->removeEntries($a),
            ],

            // ---- Vorrat ------------------------------------------------------------------------
            'get_inventory' => [
                'title' => 'Vorrat anzeigen',
                'description' => 'Vorrat je virtuellem Posten: Bestand, Status, nächstes Ablaufdatum. Mengen in der Einheit des Postens '
                    . '(„unit“ zeigt die Referenzmenge, z. B. „1 l“). filter: in_stock (vorrätig, Standard), needed (knapp, aufgebraucht, '
                    . 'abgelaufen), all (alle Posten – auch um vorhandene Namen zu finden).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'filter' => ['type' => 'string', 'enum' => ['in_stock', 'needed', 'all']],
                    'search' => ['type' => 'string', 'description' => 'Teil des Namens, z. B. „Käse“'],
                ]],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->getInventory($a),
            ],
            'update_inventory' => [
                'title' => 'Vorrat korrigieren',
                'description' => 'Bestand eines Postens ändern: set (auf Menge setzen), add (hinzufügen), remove (verbraucht), '
                    . 'empty (ist leer). Ohne unit gilt die Einheit des Postens. Fehlende Posten erscheinen automatisch auf der Liste „Automatisch“. '
                    . 'Unbekannte Posten nur mit create_if_missing=true anlegen.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'item'     => ['type' => 'string', 'description' => 'Name des Postens, z. B. „Mehl“'],
                    'item_id'  => ['type' => 'integer', 'description' => 'alternativ die ID aus get_inventory'],
                    'action'   => ['type' => 'string', 'enum' => ['set', 'add', 'remove', 'empty']],
                    'quantity' => ['type' => 'number', 'description' => 'Menge (nicht bei empty)'],
                    'unit'     => ['type' => 'string', 'enum' => ['Stück', 'g', 'kg', 'ml', 'l']],
                    'create_if_missing' => ['type' => 'boolean', 'description' => 'unbekannten Posten neu anlegen (nur set/add, Standard: nein)'],
                ], 'required' => ['action']],
                'annotations' => self::write(false, false),
                'handler' => fn (array $a) => $this->updateInventory($a),
            ],

            // ---- Rezepte -----------------------------------------------------------------------
            'search_recipes' => [
                'title' => 'Rezepte suchen',
                'description' => 'Rezepte nach Name oder Zutat suchen, optional nach Klassen filtern (z. B. „abends“). '
                    . 'Liefert auch die verfügbaren Klassen und ob das Rezept mit dem Vorrat kochbar ist.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query'   => ['type' => 'string', 'description' => 'Teil des Namens oder einer Zutat'],
                    'classes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Klassenwerte, z. B. ["abends"]'],
                ]],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->searchRecipes($a),
            ],
            'get_recipe' => [
                'title' => 'Rezept anzeigen',
                'description' => 'Rezept mit Zutaten (skaliert auf die Portionen), Zubereitung und Abgleich mit dem Vorrat (vorhanden/fehlt).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'recipe_id' => $recipeId, 'servings' => $servings,
                ], 'required' => ['recipe_id']],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->getRecipe($a),
            ],
            'suggest_recipes' => [
                'title' => 'Rezeptvorschläge',
                'description' => 'Rezepte, die sich mit dem aktuellen Vorrat kochen lassen – zuerst vollständig machbare, dann solche mit '
                    . 'wenigen fehlenden Zutaten. Optional nach Klassen filtern (z. B. „abends“).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'servings'      => $servings,
                    'classes'       => ['type' => 'array', 'items' => ['type' => 'string']],
                    'limit'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'Standard 5'],
                    'only_complete' => ['type' => 'boolean', 'description' => 'nur vollständig machbare Rezepte'],
                ]],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->suggestRecipes($a),
            ],
            'cook_recipe' => [
                'title' => 'Rezept gekocht',
                'description' => 'Rezept als gekocht verbuchen: Zutaten (skaliert auf die Portionen) werden vom Vorrat abgezogen. '
                    . 'Nur aufrufen, wenn wirklich gekocht wurde.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'recipe_id' => $recipeId, 'servings' => $servings,
                ], 'required' => ['recipe_id']],
                'annotations' => self::write(false, false),
                'handler' => fn (array $a) => $this->cookRecipe($a),
            ],
            'add_recipe_to_shopping_list' => [
                'title' => 'Fehlende Zutaten auf die Liste',
                'description' => 'Fehlende Zutaten eines Rezepts (laut Vorrat) auf eine Einkaufsliste setzen. Ohne list_id: Liste „Automatisch“.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'recipe_id' => $recipeId, 'servings' => $servings,
                    'list_id'   => ['type' => 'integer', 'description' => 'ID der Einkaufsliste; ohne Angabe die Liste „Automatisch“'],
                ], 'required' => ['recipe_id']],
                'annotations' => self::write(false, false),
                'handler' => fn (array $a) => $this->recipeToShoppingList($a),
            ],
            'create_recipe' => [
                'title' => 'Rezept anlegen',
                'description' => 'Neues Rezept anlegen, z. B. aus einem eingefügten Text oder einer Webseite. Je Zutat eine Zeile mit '
                    . 'allgemeinem Namen („Zwiebeln“, „Sahne“) – vorhandene Posten-Namen bevorzugen (get_inventory mit filter=all). '
                    . 'unit nur g, kg, ml, l oder Stück: EL/TL/Tasse in g bzw. ml umrechnen; Prise, Bund, Zehe usw. als Stück; '
                    . 'Gewürze ohne sinnvolle Menge mit quantity 1 und dem Maß in note. Zubereitung als Text mit nummerierten Schritten. '
                    . 'Vor dem Anlegen dem Benutzer die Zutatenliste kurz zeigen.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'name'         => ['type' => 'string'],
                    'servings'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'description' => 'Portionen des Rezepts'],
                    'instructions' => ['type' => 'string', 'description' => 'Zubereitung'],
                    'ingredients'  => ['type' => 'array', 'minItems' => 1, 'maxItems' => 60, 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name'     => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'unit'     => ['type' => 'string', 'enum' => ['Stück', 'g', 'kg', 'ml', 'l']],
                            'note'     => ['type' => 'string', 'description' => 'z. B. „gewürfelt“, „1 EL“'],
                        ],
                        'required' => ['name', 'quantity'],
                    ]],
                    'classes'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Klassenwerte aus search_recipes, z. B. ["abends"]'],
                ], 'required' => ['name', 'servings', 'ingredients']],
                'annotations' => self::write(false, false),
                'handler' => fn (array $a) => $this->createRecipe($a),
            ],

            // ---- Bargeld ------------------------------------------------------------------------
            'book_cash_expense' => [
                'title' => 'Bargeld-Ausgabe buchen',
                'description' => 'Eine Bargeld-Ausgabe auf ein Bargeld-Konto buchen (z. B. „12,50 beim Bäcker“). Nur Ausgaben, nur '
                    . 'Bargeld-Konten, höchstens ' . Money::format(self::CASH_MAX_CENTS) . '. Ohne category wird die Kategorie '
                    . 'automatisch vorgeschlagen. Die Antwort enthält keinen Kontostand. Ändern/Löschen nur in der Web-App.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'amount'      => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Betrag in Euro, positiv, z. B. 12.5'],
                    'description' => ['type' => 'string', 'description' => 'Wofür/wo, z. B. „Bäcker“, „Eis für die Kinder“'],
                    'date'        => ['type' => 'string', 'description' => 'Datum JJJJ-MM-TT, Standard heute'],
                    'category'    => ['type' => 'string', 'description' => 'Kategorie-Name, optional'],
                    'account'     => ['type' => 'string', 'description' => 'Name des Bargeld-Kontos, nur nötig bei mehreren'],
                ], 'required' => ['amount', 'description']],
                'annotations' => self::write(false, false),
                'handler' => fn (array $a) => $this->bookCashExpense($a),
            ],
        ];
    }

    // ---- Einkaufslisten --------------------------------------------------------------------

    private function listShoppingLists(): array
    {
        InventoryService::refreshAutoList($this->hid);
        return array_map(fn ($l) => [
            'list_id' => (int) $l['id'], 'name' => $l['name'], 'automatic' => (bool) $l['is_auto'],
            'open' => (int) $l['open_count'], 'done' => (int) $l['done_count'], 'created' => substr((string) $l['created_at'], 0, 10),
        ], (new ShoppingRepository())->lists($this->hid));
    }

    private function getShoppingList(array $a): array
    {
        $list = $this->list(self::int($a, 'list_id'));
        if ($list['is_auto']) {
            InventoryService::refreshAutoList($this->hid);
        }
        $includeDone = self::bool($a, 'include_done', false);
        $entries = array_values(array_filter($this->entries((int) $list['id']), fn ($e) => $includeDone || !$e['done']));
        return ['list_id' => (int) $list['id'], 'name' => $list['name'], 'automatic' => (bool) $list['is_auto'], 'entries' => $entries];
    }

    private function createShoppingList(array $a): array
    {
        $name = self::str($a, 'name', 120);
        $id = (new ShoppingRepository())->createList($this->hid, $name, $this->userId);
        $this->log('create_shopping_list', "Einkaufsliste „{$name}“ angelegt");
        return ['list_id' => $id, 'name' => $name];
    }

    private function addToShoppingList(array $a): array
    {
        $rows = $a['items'] ?? null;
        if (!is_array($rows) || !$rows) {
            throw new ToolError('items fehlt.');
        }
        $repo = new ShoppingRepository();
        $list = $this->list(self::int($a, 'list_id'), true);
        $added = [];
        foreach (array_slice($rows, 0, 50) as $row) {
            $row = is_string($row) ? ['name' => $row] : (is_array($row) ? $row : []);
            $name = self::str($row, 'name');
            if ($name === '') {
                continue;
            }
            $existed = $repo->findItemByNormalized($this->hid, mb_substr(ShoppingListService::normalize($name), 0, 190));
            $itemId = ShoppingListService::itemByName($this->hid, $name);
            $qty = self::num($row, 'quantity') ?? 1.0;
            $qty = $qty > 0 ? min(round($qty, 3), 9999) : 1.0;
            [$unit, $unitNote] = self::listUnit(self::str($row, 'unit', 20));
            $note = self::str($row, 'note') ?: $unitNote;
            $repo->addEntry((int) $list['id'], $itemId, $qty, $unit, $note !== '' ? $note : null);
            $added[] = [
                'name' => $repo->findItem($itemId, $this->hid)['name'], 'quantity' => ShoppingListService::quantityLabel($qty, $unit),
                'new_item' => !$existed,
            ];
        }
        if (!$added) {
            throw new ToolError('Keine Posten mit Namen übergeben.');
        }
        $this->log('add_to_shopping_list', 'Auf „' . $list['name'] . '“: ' . implode(', ', array_column($added, 'name')));
        return ['list_id' => (int) $list['id'], 'list' => $list['name'], 'added' => $added];
    }

    private function checkEntries(array $a): array
    {
        $done = self::bool($a, 'done', true);
        [$list, $hits, $missing] = $this->selectEntries($a);
        $repo = new ShoppingRepository();
        foreach ($hits as $e) {
            if ($done) {
                $repo->markDone($e['entry_id'], null);
            } else {
                $repo->updateEntry($e['entry_id'], (int) $list['id'], ['done_at' => null, 'purchase_id' => null]);
            }
        }
        $names = array_column($hits, 'name');
        $this->log('check_shopping_entries', ($done ? 'Abgehakt' : 'Wieder geöffnet') . ' auf „' . $list['name'] . '“: ' . implode(', ', $names));
        return ['list' => $list['name'], $done ? 'checked' : 'reopened' => $names, 'not_found' => $missing];
    }

    private function removeEntries(array $a): array
    {
        [$list, $hits, $missing] = $this->selectEntries($a);
        $repo = new ShoppingRepository();
        foreach ($hits as $e) {
            $repo->deleteEntry($e['entry_id'], (int) $list['id']);
        }
        $names = array_column($hits, 'name');
        $this->log('remove_shopping_entries', 'Entfernt von „' . $list['name'] . '“: ' . implode(', ', $names));
        return ['list' => $list['name'], 'removed' => $names, 'not_found' => $missing];
    }

    /**
     * Einträge per entry_ids und/oder names auswählen (Name: gleicher normalisierter Name, sonst eindeutiger Teiltreffer).
     * @return array{0:array, 1:array, 2:string[]} Liste, getroffene Einträge, nicht gefundene Angaben
     */
    private function selectEntries(array $a): array
    {
        $list = $this->list(self::int($a, 'list_id'));
        $entries = $this->entries((int) $list['id']);
        $byId = array_column($entries, null, 'entry_id');
        $hits = [];
        $missing = [];
        foreach ((array) ($a['entry_ids'] ?? []) as $id) {
            if (isset($byId[(int) $id])) {
                $hits[(int) $id] = $byId[(int) $id];
            } else {
                $missing[] = (string) $id;
            }
        }
        foreach (self::strings($a, 'names') as $name) {
            $norm = ShoppingListService::normalize($name);
            $found = array_filter($entries, fn ($e) => ShoppingListService::normalize($e['name']) === $norm);
            if (!$found) {
                $found = array_filter($entries, fn ($e) => mb_stripos($e['name'], $name) !== false || mb_stripos($name, $e['name']) !== false);
            }
            if (count($found) === 1) {
                $e = reset($found);
                $hits[$e['entry_id']] = $e;
            } else {
                $missing[] = $name . (count($found) > 1 ? ' (mehrdeutig: ' . implode(', ', array_column($found, 'name')) . ')' : '');
            }
        }
        if (!$hits) {
            throw new ToolError('Keine passenden Einträge auf „' . $list['name'] . '“ gefunden' . ($missing ? ': ' . implode(', ', $missing) : '') . '.');
        }
        return [$list, array_values($hits), $missing];
    }

    /** Liste per ID; ohne ID die zuletzt angelegte eigene Liste (ggf. neu angelegt), sonst „Automatisch“ */
    private function list(?int $listId, bool $createIfNone = false): array
    {
        $repo = new ShoppingRepository();
        if ($listId) {
            return $repo->findList($listId, $this->hid)
                ?? throw new ToolError("Einkaufsliste $listId gibt es nicht – list_shopping_lists zeigt alle Listen.");
        }
        foreach ($repo->lists($this->hid) as $l) {
            if (!$l['is_auto']) {
                return $l;
            }
        }
        if ($createIfNone) {
            return $repo->findList($repo->createList($this->hid, 'Einkaufsliste', $this->userId), $this->hid);
        }
        return $repo->autoList($this->hid);
    }

    /** Einträge ohne Preise, Geschäfte und Einkäufe */
    private function entries(int $listId): array
    {
        return array_map(fn ($e) => array_filter([
            'entry_id' => (int) $e['id'],
            'name'     => $e['name'],
            'quantity' => ShoppingListService::quantityLabel($e['quantity'], $e['unit']),
            'note'     => (string) $e['note'],
            'group'    => $e['cat_group'] ?? 'Ohne Kategorie',
            'done'     => $e['done_at'] !== null,
        ], fn ($v) => $v !== ''), (new ShoppingRepository())->entries($listId, []));
    }

    /** @return array{0:?string, 1:string} Einheit der Einkaufsliste, unbekannte Einheit als Notiz */
    private static function listUnit(string $unit): array
    {
        $u = mb_strtolower(rtrim($unit, '.'));
        return match (true) {
            in_array($u, ['', 'stück', 'stk', 'st'], true) => [null, ''],
            in_array($u, ['kg', 'g', 'l', 'ml'], true) => [$u, ''],
            in_array($u, ['pck', 'packung', 'packungen', 'päckchen', 'pack'], true) => ['Pck', ''],
            default => [null, $unit],
        };
    }

    // ---- Vorrat ----------------------------------------------------------------------------

    private function getInventory(array $a): array
    {
        $filter = self::str($a, 'filter', 20) ?: 'in_stock';
        $search = self::str($a, 'search', 100);
        $show = match ($filter) {
            'needed' => ['low', 'out', 'expired'],
            'all'    => array_keys(self::STATUS),
            default  => ['ok', 'low', 'staple'],
        };
        $out = [];
        foreach ((new InventoryRepository())->stockByItem($this->hid, date('Y-m-d')) as $r) {
            $status = InventoryService::status((float) $r['stock'], (float) $r['min_stock'], (int) $r['lot_count'] > 0, (bool) $r['staple'], $r['expired_on']);
            if (!in_array($status, $show, true) || ($search !== '' && mb_stripos($r['name'], $search) === false)) {
                continue;
            }
            $refQty = (float) $r['ref_quantity'];
            $out[] = array_filter([
                'item_id'     => (int) $r['id'],
                'name'        => $r['name'],
                'status'      => self::STATUS[$status],
                'stock'       => in_array($status, ['staple', 'none'], true) ? null : InventoryService::label((float) $r['stock'], $refQty, $r['ref_unit']),
                'unit'        => InventoryService::label(1, $refQty, $r['ref_unit']),
                'minimum'     => (float) $r['min_stock'] > 0 ? InventoryService::label((float) $r['min_stock'], $refQty, $r['ref_unit']) : null,
                'next_expiry' => $r['next_expiry'],
                'expired_on'  => $r['expired_on'],
            ], fn ($v) => $v !== null);
            if (count($out) >= 300) {
                break;
            }
        }
        return ['filter' => $filter, 'items' => $out];
    }

    private function updateInventory(array $a): array
    {
        $action = self::str($a, 'action', 10);
        if (!in_array($action, ['set', 'add', 'remove', 'empty'], true)) {
            throw new ToolError('action muss set, add, remove oder empty sein.');
        }
        $unitText = self::str($a, 'unit', 20);
        $item = $this->findItem($a, self::bool($a, 'create_if_missing', false) && in_array($action, ['set', 'add'], true), $unitText,
            hint: ' Mit create_if_missing=true wird er angelegt.');
        $itemId = (int) $item['id'];
        $refQty = (float) $item['ref_quantity'] ?: 1.0;
        $stock = (new InventoryRepository())->stockMap($this->hid, date('Y-m-d'))[$itemId] ?? 0.0;

        $amount = 0.0;
        if ($action !== 'empty') {
            $qty = self::num($a, 'quantity');
            if ($qty === null || $qty < 0 || ($qty == 0 && $action !== 'set')) {
                throw new ToolError('Bitte eine Menge (quantity) angeben.');
            }
            $unit = $unitText === '' ? InventoryService::unit($item['ref_unit']) : self::stockUnit($unitText);
            $amount = InventoryService::toRef($qty, $unit, $refQty, $item['ref_unit'])
                ?? throw new ToolError("„{$item['name']}“ wird in " . InventoryService::label(1, $refQty, $item['ref_unit'])
                    . '-Einheiten geführt – bitte die Menge in einer passenden Einheit angeben.');
        }
        $target = match ($action) {
            'set'    => $amount,
            'add'    => $stock + $amount,
            'remove' => max(0.0, $stock - $amount),
            'empty'  => 0.0,
        };
        InventoryService::adjust($this->hid, $item, $target);
        InventoryService::refreshAutoList($this->hid);
        $after = (new InventoryRepository())->stockMap($this->hid, date('Y-m-d'))[$itemId] ?? 0.0;
        $result = [
            'item' => $item['name'],
            'before' => InventoryService::label($stock, $refQty, $item['ref_unit']),
            'after' => InventoryService::label($after, $refQty, $item['ref_unit']),
        ];
        $this->log('update_inventory', "Vorrat {$item['name']}: {$result['before']} → {$result['after']}");
        return $result;
    }

    // ---- Rezepte ---------------------------------------------------------------------------

    private function searchRecipes(array $a): array
    {
        $groups = $this->recipeRepo()->classGroups($this->hid);
        $recipes = $this->filterByClasses($this->recipeRepo()->all($this->hid), $groups, self::strings($a, 'classes'));
        $query = self::str($a, 'query', 100);
        if ($query !== '') {
            $ingredients = $this->recipeRepo()->ingredients(array_map(fn ($r) => (int) $r['id'], $recipes));
            $recipes = array_filter($recipes, fn ($r) => mb_stripos($r['name'], $query) !== false
                || array_filter($ingredients[(int) $r['id']] ?? [], fn ($i) => mb_stripos($i['name'], $query) !== false));
        }
        $recipes = array_slice(array_values($recipes), 0, 100);
        $evals = RecipeService::evaluateAll($this->hid, $recipes, null);
        $names = self::classNames($groups);
        return [
            'available_classes' => array_map(fn ($g) => ['group' => $g['name'], 'values' => array_column($g['values'], 'name')], $groups),
            'recipes' => array_map(fn ($r) => [
                'recipe_id' => (int) $r['id'], 'name' => $r['name'], 'servings' => (int) $r['servings'],
                'classes' => array_values(array_intersect_key($names, array_flip($r['value_ids']))),
                'cookable_now' => $evals[(int) $r['id']]['complete'], 'missing_ingredients' => $evals[(int) $r['id']]['missing_count'],
            ], $recipes),
        ];
    }

    private function getRecipe(array $a): array
    {
        $r = $this->recipe($a);
        $servings = self::servings($a) ?? (int) $r['servings'];
        $card = RecipeService::card($r, RecipeService::evaluateAll($this->hid, [$r], $servings)[(int) $r['id']], $servings);
        $names = self::classNames($this->recipeRepo()->classGroups($this->hid));
        return [
            'recipe_id'     => (int) $r['id'],
            'name'          => $r['name'],
            'servings'      => $servings,
            'base_servings' => (int) $r['servings'],
            'classes'       => array_values(array_intersect_key($names, array_flip($r['value_ids']))),
            'cookable_now'  => $card['complete'],
            'ingredients'   => array_map(fn ($i) => array_filter([
                'name' => $i['name'], 'amount' => $i['need'], 'note' => $i['note'],
                'in_stock' => $i['staple'] ? 'Grundvorrat' : $i['have'], 'missing' => $i['missing'],
            ], fn ($v) => $v !== ''), $card['ingredients']),
            'instructions'  => (string) $r['instructions'],
        ];
    }

    private function suggestRecipes(array $a): array
    {
        $groups = $this->recipeRepo()->classGroups($this->hid);
        $recipes = $this->filterByClasses($this->recipeRepo()->all($this->hid), $groups, self::strings($a, 'classes'));
        $servings = self::servings($a);
        $evals = RecipeService::evaluateAll($this->hid, $recipes, $servings);
        $ranked = RecipeService::rank(array_map(fn ($r) => ['name' => $r['name'], 'recipe' => $r, 'eval' => $evals[(int) $r['id']]], $recipes));
        if (self::bool($a, 'only_complete', false)) {
            $ranked = array_filter($ranked, fn ($x) => $x['eval']['complete']);
        }
        $limit = max(1, min(20, self::int($a, 'limit') ?? 5));
        return array_map(function ($x) use ($servings) {
            $card = RecipeService::card($x['recipe'], $x['eval'], $servings);
            return [
                'recipe_id' => $card['id'], 'name' => $card['name'], 'servings' => $card['servings'],
                'cookable_now' => $card['complete'], 'coverage_percent' => $card['coverage'],
                'missing' => array_values(array_map(fn ($i) => $i['name'] . ' (' . $i['missing'] . ')',
                    array_filter($card['ingredients'], fn ($i) => $i['missing'] !== ''))),
            ];
        }, array_slice(array_values($ranked), 0, $limit));
    }

    private function cookRecipe(array $a): string
    {
        $r = $this->recipe($a);
        $servings = self::servings($a) ?? (int) $r['servings'];
        $short = RecipeService::cook($this->hid, $r, $servings);
        $msg = "„{$r['name']}“ für $servings " . ($servings === 1 ? 'Portion' : 'Portionen') . ' vom Vorrat abgebucht.';
        if ($short) {
            $msg .= ' Laut Vorrat war nicht genug da von: ' . implode(', ', $short) . '.';
        }
        $this->log('cook_recipe', $msg);
        return $msg;
    }

    private function recipeToShoppingList(array $a): array
    {
        $r = $this->recipe($a);
        $servings = self::servings($a) ?? (int) $r['servings'];
        $shop = new ShoppingRepository();
        $listId = self::int($a, 'list_id');
        $list = $listId
            ? ($shop->findList($listId, $this->hid) ?? throw new ToolError("Einkaufsliste $listId gibt es nicht."))
            : $shop->autoList($this->hid);
        $added = RecipeService::addMissingToList($this->hid, $r, $servings, (int) $list['id']);
        if ($added) {
            $this->log('add_recipe_to_shopping_list', "Für „{$r['name']}“ auf „{$list['name']}“: " . implode(', ', $added));
        }
        return $added
            ? ['list_id' => (int) $list['id'], 'list' => $list['name'], 'added' => $added]
            : ['list_id' => (int) $list['id'], 'list' => $list['name'], 'added' => [], 'message' => 'Es fehlt nichts – alle Zutaten sind laut Vorrat da.'];
    }

    private function createRecipe(array $a): array
    {
        $repo = $this->recipeRepo();
        $name = self::str($a, 'name');
        foreach ($repo->all($this->hid) as $r) {
            if (mb_strtolower($r['name']) === mb_strtolower($name)) {
                throw new ToolError("Ein Rezept „{$r['name']}“ gibt es schon (recipe_id {$r['id']}). Bitte einen anderen Namen wählen.");
            }
        }
        $rows = $a['ingredients'] ?? null;
        if (!is_array($rows) || !$rows) {
            throw new ToolError('Bitte mindestens eine Zutat angeben.');
        }
        $rows = array_map(fn ($row) => is_array($row) ? [
            'name' => self::str($row, 'name'), 'quantity' => (string) (self::num($row, 'quantity') ?? 1),
            'unit' => in_array(mb_strtolower(self::str($row, 'unit', 20)), ['stück', 'stk', 'st'], true) ? '' : self::str($row, 'unit', 20),
            'note' => self::str($row, 'note'),
        ] : null, array_slice($rows, 0, 60));

        $shop = new ShoppingRepository();
        $before = array_column($shop->items($this->hid), 'name', 'id');
        try {
            $ingredients = RecipeService::ingredientRows($this->hid, array_filter($rows));
        } catch (\DomainException $e) {
            throw new ToolError($e->getMessage());
        }
        if (!$ingredients) {
            throw new ToolError('Bitte mindestens eine Zutat mit Namen angeben.');
        }
        [$values, $unknown] = $this->classIds(self::strings($a, 'classes'));
        $data = [
            'name'         => $name,
            'servings'     => max(1, min(self::int($a, 'servings') ?? 2, 99)),
            'instructions' => self::str($a, 'instructions', 60000) ?: null,
            'created_by'   => $this->userId,
        ];
        $id = $repo->transaction(function () use ($repo, $data, $ingredients, $values) {
            $id = $repo->create($this->hid, $data);
            $repo->replaceIngredients($id, $ingredients);
            $repo->setClasses($id, $values);
            return $id;
        });
        $newItems = array_values(array_diff_key(array_column($shop->items($this->hid), 'name', 'id'), $before));
        $this->log('create_recipe', "Rezept „{$name}“ angelegt (" . count($ingredients) . ' Zutaten)');
        return array_filter([
            'recipe_id' => $id, 'name' => $name, 'servings' => $data['servings'], 'ingredients' => count($ingredients),
            'new_items' => $newItems, 'unknown_classes' => $unknown,
        ], fn ($v) => $v !== []);
    }

    private function recipe(array $a): array
    {
        $id = self::int($a, 'recipe_id');
        return ($id ? $this->recipeRepo()->find($id, $this->hid) : null)
            ?? throw new ToolError('Rezept nicht gefunden – search_recipes zeigt alle Rezepte.');
    }

    private function recipeRepo(): RecipeRepository
    {
        if (!$this->recipes) {
            $this->recipes = new RecipeRepository();
            $this->recipes->ensureDefaultClasses($this->hid);
        }
        return $this->recipes;
    }

    /** Wie in der Web-Oberfläche: innerhalb einer Gruppe „oder“, zwischen Gruppen „und“ */
    private function filterByClasses(array $recipes, array $groups, array $classNames): array
    {
        if (!$classNames) {
            return $recipes;
        }
        [$values, $unknown] = $this->classIds($classNames);
        if ($unknown) {
            throw new ToolError('Unbekannte Klasse: ' . implode(', ', $unknown) . '. Verfügbar: ' . implode(', ', self::classNames($groups)) . '.');
        }
        $byGroup = [];
        foreach ($groups as $g) {
            $sel = array_intersect(array_column($g['values'], 'id'), $values);
            if ($sel) {
                $byGroup[] = $sel;
            }
        }
        return array_values(array_filter($recipes, fn ($r) => array_reduce($byGroup, fn ($ok, $sel) => $ok && array_intersect($sel, $r['value_ids']), true)));
    }

    /** @return array{0:int[], 1:string[]} Klassenwert-IDs, unbekannte Namen */
    private function classIds(array $classNames): array
    {
        $index = [];
        foreach (self::classNames($this->recipeRepo()->classGroups($this->hid)) as $id => $n) {
            $index[mb_strtolower($n)] ??= $id;
        }
        $ids = [];
        $unknown = [];
        foreach ($classNames as $n) {
            $key = mb_strtolower($n);
            if (isset($index[$key])) {
                $ids[] = (int) $index[$key];
            } else {
                $unknown[] = $n;
            }
        }
        return [array_values(array_unique($ids)), $unknown];
    }

    /** @return array<int, string> Klassenwert-ID => Name */
    private static function classNames(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            foreach ($g['values'] as $v) {
                $out[$v['id']] = $v['name'];
            }
        }
        return $out;
    }

    private static function servings(array $a): ?int
    {
        $v = self::int($a, 'servings');
        return $v !== null && $v > 0 ? min($v, 99) : null;
    }

    // ---- Bargeld ---------------------------------------------------------------------------

    private function bookCashExpense(array $a): string
    {
        $account = $this->cashAccount(self::str($a, 'account', 100));
        $raw = $a['amount'] ?? null;
        $cents = is_int($raw) || is_float($raw) || is_string($raw) ? Money::parse($raw) : null;
        if ($cents === null || $cents <= 0) {
            throw new ToolError('Bitte einen positiven Betrag in Euro angeben.');
        }
        if ($cents > self::CASH_MAX_CENTS) {
            throw new ToolError('Beträge über ' . Money::format(self::CASH_MAX_CENTS) . ' bitte in der Web-App buchen.');
        }
        $description = self::str($a, 'description');
        if ($description === '') {
            throw new ToolError('Bitte angeben, wofür das Geld ausgegeben wurde.');
        }
        $date = self::str($a, 'date', 10);
        if ($date === '') {
            $date = date('Y-m-d');
        } elseif (valid_date($date) === null || $date > date('Y-m-d') || $date < date('Y-m-d', strtotime('-1 year'))) {
            throw new ToolError('Datum bitte als JJJJ-MM-TT, nicht in der Zukunft und höchstens ein Jahr zurück.');
        }
        $categoryId = $this->cashCategory(self::str($a, 'category', 100), $description);

        (new TransactionService())->create($this->hid, [
            'account_id'   => (int) $account['id'],
            'booking_date' => $date,
            'amount'       => Money::toDecimal(-$cents),
            'payee'        => $description,
            'category_id'  => $categoryId,
            'note'         => 'per Claude erfasst',
            'created_by'   => $this->userId,
        ]);
        $category = $categoryId ? ((new CategoryRepository())->find($categoryId, $this->hid)['name'] ?? null) : null;
        $msg = 'Gebucht: ' . Money::format($cents) . " „{$description}“ am " . date_de($date)
            . ' · Kategorie: ' . ($category ?? 'ohne') . " · Konto: {$account['name']}";
        $this->log('book_cash_expense', $msg);
        return $msg;
    }

    /** Kategorie per Name oder automatisch aus der Beschreibung */
    private function cashCategory(string $name, string $description): ?int
    {
        if ($name !== '') {
            return $this->categoryByName($name);
        }
        $id = (new CategorizationService($this->hid))->suggestForTransaction($description, null)['category_id'];
        return (new CategoryRepository())->validId($id, $this->hid);
    }
}
