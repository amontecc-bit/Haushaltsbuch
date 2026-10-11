/* Rezepte, Rezeptvorschläge und Klassifikation */

let recipeKey = 0;

/** „1 Portion“, „4 Portionen“ */
function portions(n) { return n + (n === 1 ? ' Portion' : ' Portionen'); }

/** Rezeptliste: Suche und Filter nach Klassenwerten (innerhalb einer Gruppe „oder“, zwischen Gruppen „und“) */
function recipeIndex(cfg) {
    return {
        recipes: cfg.recipes,
        groups: cfg.groups,
        filter: '',
        selected: [],

        has(v) { return this.selected.includes(v); },

        toggleValue(g, v) {
            this.selected = this.has(v) ? this.selected.filter((x) => x !== v) : [...this.selected, v];
        },

        filtered() {
            const words = this.filter.trim().toLowerCase().split(/\s+/).filter(Boolean);
            return this.recipes.filter((r) => {
                if (!words.every((w) => r.name.toLowerCase().includes(w))) return false;
                return this.groups.every((ids) => {
                    const sel = ids.filter((id) => this.selected.includes(id));
                    return !sel.length || sel.some((id) => r.values.includes(id));
                });
            });
        },
    };
}

/** Vorschläge: zuerst die machbaren, „Weitere Vorschläge“ blendet seitenweise mehr ein */
function recipeSuggestions(cfg) {
    const complete = cfg.recipes.filter((r) => r.complete).length;
    return {
        recipes: cfg.recipes,
        lists: cfg.lists,
        page: cfg.page,
        listId: cfg.lists.length ? String(cfg.lists[0].id) : 'auto',
        limit: Math.max(complete, Math.min(3, cfg.recipes.length)),
        openId: null,

        shown() { return this.recipes.slice(0, this.limit); },
        more() { return Math.max(0, this.recipes.length - this.limit); },

        summary() {
            const n = this.recipes.filter((r) => r.complete).length;
            return n
                ? (n === 1 ? '1 Rezept' : n + ' Rezepte') + ' mit dem Vorrat machbar.'
                : 'Mit dem aktuellen Vorrat ist kein Rezept vollständig machbar – hier die nächstbesten.';
        },
    };
}

/** Rezeptformular mit Zutatenzeilen (virtuelle Posten) */
function recipeForm(cfg) {
    const row = (r) => Object.assign({ key: ++recipeKey, item_id: null, name: '', quantity: '', unit: '', note: '' }, r || {});
    return {
        id: cfg.id,
        name: cfg.name,
        servings: cfg.servings,
        instructions: cfg.instructions,
        values: cfg.values.slice(),
        ingredients: cfg.ingredients.length ? cfg.ingredients.map(row) : [row()],
        items: cfg.items,
        units: cfg.units,
        sugIdx: null, busy: false, error: '',

        toggleValue(v) {
            this.values = this.values.includes(v) ? this.values.filter((x) => x !== v) : [...this.values, v];
        },

        addRow() { this.ingredients.push(row()); },

        itemOf(r) {
            if (r.item_id) return this.items.find((i) => i.id === r.item_id) || null;
            const n = r.name.trim().toLowerCase();
            return n ? this.items.find((i) => i.name.toLowerCase() === n) || null : null;
        },

        hits(r) {
            const words = r.name.trim().toLowerCase().split(/\s+/).filter(Boolean);
            if (!words.length) return [];
            return this.items.filter((i) => words.every((w) => i.name.toLowerCase().includes(w))).slice(0, 8);
        },

        pick(r, it) {
            r.item_id = it.id;
            r.name = it.name;
            if (!this.unitsFor(r).includes(r.unit)) r.unit = it.ref_unit === 'kg' ? 'g' : (it.ref_unit === 'l' ? 'ml' : it.ref_unit);
            this.sugIdx = null;
        },

        /** Einheiten passend zur Referenz des Postens (neue Posten und Grundvorrat: alle) */
        unitsFor(r) {
            const it = this.itemOf(r);
            if (!it || it.staple) return Object.keys(this.units);
            if (it.ref_unit === 'g' || it.ref_unit === 'kg') return ['g', 'kg'];
            if (it.ref_unit === 'ml' || it.ref_unit === 'l') return ['ml', 'l'];
            return [''];
        },

        async save() {
            this.busy = true;
            this.error = '';
            try {
                const r = await HB.post(this.id ? '/recipes/' + this.id : '/recipes', {
                    name: this.name, servings: this.servings, instructions: this.instructions, values: this.values,
                    ingredients: this.ingredients.filter((i) => i.name.trim() !== '')
                        .map((i) => ({ item_id: i.item_id || (this.itemOf(i) || {}).id || '', name: i.name, quantity: i.quantity, unit: i.unit, note: i.note })),
                });
                location.href = r.redirect;
            } catch (err) {
                this.error = err.message;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } finally {
                this.busy = false;
            }
        },
    };
}

/** Klassifikation: Gruppen mit Werten bearbeiten */
function recipeClasses(cfg) {
    const val = (v) => ({ key: ++recipeKey, id: v ? v.id : 0, name: v ? v.name : '' });
    return {
        groups: cfg.groups.map((g) => ({ key: ++recipeKey, id: g.id, name: g.name, values: g.values.map(val) })),
        busy: false, error: '',

        addGroup() { this.groups.push({ key: ++recipeKey, id: 0, name: '', values: [val()] }); },
        addValue(g) { g.values.push(val()); },

        removeGroup(i) {
            if (this.groups[i].id && !confirm('Gruppe löschen? Die Zuordnung der Rezepte zu ihren Werten geht verloren.')) return;
            this.groups.splice(i, 1);
        },

        async save() {
            this.busy = true;
            this.error = '';
            try {
                const r = await HB.post('/recipes/classes', {
                    groups: this.groups.map((g) => ({ id: g.id, name: g.name, values: g.values.map((v) => ({ id: v.id, name: v.name })) })),
                });
                location.href = r.redirect;
            } catch (err) {
                this.error = err.message;
            } finally {
                this.busy = false;
            }
        },
    };
}
