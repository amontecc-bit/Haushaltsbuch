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
