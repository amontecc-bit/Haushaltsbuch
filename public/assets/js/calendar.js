/* Kalender: Terminformular (Vorlagen übernehmen, Ende folgt dem Beginn, Dauer anzeigen) */

/** Minuten lesbar wie CalendarService::formatDuration(): „45 Min.“, „1 Std. 30 Min.“, „2 Tage“ */
function calDuration(min) {
    if (!min || min <= 0) return '';
    const d = Math.floor(min / 1440), h = Math.floor((min % 1440) / 60), m = min % 60;
    const parts = [];
    if (d) parts.push(d + (d === 1 ? ' Tag' : ' Tage'));
    if (h) parts.push(h + ' Std.');
    if (m) parts.push(m + ' Min.');
    return parts.join(' ');
}

function calPad(n) { return String(n).padStart(2, '0'); }
function calDate(d) { return d.getFullYear() + '-' + calPad(d.getMonth() + 1) + '-' + calPad(d.getDate()); }
function calTime(d) { return calPad(d.getHours()) + ':' + calPad(d.getMinutes()); }

function calendarForm(cfg) {
    return {
        ...cfg.event,
        budget: cfg.budget,
        budgetAccount: cfg.budgetAccount,
        budgetCategory: cfg.budgetCategory,
        templates: cfg.templates,
        durationMin: null,
        tplTitle: null,

        init() {
            this.durationMin = this.diff();
            // Beginn verschoben → Ende mit gleicher Dauer mitziehen; Ende geändert → neue Dauer merken
            this.$watch('startDate', () => this.shift());
            this.$watch('startTime', () => this.shift());
            this.$watch('endDate', () => { this.durationMin = this.diff(); });
            this.$watch('endTime', () => { this.durationMin = this.diff(); });
        },

        start() { return new Date(this.startDate + 'T' + (this.allDay ? '00:00' : (this.startTime || '00:00'))); },
        end() { return new Date((this.endDate || this.startDate) + 'T' + (this.allDay ? '00:00' : (this.endTime || '00:00'))); },

        /** Dauer in Minuten (ganztägig: Abstand der Tage, 0 = ein Tag) */
        diff() {
            const ms = this.end() - this.start();
            return isNaN(ms) ? null : Math.round(ms / 60000);
        },

        shift() {
            if (this.durationMin === null || !this.startDate) return;
            const e = new Date(this.start().getTime() + this.durationMin * 60000);
            if (isNaN(e)) return;
            this.endDate = calDate(e);
            if (!this.allDay) this.endTime = calTime(e);
        },

        durationText() {
            const m = this.diff();
            if (m === null) return '';
            if (m < 0 || (!this.allDay && m === 0)) return 'Das Ende liegt vor dem Beginn.';
            return 'Dauer: ' + (this.allDay ? calDuration(m + 1440) : calDuration(m));
        },

        applyTemplate(id) {
            const t = this.templates.find(x => x.id === String(id));
            this.templateId = t ? t.id : '';
            if (!t) return;
            const suggested = t.title || t.name;
            if (!this.title || this.title === this.tplTitle) this.title = suggested;
            this.tplTitle = suggested;
            if (t.location && !this.location) this.location = t.location;
            this.allDay = t.allDay;
            this.lead = calDuration(t.lead);
            this.trail = calDuration(t.trail);
            this.color = t.color;
            this.durationMin = t.allDay ? Math.max(0, t.duration - 1440) : t.duration;
            this.shift();
            if (t.budget) {
                this.budget = t.budget;
                this.budgetAccount = t.budgetAccount;
                this.budgetCategory = t.budgetCategory;
            }
        },
    };
}

/* Zeitraster (Tag/Woche): eigene Termine mit der Maus bzw. dem Finger ändern.
   - Ziehen am Termin verschiebt ihn samt Vor-/Nachlauf (Woche: auch auf einen anderen Tag), 15-Minuten-Raster
   - Griffe oben/unten am Termin ändern Beginn bzw. Ende, Griffe an Vor-/Nachlauf deren Länge (5-Minuten-Raster)
   - Touch: Termin kurz gedrückt halten, dann ziehen (Griffe sofort); Esc bricht ab
   - Serien: Rückfrage „Nur diesen Termin“ / „Ganze Serie“; gespeichert wird per POST /calendar/{id}/move */
document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.cal-tg').forEach(calTimegrid));

