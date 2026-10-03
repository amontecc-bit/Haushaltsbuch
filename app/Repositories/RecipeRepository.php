<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Rezepte mit Stückliste (virtuelle Posten) und Klassifikation (Gruppen mit Werten, z. B. Mahlzeit: morgens).
 */
final class RecipeRepository extends Repository
{
    /** Standard-Klassifikation für neue Haushalte */
    public const DEFAULT_CLASSES = ['Mahlzeit' => ['morgens', 'mittags', 'abends']];

    // ---- Rezepte ---------------------------------------------------------------------

    /** Alle Rezepte mit Anzahl Zutaten und Klassenwert-IDs */
    public function all(int $householdId): array
    {
        $rows = $this->many(
            'SELECT r.*, (SELECT COUNT(*) FROM recipe_ingredients i WHERE i.recipe_id = r.id) AS ingredient_count,
                    (SELECT GROUP_CONCAT(l.value_id) FROM recipe_class_links l WHERE l.recipe_id = r.id) AS value_ids
             FROM recipes r WHERE r.household_id = ? ORDER BY r.name',
            [$householdId]
        );
        foreach ($rows as &$r) {
            $r['value_ids'] = $r['value_ids'] ? array_map('intval', explode(',', $r['value_ids'])) : [];
        }
        return $rows;
    }

    public function find(int $id, int $householdId): ?array
    {
        $r = $this->one('SELECT * FROM recipes WHERE id = ? AND household_id = ?', [$id, $householdId]);
        if ($r) {
            $r['value_ids'] = array_map('intval', array_column(
                $this->many('SELECT value_id FROM recipe_class_links WHERE recipe_id = ?', [$id]), 'value_id'
            ));
        }
        return $r;
    }

    public function create(int $householdId, array $data): int
    {
        return $this->insert('recipes', ['household_id' => $householdId] + $data);
    }

