/* Einkaufsliste und virtuelle Posten */

/** Preisspanne „0,99 € – 1,49 € / kg“ */
function shopRange(min, max, unit) {
    if (min === null || min === undefined) return '–';
    const r = min === max ? HB.money(min) : HB.money(min) + ' – ' + HB.money(max);
    return unit ? r + ' / ' + unit : r;
}

/** Suchbegriff passt, wenn alle Wörter im Text vorkommen */
function shopMatches(text, words) {
    const t = String(text || '').toLowerCase();
    return words.every((w) => t.includes(w));
}

function shoppingList(cfg) {
    return {
        listId: cfg.listId,
        entries: cfg.entries,
        items: cfg.items,
        catalog: cfg.catalog,
        q: '', qty: '1', unit: '', hl: 0, focused: false, busy: false,
        editId: null, editQty: '', editUnit: '', editNote: '',
        showDone: false,
        purchaseId: '',
        msg: { text: '', type: 'success' },

        init() {
            const sel = document.querySelector('#matchModal select');
            if (sel) this.purchaseId = sel.value;
        },

        open() { return this.entries.filter((e) => !e.done); },
        done() { return this.entries.filter((e) => e.done); },

        /** Offene Einträge nach Kategorie gruppiert (Reihenfolge kommt vom Server) */
        groups() {
            const out = [];
            for (const e of this.open()) {
                let g = out.find((x) => x.name === e.group);
                if (!g) out.push(g = { name: e.group, entries: [] });
                g.entries.push(e);
            }
            return out;
        },

        priceRange(e) { return shopRange(e.min, e.max, e.price_unit); },

        /** Vorschläge: virtuelle Posten, dann Produkte aus dem Fundus, zuletzt „als neuen Posten anlegen“ */
        suggestions() {
            const q = this.q.trim();
            if (!q) return [];
            const words = q.toLowerCase().split(/\s+/);
            const out = [];
            const shownItems = new Set();
            for (const it of this.items) {
                if (out.length >= 6) break;
                if (shopMatches(it.name + ' ' + it.products.join(' '), words)) {
                    shownItems.add(it.id);
                    out.push({ key: 'i' + it.id, type: 'item', id: it.id, label: it.name,
                        sub: it.products.length ? it.products.slice(0, 3).join(' · ') : '', price: '' });
                }
            }
            let n = 0;
            for (const p of this.catalog) {
                if (n >= 8) break;
                if (p.item_id && shownItems.has(p.item_id)) continue;
                if (shopMatches(p.name, words)) {
                    const item = p.item_id ? this.items.find((i) => i.id === p.item_id) : null;
                    out.push({ key: 'p' + p.id, type: 'product', id: p.id, label: p.name,
                        sub: (item ? '→ ' + item.name + ' · ' : '') + p.cnt + '× gekauft', price: shopRange(p.min, p.max, p.unit) });
                    n++;
                }
            }
            const exact = this.items.some((i) => i.name.toLowerCase() === q.toLowerCase());
            if (!exact) {
                out.push({ key: 'new', type: 'new', label: '„' + q + '“ als neuen Posten hinzufügen', sub: '', price: '' });
            }
            return out;
        },

        move(d) {
            const n = this.suggestions().length;
            if (n) this.hl = (this.hl + d + n) % n;
        },

        addHighlighted() {
            const s = this.suggestions();
            if (s.length) this.add(s[Math.min(this.hl, s.length - 1)]);
        },

        async add(s) {
            const data = { quantity: this.qty, unit: this.unit };
            if (s.type === 'item') data.item_id = s.id;
            else if (s.type === 'product') data.product_id = s.id;
            else data.name = this.q.trim();
            const r = await this.call('/shopping/' + this.listId + '/add', data);
            if (r) {
                this.q = ''; this.qty = '1'; this.unit = ''; this.hl = 0;
                this.$refs.search.focus();
            }
        },

        toggle(e) { this.call('/shopping/' + this.listId + '/entries/' + e.id, { done: !e.done }); },

        remove(e) { this.call('/shopping/' + this.listId + '/entries/' + e.id + '/delete', {}); },

        edit(e) {
            if (this.editId === e.id) { this.editId = null; return; }
            this.editId = e.id;
            this.editQty = String(e.quantity).replace('.', ',');
            this.editUnit = e.unit;
            this.editNote = e.note;
        },

        async saveEdit(e) {
            const r = await this.call('/shopping/' + this.listId + '/entries/' + e.id, { quantity: this.editQty, unit: this.editUnit, note: this.editNote });
            if (r) this.editId = null;
        },

        clearDone() { this.call('/shopping/' + this.listId + '/clear-done', {}); },

        async match() {
            const r = await this.call('/shopping/' + this.listId + '/match', { purchase_id: this.purchaseId });
            if (r) {
                bootstrap.Modal.getInstance(document.getElementById('matchModal'))?.hide();
                this.msg = { text: r.message, type: r.matched ? 'success' : 'info' };
                if (r.matched) this.showDone = true;
            }
        },

        /** POST an den Server; Antwort enthält die aktualisierten Einträge (und ggf. Posten) */
        async call(path, data) {
            this.busy = true;
            try {
                const r = await HB.post(path, data);
                if (r.entries) this.entries = r.entries;
                if (r.items) this.items = r.items;
                return r;
            } catch (err) {
                this.msg = { text: err.message, type: 'danger' };
                return null;
            } finally {
                this.busy = false;
            }
        },
    };
}

