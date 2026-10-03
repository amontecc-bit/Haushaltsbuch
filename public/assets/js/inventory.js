/* Vorrat je virtuellem Posten */

/** "2026-10-03" → "03.10.2026" */
function invDate(d) {
    if (!d) return '';
    const [y, m, day] = String(d).split('-');
    return day + '.' + m + '.' + y;
}

const INV_STATUS = {
    ok: ['text-bg-success', 'vorrätig'],
    low: ['text-bg-warning', 'knapp'],
    out: ['text-bg-danger', 'aufgebraucht'],
    expired: ['text-bg-danger', 'abgelaufen'],
    staple: ['text-bg-secondary', 'Grundvorrat'],
    none: ['text-bg-light border', 'kein Vorrat'],
};

function inventoryList(cfg) {
    return {
        items: cfg.items,
        filter: '', showAll: false, error: '', busy: false,
        openId: null, setValue: '',

        filtered() {
            const words = this.filter.trim().toLowerCase().split(/\s+/).filter(Boolean);
            return this.items
                .filter((it) => (this.showAll || words.length || it.status !== 'none'))
                .filter((it) => words.every((w) => it.name.toLowerCase().includes(w)))
                .sort((a, b) => a.sort - b.sort || a.name.localeCompare(b.name, 'de'));
        },

        badge(status) { return INV_STATUS[status] || INV_STATUS.none; },

        sub(it) {
            const parts = [];
            if (it.status === 'expired' && it.expired_on) parts.push('abgelaufen am ' + invDate(it.expired_on));
            if (it.next_expiry) parts.push('nächster Ablauf ' + invDate(it.next_expiry));
            if (it.min_label) parts.push('Mindestbestand ' + it.min_label);
            if (!it.auto && it.status !== 'staple') parts.push('nicht automatisch nachkaufen');
            return parts.join(' · ') || ('Referenz ' + it.ref_label);
        },

        toggle(it) {
            this.openId = this.openId === it.id ? null : it.id;
            this.setValue = String(it.stock).replace('.', ',');
        },

        async adjust(it, data) {
            if (await this.call('/inventory/' + it.id, data)) {
                const fresh = this.items.find((i) => i.id === it.id);
                if (fresh) this.setValue = String(fresh.stock).replace('.', ',');
            }
        },

        updateLot(l, data) { this.call('/inventory/lots/' + l.id, data); },

        deleteLot(l) {
            if (!confirm('Charge löschen? Der Bestand sinkt um den Rest dieser Charge.')) return;
            this.call('/inventory/lots/' + l.id + '/delete', {});
        },

        async call(path, data) {
            this.busy = true;
            this.error = '';
            try {
                const r = await HB.post(path, data);
                if (r.items) this.items = r.items;
                return r;
            } catch (err) {
                this.error = err.message;
                return null;
            } finally {
                this.busy = false;
            }
        },
    };
}