function calTimegrid(tg) {
    const scroller = tg.querySelector('.cal-tg-scroll');
    const body = tg.querySelector('.cal-tg-body');
    const sticky = tg.querySelector('.cal-tg-sticky');
    const pxPerMin = () => body.getBoundingClientRect().height / 1440;
    // Beim Öffnen auf 7 Uhr bzw. kurz vor den ersten Termin scrollen
    scroller.scrollTop = (+tg.dataset.scroll || 0) * pxPerMin();

    const fmt = (m) => calPad(Math.floor(m / 60)) + ':' + calPad(m % 60);
    const snap = (v, step) => Math.round(v / step) * step;
    const clamp = (v, a, b) => Math.min(Math.max(v, a), b);
    const pct = (m) => (m / 1440 * 100) + '%';
    const info = (b) => b.calData || (b.calData = JSON.parse(b.dataset.drag));
    const colAt = (x) => [...tg.querySelectorAll('.cal-tg-col')].find((c) => {
        const r = c.getBoundingClientRect();
        return x >= r.left && x < r.right;
    });
    let drag = null;
    let suppressClick = false;

    tg.addEventListener('pointerdown', (e) => {
        if (drag || e.button !== 0) return;
        const block = e.target.closest('.cal-ev-drag');
        if (!block) return;
        const d = info(block);
        const handle = e.target.closest('.cal-h');
        const blocks = [...tg.querySelectorAll('.cal-ev-drag')].filter((b) => info(b).occ === d.occ);
        drag = {
            d, blocks, mode: handle ? handle.dataset.h : 'move', pointerId: e.pointerId, touch: e.pointerType === 'touch',
            col: block.closest('.cal-tg-col'), x0: e.clientX, y0: e.clientY, lastX: e.clientX, lastY: e.clientY, s0: scroller.scrollTop,
            now: { start: d.start, end: d.end, lead: d.lead, trail: d.trail }, active: false,
            saved: blocks.map((b) => [b, b.getAttribute('style'), b.parentNode, b.querySelector('.cal-ev-time')?.innerHTML]),
        };
        drag.origCol = drag.col;
        if (!drag.touch) e.preventDefault(); // keine Textauswahl beim Ziehen mit der Maus
        if (handle) {
            begin();
        } else if (drag.touch) {
            // Touch: erst nach kurzem Halten ziehen, sonst normal scrollen
            drag.timer = setTimeout(() => { if (drag && !drag.active) { begin(); navigator.vibrate && navigator.vibrate(20); } }, 350);
        }
    });

    window.addEventListener('pointermove', (e) => {
        if (!drag || e.pointerId !== drag.pointerId) return;
        drag.lastX = e.clientX;
        drag.lastY = e.clientY;
        if (!drag.active) {
            const dist = Math.hypot(e.clientX - drag.x0, e.clientY - drag.y0);
            if (drag.touch) { if (dist > 8) reset(); return; }
            if (dist < 4) return;
            begin();
        }
        update();
    });
    window.addEventListener('pointerup', (e) => {
        if (!drag || e.pointerId !== drag.pointerId) return;
        if (!drag.active) { reset(); return; } // normaler Klick → Link öffnet den Termin
        suppressClick = true;
        setTimeout(() => { suppressClick = false; }, 300);
        finish();
    });
    window.addEventListener('pointercancel', (e) => { if (drag && e.pointerId === drag.pointerId) restore(); });
    window.addEventListener('keydown', (e) => { if (e.key === 'Escape' && drag && drag.active) restore(); });
    tg.addEventListener('click', (e) => {
        if (suppressClick) { e.preventDefault(); e.stopPropagation(); suppressClick = false; }
    }, true);
    tg.addEventListener('contextmenu', (e) => { if (e.target.closest('.cal-ev-drag')) e.preventDefault(); });
    document.addEventListener('touchmove', (e) => { if (drag && drag.active) e.preventDefault(); }, { passive: false });

    function begin() {
        drag.active = true;
        clearTimeout(drag.timer);
        tg.classList.add('cal-tg-busy');
        drag.blocks.forEach((b) => b.classList.add('cal-dragging'));
        drag.label = document.createElement('div');
        drag.label.className = 'cal-drag-label';
        place();
        autoScroll();
    }

    /** Neue Zeiten aus der Zeigerposition (inkl. gescrollter Strecke) */
    function update() {
        const d = drag.d, n = drag.now;
        const dm = (drag.lastY - drag.y0 + scroller.scrollTop - drag.s0) / pxPerMin();
        const dur = d.end - d.start;
        if (drag.mode === 'move') {
            n.start = clamp(snap(d.start + dm, 15), d.lead, 1440 - dur - d.trail);
            n.end = n.start + dur;
            const col = colAt(drag.lastX);
            if (col && col !== drag.col) {
                drag.col = col;
                drag.blocks.forEach((b) => col.appendChild(b));
            }
        } else if (drag.mode === 'start') {
            n.start = clamp(snap(d.start + dm, 15), d.lead, d.end - 15);
        } else if (drag.mode === 'end') {
            n.end = clamp(snap(d.end + dm, 15), d.start + 15, 1440 - d.trail);
        } else if (drag.mode === 'lead') {
            n.lead = clamp(snap(d.lead - dm, 5), 0, d.start);
        } else if (drag.mode === 'trail') {
            n.trail = clamp(snap(d.trail + dm, 5), 0, 1440 - d.end);
        }
        place();
    }

    /** Vorschau: Blöcke an die neue Lage setzen (während des Ziehens volle Spaltenbreite) */
    function place() {
        const n = drag.now;
        for (const b of drag.blocks) {
            const k = b.dataset.kind;
            const [a, z] = k === 'lead' ? [n.start - n.lead, n.start] : k === 'trail' ? [n.end, n.end + n.trail] : [n.start, n.end];
            b.style.top = pct(a);
            b.style.height = pct(Math.max(z - a, 0));
            b.style.left = '0';
            b.style.width = 'calc(100% - 2px)';
            b.style.visibility = z > a ? '' : 'hidden';
            const time = k === 'main' && b.querySelector('.cal-ev-time');
            if (time) time.textContent = fmt(n.start) + '–' + fmt(n.end);
        }
        drag.label.textContent = drag.mode === 'lead' ? 'Vorlauf ' + (calDuration(n.lead) || '0 Min.')
            : drag.mode === 'trail' ? 'Nachlauf ' + (calDuration(n.trail) || '0 Min.')
            : fmt(n.start) + '–' + fmt(n.end) + ' Uhr';
        drag.label.style.top = pct(drag.mode === 'trail' ? n.end : n.start - n.lead);
        drag.col.appendChild(drag.label);
    }

    /** Am oberen/unteren Rand des Rasters automatisch weiterscrollen */
    function autoScroll() {
        if (!drag || !drag.active) return;
        const r = scroller.getBoundingClientRect();
        const top = r.top + sticky.offsetHeight + 30, bottom = r.bottom - 30;
        const v = drag.lastY < top ? -Math.ceil((top - drag.lastY) / 3) : drag.lastY > bottom ? Math.ceil((drag.lastY - bottom) / 3) : 0;
        if (v) {
            const before = scroller.scrollTop;
            scroller.scrollTop += v;
            if (scroller.scrollTop !== before) update();
        }
        drag.raf = requestAnimationFrame(autoScroll);
    }

    function cleanup(dr) {
        clearTimeout(dr.timer);
        cancelAnimationFrame(dr.raf);
        if (dr.label) dr.label.remove();
        dr.blocks.forEach((b) => b.classList.remove('cal-dragging'));
        tg.classList.remove('cal-tg-busy');
    }

    function reset() {
        if (!drag) return;
        cleanup(drag);
        drag = null;
    }

    function restore(dr = drag) {
        if (!dr) return;
        cleanup(dr);
        dr.saved.forEach(([b, style, parent, time]) => {
            b.setAttribute('style', style);
            parent.appendChild(b);
            if (time !== undefined) b.querySelector('.cal-ev-time').innerHTML = time;
        });
        if (dr === drag) drag = null;
    }

    async function finish() {
        const dr = drag;
        drag = null;
        cancelAnimationFrame(dr.raf);
        const d = dr.d, n = dr.now;
        const changed = n.start !== d.start || n.end !== d.end || n.lead !== d.lead || n.trail !== d.trail || dr.col !== dr.origCol;
        if (!changed) { restore(dr); return; }
        let scope = 'series';
        if (d.rec) {
            scope = await calAskScope();
            if (scope !== 'one' && scope !== 'series') { restore(dr); return; }
        }
        const day = dr.col.dataset.date;
        const at = (m) => { const t = new Date(day + 'T00:00:00'); t.setMinutes(m); return calDate(t) + ' ' + calTime(t); };
        dr.label.textContent = 'Speichern …';
        try {
            await HB.post('/calendar/' + d.id + '/move', { date: d.date, start: at(n.start), end: at(n.end), lead: n.lead, trail: n.trail, scope });
        } catch (err) {
            alert(err.message);
        }
        location.reload();
    }
}

/** Rückfrage bei Terminserien: 'one' | 'series' | 'cancel' */
function calAskScope() {
    const dialog = document.getElementById('cal-scope-dialog');
    if (!dialog || !dialog.showModal) {
        return Promise.resolve(confirm('Nur diesen Termin ändern? (Abbrechen = ganze Serie)') ? 'one' : 'series');
    }
    return new Promise((resolve) => {
        dialog.returnValue = '';
        dialog.addEventListener('close', () => resolve(dialog.returnValue || 'cancel'), { once: true });
        dialog.showModal();
    });
}