    public function update(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('recipes', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM recipes WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    // ---- Zutaten ---------------------------------------------------------------------

    /**
     * Zutaten mit Referenz und Grundvorrat-Kennzeichen des virtuellen Postens.
     * @return array<int, array[]> Rezept-ID => Zutaten
     */
    public function ingredients(array $recipeIds): array
    {
        if (!$recipeIds) {
            return [];
        }
        $recipeIds = array_values($recipeIds);
        $out = [];
        foreach ($this->many(
            'SELECT i.*, s.name, s.ref_quantity, s.ref_unit, s.staple
             FROM recipe_ingredients i JOIN shopping_items s ON s.id = i.shopping_item_id
             WHERE i.recipe_id IN (' . self::in($recipeIds) . ') ORDER BY i.recipe_id, i.sort_order, i.id',
            $recipeIds
        ) as $r) {
            $out[(int) $r['recipe_id']][] = $r;
        }
        return $out;
    }

    /** @param array<int, array{shopping_item_id:int, quantity:float, unit:?string, note:?string}> $rows */
    public function replaceIngredients(int $recipeId, array $rows): void
    {
        $this->exec('DELETE FROM recipe_ingredients WHERE recipe_id = ?', [$recipeId]);
        foreach (array_values($rows) as $n => $r) {
            $this->insert('recipe_ingredients', [
                'recipe_id' => $recipeId, 'shopping_item_id' => $r['shopping_item_id'], 'quantity' => $r['quantity'],
                'unit' => $r['unit'], 'note' => $r['note'], 'sort_order' => $n,
            ]);
        }
    }

    // ---- Klassifikation ---------------------------------------------------------------

    /** @return array<int, array{id:int, name:string, values: array<int, array{id:int, name:string}>}> */
    public function classGroups(int $householdId): array
    {
        $groups = [];
        foreach ($this->many(
            'SELECT g.id AS gid, g.name AS gname, v.id AS vid, v.name AS vname
             FROM recipe_class_groups g LEFT JOIN recipe_class_values v ON v.group_id = g.id
             WHERE g.household_id = ? ORDER BY g.sort_order, g.id, v.sort_order, v.id',
            [$householdId]
        ) as $r) {
            $gid = (int) $r['gid'];
            $groups[$gid] ??= ['id' => $gid, 'name' => $r['gname'], 'values' => []];
            if ($r['vid'] !== null) {
                $groups[$gid]['values'][] = ['id' => (int) $r['vid'], 'name' => $r['vname']];
            }
        }
        return array_values($groups);
    }

    /**
     * Standardgruppe anlegen, solange der Haushalt weder Klassifikation noch Rezepte hat
     * (wer später alle Gruppen löscht, bekommt sie nicht zurück).
     */
    public function ensureDefaultClasses(int $householdId): void
    {
        if ($this->value(
            'SELECT 1 FROM recipe_class_groups WHERE household_id = ? UNION ALL SELECT 1 FROM recipes WHERE household_id = ? LIMIT 1',
            [$householdId, $householdId]
        )) {
            return;
        }
        $g = 0;
        foreach (self::DEFAULT_CLASSES as $group => $values) {
            $gid = $this->insert('recipe_class_groups', ['household_id' => $householdId, 'name' => $group, 'sort_order' => $g++]);
            foreach ($values as $n => $v) {
                $this->insert('recipe_class_values', ['group_id' => $gid, 'name' => $v, 'sort_order' => $n]);
            }
        }
    }

    /** Nur Klassenwerte des Haushalts durchlassen */
    public function validValueIds(int $householdId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        return array_map('intval', array_column($this->many(
            'SELECT v.id FROM recipe_class_values v JOIN recipe_class_groups g ON g.id = v.group_id
             WHERE g.household_id = ? AND v.id IN (' . self::in($ids) . ')',
            [$householdId, ...$ids]
        ), 'id'));
    }

    public function setClasses(int $recipeId, array $valueIds): void
    {
        $this->exec('DELETE FROM recipe_class_links WHERE recipe_id = ?', [$recipeId]);
        foreach ($valueIds as $vid) {
            $this->exec('INSERT IGNORE INTO recipe_class_links (recipe_id, value_id) VALUES (?, ?)', [$recipeId, $vid]);
        }
    }

    /**
     * Klassifikation komplett speichern. Gruppen/Werte mit id werden umbenannt, ohne id angelegt,
     * fehlende gelöscht (samt Zuordnung zu Rezepten).
     * @param array<int, array{id?:int, name:string, values: array<int, array{id?:int, name:string}>}> $groups
     */
    public function saveClasses(int $householdId, array $groups): void
    {
        $existing = [];
        foreach ($this->classGroups($householdId) as $g) {
            $existing[$g['id']] = array_column($g['values'], 'id');
        }
        $keepGroups = [];
        foreach (array_values($groups) as $gi => $g) {
            $gid = (int) ($g['id'] ?? 0);
            if ($gid && isset($existing[$gid])) {
                $this->updateWhere('recipe_class_groups', ['name' => $g['name'], 'sort_order' => $gi], ['id' => $gid, 'household_id' => $householdId]);
            } else {
                $gid = $this->insert('recipe_class_groups', ['household_id' => $householdId, 'name' => $g['name'], 'sort_order' => $gi]);
                $existing[$gid] = [];
            }
            $keepGroups[] = $gid;
            $keepValues = [];
            foreach (array_values($g['values']) as $vi => $v) {
                $vid = (int) ($v['id'] ?? 0);
                if ($vid && in_array($vid, $existing[$gid], true)) {
                    $this->updateWhere('recipe_class_values', ['name' => $v['name'], 'sort_order' => $vi], ['id' => $vid, 'group_id' => $gid]);
                } else {
                    $vid = $this->insert('recipe_class_values', ['group_id' => $gid, 'name' => $v['name'], 'sort_order' => $vi]);
                }
                $keepValues[] = $vid;
            }
            $this->exec(
                'DELETE FROM recipe_class_values WHERE group_id = ?' . ($keepValues ? ' AND id NOT IN (' . self::in($keepValues) . ')' : ''),
                [$gid, ...$keepValues]
            );
        }
        $this->exec(
            'DELETE FROM recipe_class_groups WHERE household_id = ?' . ($keepGroups ? ' AND id NOT IN (' . self::in($keepGroups) . ')' : ''),
            [$householdId, ...$keepGroups]
        );
    }
}
