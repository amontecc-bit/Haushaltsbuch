/* Wunschliste: Formular mit Sparplan-Vorschau (gleiche Rechnung wie WishService::plan) */
function wishForm(init) {
    const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    const cents = (v) => Math.round(Math.abs(HB.parseAmount(v)) * 100);

    /** k-te Rate ab Start (Tag des Starts, am Monatsende geklemmt) als 'YYYY-MM-DD' */
    function rateDate(start, k) {
        const y = +start.slice(0, 4), m = +start.slice(5, 7) - 1 + k, day = +start.slice(8, 10);
        const yy = y + Math.floor(m / 12), mm = m % 12;
        const dim = new Date(yy, mm + 1, 0).getDate();
        return yy + '-' + String(mm + 1).padStart(2, '0') + '-' + String(Math.min(day, dim)).padStart(2, '0');
    }
    function ratesUntil(start, date) {
        if (date < start) return 0;
        const n = (+date.slice(0, 4) - +start.slice(0, 4)) * 12 + +date.slice(5, 7) - +start.slice(5, 7);
        return rateDate(start, n) <= date ? n + 1 : n;
    }
    const de = (d) => d.slice(8, 10) + '.' + d.slice(5, 7) + '.' + d.slice(0, 4);

    return {
        ...init,
        imageUrl: null,

        get selected() {
            return this.posten.find((p) => String(p.id) === String(this.postenId)) || null;
        },
        get lockedAccount() {
            return !!(this.selected && this.selected.income_account_id);
        },
        get rateCents() { return cents(this.rate); },
        get overBy() {
            return this.selected ? this.rateCents - this.selected.free : 0;
        },
        get late() {
            return !!this.reachDate && !!this.wantedBy && this.reachDate > this.wantedBy;
        },
        get reachDate() {
            const price = cents(this.price), saved = cents(this.saved), rate = this.rateCents;
            if (!price || !this.start || saved >= price || !rate) return null;
            const needed = Math.ceil((price - saved) / rate);
            return needed > 600 ? null : rateDate(this.start, needed - 1);
        },
        get preview() {
            const price = cents(this.price), saved = cents(this.saved), rate = this.rateCents;
            if (!price) return rate ? 'Ohne Preis lässt sich kein Zieldatum berechnen.' : '';
            if (saved >= price) return 'Der Betrag ist bereits angespart.';
            if (!rate) return '';
            const d = this.reachDate;
            if (!d) return 'Mit dieser Rate dauert es über 50 Jahre.';
            let text = 'Hältst du den Sparplan ein, ist der Betrag am ' + de(d) + ' (' + MONTHS[+d.slice(5, 7) - 1] + ' ' + d.slice(0, 4) + ') zusammen.';
            if (this.late) {
                const n = ratesUntil(this.start, this.wantedBy);
                text += ' Das ist nach dem Wunschtermin' + (n > 0 ? ' – dafür wären ' + this.fmt(Math.ceil((price - saved) / n)) + ' im Monat nötig.' : '.');
            }
            return text;
        },
        fmt(c) { return HB.money(c / 100); },

        postenChanged() {
            if (this.selected && this.selected.income_account_id) {
                this.accountId = String(this.selected.income_account_id);
            }
            // Rate vorschlagen: was beim Posten noch frei ist
            if (this.selected && !this.rateCents && this.selected.free > 0) {
                this.rate = (this.selected.free / 100).toFixed(2).replace('.', ',');
            }
        },
        showImage(ev) {
            const f = ev.target.files && ev.target.files[0];
            if (this.imageUrl) URL.revokeObjectURL(this.imageUrl);
            this.imageUrl = f ? URL.createObjectURL(f) : null;
        },
        init() {
            if (this.selected) this.postenChanged();
        },
    };
}