function shoppingItems(cfg) {
    return {
        items: cfg.items,
        catalog: cfg.catalog,
        filter: '', newName: '', error: '',
        openId: null, editName: '', pq: '',

        init() {
            // Aus der Einkaufsliste geöffnet (?open=ID): Posten aufklappen und hinscrollen
            const it = this.items.find((i) => i.id === cfg.openId);
            if (it) {
                this.toggle(it);
                this.$nextTick(() => document.getElementById('item-' + it.id)?.scrollIntoView({ block: 'center' }));
            }
        },

        filtered() {
            const words = this.filter.trim().toLowerCase().split(/\s+/).filter(Boolean);
            if (!words.length) return this.items;
            return this.items.filter((it) => shopMatches(it.name + ' ' + it.products.map((p) => p.name).join(' '), words));
        },

        priceRange(it) { return shopRange(it.min, it.max, it.price_unit); },
        range(min, max, unit) { return shopRange(min, max, unit); },

        toggle(it) {
            this.openId = this.openId === it.id ? null : it.id;
            this.editName = it.name;
            this.pq = '';
        },

        /** Produkte aus dem Fundus, die dem Posten noch nicht zugeordnet sind */
        productHits(it) {
            const words = this.pq.trim().toLowerCase().split(/\s+/).filter(Boolean);
            if (!words.length) return [];
            const linked = new Set(it.products.map((p) => p.id));
            return this.catalog.filter((p) => !linked.has(p.id) && shopMatches(p.name, words)).slice(0, 10);
        },

        async create() {
            const name = this.newName.trim();
            if (await this.call('/shopping/items', { name })) {
                this.newName = '';
                const it = this.items.find((i) => i.name.toLowerCase() === name.toLowerCase());
                if (it) { this.filter = ''; this.toggle(it); }
            }
        },

        save(it, data) { this.call('/shopping/items/' + it.id, data); },

        async link(it, p) {
            if (await this.call('/shopping/items/' + it.id + '/products', { product_id: p.id })) this.pq = '';
        },

        unlink(it, p) { this.call('/shopping/items/' + it.id + '/products/' + p.id + '/delete', {}); },

        destroy(it) {
            if (!confirm('„' + it.name + '“ löschen? Der Posten verschwindet auch von allen Einkaufslisten.')) return;
            this.call('/shopping/items/' + it.id + '/delete', {});
        },

        async call(path, data) {
            this.error = '';
            try {
                const r = await HB.post(path, data);
                if (r.items) this.items = r.items;
                return r;
            } catch (err) {
                this.error = err.message;
                return null;
            }
        },
    };
}

/** Zuordnung echter Produkte zu virtuellen Posten per Drag & Drop (am Handy: antippen, dann Ziel antippen) */
function shoppingAssign(cfg) {
    return {
        items: cfg.items,
        catalog: cfg.catalog,
        filter: '', error: '', busy: false,
        over: null,       // Ablagefläche unter dem Mauszeiger: Posten-ID, 'new' oder 'none'
        dragging: null,   // { p, from } beim Ziehen
        selected: null,   // { p, from } beim Antippen

        words() { return this.filter.trim().toLowerCase().split(/\s+/).filter(Boolean); },

        filteredItems() {
            const w = this.words();
            if (!w.length) return this.items;
            return this.items.filter((it) => shopMatches(it.name + ' ' + it.products.map((p) => p.name).join(' '), w));
        },

        unassigned() {
            const w = this.words();
            return this.catalog.filter((p) => !p.item_id && (!w.length || shopMatches(p.name, w)));
        },
        unassignedShown() { return this.unassigned().slice(0, 150); },

        range(it) { return shopRange(it.min, it.max, it.price_unit); },

        zoneClass(key) {
            return { 'assign-over': this.over === key, 'assign-target': this.selected !== null };
        },
        leave(key) { if (this.over === key) this.over = null; },
        isSelected(p, from) { return this.selected && this.selected.p.id === p.id && this.selected.from === from; },

        dragStart(ev, p, from) {
            this.dragging = { p, from };
            this.selected = null;
            ev.dataTransfer.effectAllowed = 'copyMove';
            ev.dataTransfer.setData('text/plain', p.name);
        },

        drop(ev, target) {
            this.over = null;
            const d = this.dragging;
            this.dragging = null;
            if (d) this.move(d.p, d.from, target, ev.ctrlKey || ev.metaKey);
        },

        tapChip(p, from) {
            this.selected = this.isSelected(p, from) ? null : { p, from };
        },

        tapZone(target) {
            if (!this.selected) return;
            const s = this.selected;
            this.selected = null;
            this.move(s.p, s.from, target, false);
        },

        /** Verschieben (copy = in der Quelle lassen); target: Posten-ID, 'new' oder 'none' */
        async move(p, from, target, copy) {
            if (target === from || (target === 'none' && from === null)) return;
            this.busy = true;
            this.error = '';
            try {
                const r = await HB.post('/shopping/assign', {
                    product_id: p.id,
                    from_item_id: copy ? '' : (from || ''),
                    to_item_id: target === 'none' ? '' : target,
                });
                this.items = r.items;
                this.catalog = r.catalog;
            } catch (err) {
                this.error = err.message;
            } finally {
                this.busy = false;
            }
        },
    };
}
