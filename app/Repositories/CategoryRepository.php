<?php

declare(strict_types=1);

namespace App\Repositories;

final class CategoryRepository extends Repository
{
    /** Flache Liste, Eltern vor Kindern sortiert, mit parent_name und full_name */
    public function all(int $householdId, ?string $type = null): array
    {
        $params = [$householdId];
        $typeSql = '';
        if ($type) {
            $typeSql = 'AND c.type = ?';
            $params[] = $type;
        }
        $rows = $this->many(
            "SELECT c.*, p.name AS parent_name,
                    COALESCE(p.sort_order, c.sort_order) AS grp_sort, COALESCE(p.name, c.name) AS grp_name
             FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
             WHERE c.household_id = ? $typeSql
             ORDER BY c.type, grp_sort, grp_name, c.parent_id IS NOT NULL, c.sort_order, c.name",
            $params
        );
        foreach ($rows as &$r) {
            $r['full_name'] = $r['parent_name'] ? $r['parent_name'] . ' › ' . $r['name'] : $r['name'];
        }
        return $rows;
    }

    /** Hierarchisch: Hauptkategorien mit 'children' */
    public function tree(int $householdId): array
    {
        $all = $this->all($householdId);
        $byId = [];
        foreach ($all as $c) {
            if (!$c['parent_id']) {
                $byId[$c['id']] = $c + ['children' => []];
            }
        }
        foreach ($all as $c) {
            if ($c['parent_id'] && isset($byId[$c['parent_id']])) {
                $byId[$c['parent_id']]['children'][] = $c;
            }
        }
        return array_values($byId);
    }

    public function find(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM categories WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    /** Prüft, ob eine Kategorie-ID zum Haushalt gehört; gibt sonst null zurück */
    public function validId(?int $id, int $householdId): ?int
    {
        if (!$id) {
            return null;
        }
        return $this->value('SELECT id FROM categories WHERE id = ? AND household_id = ?', [$id, $householdId]) ? $id : null;
    }

    public function create(int $householdId, array $data): int
    {
        return $this->insert('categories', ['household_id' => $householdId] + $data);
    }

    public function update(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('categories', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM categories WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function usage(int $id): int
    {
        return (int) $this->value(
            'SELECT (SELECT COUNT(*) FROM transactions WHERE category_id = ?) + (SELECT COUNT(*) FROM purchase_items WHERE category_id = ?)',
            [$id, $id]
        );
    }

    /** Buchungen/Posten auf andere Kategorie umhängen (vor dem Löschen) */
    public function reassign(int $from, ?int $to): void
    {
        $this->exec('UPDATE transactions SET category_id = ? WHERE category_id = ?', [$to, $from]);
        $this->exec('UPDATE purchase_items SET category_id = ? WHERE category_id = ?', [$to, $from]);
        $this->exec('UPDATE recurring_transactions SET category_id = ? WHERE category_id = ?', [$to, $from]);
    }

    /**
     * Standard-Kategorien für einen neuen Haushalt.
     * Format: [Name, Typ, Icon, Farbe, [Unterkategorien]]
     * Farben: die acht häufigsten Ausgabenbereiche erhalten je einen Ton der validierten Diagramm-Palette,
     * seltenere Bereiche neutrale Grautöne (in Diagrammen ohnehin meist unter „Übrige“).
     */
    public function seedDefaults(int $householdId): void
    {
        $defaults = [
            ['Lebensmittel', 'expense', 'basket', '#1baf7a', ['Obst & Gemüse', 'Brot & Backwaren', 'Milchprodukte & Eier', 'Fleisch & Fisch', 'Tiefkühl', 'Vorrat & Konserven', 'Süßes & Snacks', 'Getränke', 'Alkohol', 'Fertiggerichte']],
            ['Drogerie & Haushalt', 'expense', 'droplet', '#e87ba4', ['Körperpflege', 'Reinigung', 'Haushaltswaren', 'Baby & Kind', 'Tierbedarf']],
            ['Wohnen', 'expense', 'house', '#2a78d6', ['Miete / Rate', 'Nebenkosten', 'Strom', 'Heizung / Gas', 'Wasser', 'Internet & Telefon', 'Rundfunkbeitrag', 'Einrichtung', 'Reparaturen']],
            ['Mobilität', 'expense', 'car-front', '#4a3aa7', ['Tanken / Laden', 'Werkstatt', 'KFZ-Versicherung', 'KFZ-Steuer', 'ÖPNV', 'Parken']],
            ['Versicherungen', 'expense', 'shield-check', '#eda100', ['Haftpflicht', 'Hausrat', 'Kranken-Zusatz', 'Leben / BU']],
            ['Gesundheit', 'expense', 'heart-pulse', '#e34948', ['Apotheke', 'Arzt', 'Brille / Optiker']],
            ['Freizeit', 'expense', 'controller', '#eb6834', ['Restaurant & Café', 'Hobby', 'Sport', 'Urlaub', 'Kultur & Kino', 'Streaming & Abos']],
            ['Kleidung', 'expense', 'bag', '#a3a29c', []],
            ['Bildung & Kinder', 'expense', 'mortarboard', '#8a8984', ['Schule / Kita', 'Taschengeld', 'Bücher']],
            ['Elektronik', 'expense', 'phone', '#bdbcb6', []],
            ['Geschenke & Spenden', 'expense', 'gift', '#6f6e69', []],
            ['Finanzen', 'expense', 'bank', '#008300', ['Kreditrate', 'Gebühren', 'Zinsen', 'Steuern']],
            ['Sonstiges', 'expense', 'three-dots', '#c9c8c2', ['Pfand']],
            ['Gehalt', 'income', 'cash-stack', '#2a78d6', []],
            ['Kindergeld', 'income', 'people', '#1baf7a', []],
            ['Sonstige Einnahmen', 'income', 'plus-circle', '#eda100', ['Erstattungen', 'Zinserträge', 'Verkäufe', 'Geschenke erhalten']],
        ];
        foreach ($defaults as $i => [$name, $type, $icon, $color, $children]) {
            $pid = $this->create($householdId, ['name' => $name, 'type' => $type, 'icon' => $icon, 'color' => $color, 'sort_order' => $i]);
            foreach ($children as $j => $child) {
                $this->create($householdId, ['parent_id' => $pid, 'name' => $child, 'type' => $type, 'icon' => $icon, 'color' => $color, 'sort_order' => $j]);
            }
        }
    }

    /** name (klein) => id, inkl. "Eltern › Kind"-Schreibweise */
    public function nameIndex(int $householdId): array
    {
        $idx = [];
        foreach ($this->all($householdId) as $c) {
            $idx[mb_strtolower($c['name'])] ??= (int) $c['id'];
            $idx[mb_strtolower($c['full_name'])] = (int) $c['id'];
        }
        return $idx;
    }
}
