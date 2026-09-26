/* Gemeinsame Helfer für alle Seiten */
window.HB = (function () {
    const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';

    function url(path) {
        return meta('base-url') + '/' + String(path).replace(/^\//, '');
    }

    /** POST (JSON oder FormData) mit CSRF-Token, Antwort als JSON */
    async function post(path, data) {
        const isForm = data instanceof FormData;
        const res = await fetch(url(path), {
            method: 'POST',
            headers: Object.assign(
                { 'X-CSRF-Token': meta('csrf-token'), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                isForm ? {} : { 'Content-Type': 'application/json' }
            ),
            body: isForm ? data : JSON.stringify(data || {}),
        });
        let json = null;
        try { json = await res.json(); } catch (e) { /* ignore */ }
        if (!res.ok) {
            throw new Error((json && json.error) || ('Fehler ' + res.status));
        }
        return json;
    }

    /** "1.234,56" / "12.5" / "-3" → Zahl (Euro) */
    function parseAmount(v) {
        if (typeof v === 'number') return v;
        let s = String(v || '').replace(/[\s€]/g, '').replace('+', '');
        if (!s) return 0;
        let neg = false;
        if (s.endsWith('-')) { neg = true; s = s.slice(0, -1); }
        if (s.startsWith('-')) { neg = !neg; s = s.slice(1); }
        const c = s.lastIndexOf(','), d = s.lastIndexOf('.');
        if (c > -1 && d > -1) {
            s = c > d ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
        } else if (c > -1) {
            s = s.replace(',', '.');
        }
        const n = parseFloat(s);
        return isNaN(n) ? 0 : (neg ? -n : n);
    }

    const fmt = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
    function money(n) { return fmt.format(n || 0); }
    function num(n, digits = 2) {
        return (n || 0).toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    }

    function toggleTheme() {
        const cur = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', cur);
        try { localStorage.setItem('theme', cur); } catch (e) { /* ignore */ }
        // Diagramme haben eigene Farbstufen je Modus → neu zeichnen
        if (window.Chart && Object.keys(Chart.instances || {}).length) location.reload();
    }

    function confirmSubmit(form, message) {
        return window.confirm(message || 'Wirklich löschen?');
    }

    const isDark = () => document.documentElement.getAttribute('data-bs-theme') === 'dark';

    /** Validierte Kategorienpalette (feste Reihenfolge, nie zyklisch) – eigene Stufen für Hell/Dunkel */
    const PALETTE = {
        light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'],
        dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
    };
    const OTHER = { light: '#a3a29c', dark: '#6f6e69' };
    function series(i) { return PALETTE[isDark() ? 'dark' : 'light'][i]; }
    function otherColor() { return OTHER[isDark() ? 'dark' : 'light']; }
    function surface() { return getComputedStyle(document.body).getPropertyValue('--bs-body-bg').trim() || (isDark() ? '#212529' : '#fff'); }

    /** Chart.js-Standards: dünne Marken, 2px Abstände in Flächenfarbe, zurückhaltende Achsen */
    function chartDefaults() {
        if (!window.Chart) return;
        const dark = isDark();
        Chart.defaults.color = dark ? '#c3c2b7' : '#52514e';
        Chart.defaults.borderColor = dark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.elements.bar.borderRadius = 4;
        Chart.defaults.elements.bar.borderSkipped = 'start';
        Chart.defaults.elements.line.borderWidth = 2;
        Chart.defaults.elements.point.radius = 0;
        Chart.defaults.elements.point.hoverRadius = 5;
        Chart.defaults.elements.point.hitRadius = 12;
        Chart.defaults.elements.arc.borderWidth = 2;
        Chart.defaults.elements.arc.borderColor = surface();
        Chart.defaults.interaction.mode = 'index';
        Chart.defaults.interaction.intersect = false;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.tooltip.callbacks.label = function (ctx) {
            const v = ctx.parsed && typeof ctx.parsed === 'object' ? (ctx.parsed.y ?? ctx.parsed) : ctx.parsed;
            return (ctx.dataset.label ? ctx.dataset.label + ': ' : (ctx.label ? ctx.label + ': ' : '')) + money(v);
        };
    }

    /** Achsenbeschriftung in Euro ohne Nachkommastellen */
    const euroTick = (v) => HB.num(v, 0) + ' €';

    /** Alpine-Baustein für Listen mit Mehrfachauswahl und „Alle auswählen“ (ids als Strings wie in den Checkboxen) */
    function selection(ids) {
        const all = (ids || []).map(String);
        return {
            selected: [],
            allSelected() { return all.length > 0 && this.selected.length === all.length; },
            someSelected() { return this.selected.length > 0 && this.selected.length < all.length; },
            toggleAll() { this.selected = this.allSelected() ? [] : all.slice(); },
        };
    }

    return { url, post, parseAmount, money, num, toggleTheme, confirmSubmit, chartDefaults, series, otherColor, surface, euroTick, selection };
})();

/* Service Worker für PWA-Installation */
if ('serviceWorker' in navigator && location.protocol === 'https:') {
    window.addEventListener('load', () => navigator.serviceWorker.register(HB.url('/sw.js')).catch(() => {}));
}
