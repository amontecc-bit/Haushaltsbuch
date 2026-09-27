/* Einkauf erfassen: Posten, Kamera, lokale OCR (Tesseract.js), PDF (pdf.js), KI-Erkennung */
(function () {
    let keySeq = 0;
    const newItem = (d = {}) => Object.assign({ key: ++keySeq, name: '', quantity: 1, unit: '', total: '', category: '', suggested: false, missing: false, suspect: '', ocr: '' }, d);

    /** Skript einmalig nachladen */
    const loaded = {};
    function loadScript(src) {
        if (!loaded[src]) {
            loaded[src] = new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = src;
                s.onload = resolve;
                s.onerror = () => reject(new Error('Konnte ' + src + ' nicht laden'));
                document.head.appendChild(s);
            });
        }
        return loaded[src];
    }

    function loadImage(src) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => reject(new Error('Bild konnte nicht gelesen werden'));
            img.src = src;
        });
    }

    /** Bild verkleinern → JPEG-Blob (für Upload/Speicherung) */
    async function toJpeg(source, maxSide = 2000, quality = 0.85) {
        const w = source.naturalWidth || source.videoWidth || source.width;
        const h = source.naturalHeight || source.videoHeight || source.height;
        const scale = Math.min(1, maxSide / Math.max(w, h));
        const c = document.createElement('canvas');
        c.width = Math.round(w * scale);
        c.height = Math.round(h * scale);
        c.getContext('2d').drawImage(source, 0, 0, c.width, c.height);
        return new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', quality));
    }

    /** Gleitendes Max/Min (Fenster 2r+1) erst über Zeilen, dann über Spalten; am Rand nur der Bildteil */
    function rankFilter(src, w, h, r, pick) {
        const tmp = new Float32Array(src.length), out = new Float32Array(src.length);
        for (let y = 0; y < h; y++) {
            const o = y * w;
            for (let x = 0; x < w; x++) {
                let v = src[o + x];
                for (let k = Math.max(0, x - r), e = Math.min(w - 1, x + r); k <= e; k++) v = pick(v, src[o + k]);
                tmp[o + x] = v;
            }
        }
        for (let x = 0; x < w; x++) {
            for (let y = 0; y < h; y++) {
                let v = tmp[y * w + x];
                for (let k = Math.max(0, y - r), e = Math.min(h - 1, y + r); k <= e; k++) v = pick(v, tmp[k * w + x]);
                out[y * w + x] = v;
            }
        }
        return out;
    }

    /** Mittelwert im Fenster 2r+1 (laufende Summen, am Rand nur der Bildteil) */
    function boxBlur(src, w, h, r) {
        const tmp = new Float32Array(src.length), out = new Float32Array(src.length);
        const pass = (a, b, len, count, idx) => {
            for (let line = 0; line < count; line++) {
                let sum = 0, n = 0;
                for (let k = 0; k < Math.min(r, len); k++) { sum += a[idx(line, k)]; n++; }
                for (let i = 0; i < len; i++) {
                    if (i + r < len) { sum += a[idx(line, i + r)]; n++; }
                    if (i - r - 1 >= 0) { sum -= a[idx(line, i - r - 1)]; n--; }
                    b[idx(line, i)] = sum / n;
                }
            }
        };
        pass(src, tmp, w, h, (y, x) => y * w + x);
        pass(tmp, out, h, w, (x, y) => y * w + x);
        return out;
    }

    /**
     * Papier finden: hell + ungesättigt, auf einem groben Raster (1/8) Lücken der Schrift schließen und
     * die größte zusammenhängende Fläche nehmen. Liefert Raster-Maske (Uint8Array, Breite sw) oder null.
     */
    function paperMask(px, w, h, step = 8) {
        const sw = Math.floor(w / step), sh = Math.floor(h / step);
        let m = new Float32Array(sw * sh);
        for (let y = 0; y < sh; y++) {
            for (let x = 0; x < sw; x++) {
                const i = (y * step * w + x * step) * 4;
                const mx = Math.max(px[i], px[i + 1], px[i + 2]), mn = Math.min(px[i], px[i + 1], px[i + 2]);
                m[y * sw + x] = mx > 110 && mx - mn < 45 ? 1 : 0;
            }
        }
        m = rankFilter(rankFilter(m, sw, sh, 7, Math.max), sw, sh, 7, Math.min); // Schließen 15×15
        const label = new Int32Array(sw * sh);
        let best = 0, bestSize = 0;
        for (let s = 0, id = 0; s < m.length; s++) {
            if (!m[s] || label[s]) continue;
            id++;
            let size = 0;
            const stack = [s];
            label[s] = id;
            while (stack.length) {
                const p = stack.pop(), px0 = p % sw, py0 = (p - px0) / sw;
                size++;
                for (let dy = -1; dy <= 1; dy++) {
                    for (let dx = -1; dx <= 1; dx++) {
                        const nx = px0 + dx, ny = py0 + dy, q = ny * sw + nx;
                        if (nx >= 0 && ny >= 0 && nx < sw && ny < sh && m[q] && !label[q]) { label[q] = id; stack.push(q); }
                    }
                }
            }
            if (size > bestSize) { bestSize = size; best = id; }
        }
        if (bestSize < m.length * 0.05) return null; // kein Papier erkannt → ganzes Bild verwenden
        const mask = new Uint8Array(m.length);
        for (let s = 0; s < m.length; s++) mask[s] = label[s] === best ? 1 : 0;
        return { mask, sw, sh, step };
    }

    /**
     * Aufbereitung für OCR (Breite 1200–2400 px): Graustufen, Beleuchtung ausgleichen (durch den geglätteten
     * Papierhintergrund teilen), Kontrast strecken, auf den Bon zuschneiden und den Hintergrund weiß machen –
     * Tischdecke & Co. erzeugen sonst Buchstabenmüll am Zeilenende, an dem die Preiserkennung scheitert.
     */
    function ocrCanvas(img) {
        const iw = img.naturalWidth || img.width, ih = img.naturalHeight || img.height;
        let scale = 1;
        if (iw < 1200) scale = 1200 / iw;
        if (iw * scale > 2400) scale = 2400 / iw;
        const w = Math.round(iw * scale), h = Math.round(ih * scale);
        const src = document.createElement('canvas');
        src.width = w;
        src.height = h;
        const sctx = src.getContext('2d', { willReadFrequently: true });
        sctx.drawImage(img, 0, 0, w, h);
        const px = sctx.getImageData(0, 0, w, h).data;

        const gray = new Float32Array(w * h);
        for (let i = 0, j = 0; j < gray.length; i += 4, j++) gray[j] = Math.floor(0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2]);
        const bg = boxBlur(rankFilter(gray, w, h, 8, Math.max), w, h, 25);
        const hist = new Uint32Array(256);
        for (let j = 0; j < gray.length; j++) {
            gray[j] = Math.min(255, gray[j] / Math.max(1, bg[j]) * 255);
            hist[gray[j] | 0]++;
        }
        const pct = (p) => { let n = 0, v = 0; while (v < 255 && (n += hist[v]) < gray.length * p) v++; return v; };
        const lo = pct(0.01), range = Math.max(1, pct(0.99) - lo);

        const paper = paperMask(px, w, h);
        let x0 = 0, y0 = 0, x1 = w - 1, y1 = h - 1;
        const onPaper = (x, y) => !paper || paper.mask[Math.min(paper.sh - 1, (y / paper.step) | 0) * paper.sw + Math.min(paper.sw - 1, (x / paper.step) | 0)];
        if (paper) {
            x0 = w; y0 = h; x1 = 0; y1 = 0;
            for (let y = 0; y < paper.sh; y++) {
                for (let x = 0; x < paper.sw; x++) {
                    if (!paper.mask[y * paper.sw + x]) continue;
                    x0 = Math.min(x0, x * paper.step); x1 = Math.max(x1, (x + 1) * paper.step - 1);
                    y0 = Math.min(y0, y * paper.step); y1 = Math.max(y1, (y + 1) * paper.step - 1);
                }
            }
            x1 = Math.min(w - 1, x1); y1 = Math.min(h - 1, y1);
        }
        const c = document.createElement('canvas');
        c.width = x1 - x0 + 1;
        c.height = y1 - y0 + 1;
        const ctx = c.getContext('2d');
        const out = ctx.createImageData(c.width, c.height);
        for (let y = y0, o = 0; y <= y1; y++) {
            for (let x = x0; x <= x1; x++, o += 4) {
                const v = onPaper(x, y) ? Math.max(0, Math.min(255, (gray[y * w + x] - lo) / range * 255)) : 255;
                out.data[o] = out.data[o + 1] = out.data[o + 2] = v;
                out.data[o + 3] = 255;
            }
        }
        ctx.putImageData(out, 0, 0);
        return c;
    }

    let worker = null;
    async function ocr(canvases, onProgress) {
        await loadScript(HB.url('/assets/vendor/tesseract/tesseract.min.js'));
        if (!worker) {
            const base = HB.url('/assets/vendor/tesseract');
            worker = await Tesseract.createWorker('deu', 1, {
                workerPath: base + '/worker.min.js',
                corePath: base,
                langPath: base,
                logger: (m) => { if (m.status === 'recognizing text' && onProgress) onProgress(m.progress); },
            });
            // PSM 4 (eine Spalte, unterschiedlich große Zeilen) liest Bons deutlich besser als der Standard (6)
            await worker.setParameters({ preserve_interword_spaces: '1', tessedit_pageseg_mode: '4' });
        }
        const texts = [];
        for (let i = 0; i < canvases.length; i++) {
            const { data } = await worker.recognize(canvases[i]);
            texts.push(data.text);
        }
        // "\f" trennt die Fotos – der Server fügt überlappende Aufnahmen eines langen Bons zusammen
        return texts.join('\n\f\n');
    }

    async function pdfToCanvases(file, maxPages = 5) {
        const pdfjs = await import(HB.url('/assets/vendor/pdfjs/pdf.min.mjs'));
        pdfjs.GlobalWorkerOptions.workerSrc = HB.url('/assets/vendor/pdfjs/pdf.worker.min.mjs');
        const doc = await pdfjs.getDocument({ data: await file.arrayBuffer() }).promise;
        const out = [];
        for (let p = 1; p <= Math.min(doc.numPages, maxPages); p++) {
            const page = await doc.getPage(p);
            const vp = page.getViewport({ scale: 2 });
            const c = document.createElement('canvas');
            c.width = vp.width;
            c.height = vp.height;
            await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
            out.push(c);
        }
        return out;
    }

    window.purchaseForm = function (init, cats) {
        return {
            ...init,
            cats,
            items: (init.items || []).map((i) => newItem(i)),
            images: [],          // {blob, url}
            pdf: null,
            token: null,
            rawText: '',
            recognized: !!init.id,
            bonTotal: null,
            stats: null,         // lokale Erkennung: {rows, found, missing, unsure}
            busy: false,
            status: '',
            progress: 0,
            error: '',
            camera: false,
            stream: null,
            hasCamera: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
            candidates: [],
            transactionId: '',
            duplicate: null,     // bereits vorhandene Buchung mit gleicher Summe
            dupKey: '',
            dupDismissed: false,
            dupTimer: null,

            init() {
                if (!this.items.length && this.mode === 'manual') this.addItem(false);
                this.$watch('mode', (m) => { if (m === 'manual' && !this.items.length) this.addItem(false); this.stopCamera(); });
                if (!this.id) {
                    const later = () => { clearTimeout(this.dupTimer); this.dupTimer = setTimeout(() => this.checkDuplicate(), 600); };
                    this.$watch('date', later);
                    this.$watch('store', later);
                    this.$watch('totalManual', later);
                    this.$watch('items', later);
                }
            },

            // ---- Posten -------------------------------------------------
            addItem(focus) {
                const it = newItem();
                this.items.push(it);
                if (focus) this.$nextTick(() => document.getElementById('item-' + it.key)?.focus());
            },
            total() {
                if (!this.items.length) return HB.parseAmount(this.totalManual);
                return Math.round(this.items.reduce((s, i) => s + HB.parseAmount(i.total), 0) * 100) / 100;
            },
            unitPrice(it) {
                const q = HB.parseAmount(it.quantity);
                const t = HB.parseAmount(it.total);
                if (!q || q === 1 || !t) return '';
                return HB.num(q, it.unit ? 3 : 0) + ' ' + (it.unit || 'Stk') + ' × ' + HB.money(t / q) + (it.unit ? '/' + it.unit : '');
            },
            async onName(it) {
                if (it.category && !it.suggested) return;
                try {
                    const r = await HB.post('/purchases/suggest', { names: [it.name] });
                    if (r.categories[0]) { it.category = String(r.categories[0]); it.suggested = true; }
                } catch (e) { /* ohne Vorschlag */ }
            },
            canBook() { return this.accountId && this.bookable.includes(this.accountId); },

            // ---- Kamera -------------------------------------------------
            async startCamera() {
                this.error = '';
                try {
                    this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 2560 } }, audio: false });
                    this.camera = true;
                    this.$nextTick(() => { this.$refs.video.srcObject = this.stream; });
                } catch (e) {
                    this.error = 'Kamera nicht verfügbar (' + e.message + '). Bitte „Kassenbon fotografieren“ verwenden.';
                }
            },
            stopCamera() {
                if (this.stream) this.stream.getTracks().forEach((t) => t.stop());
                this.stream = null;
                this.camera = false;
            },
            async snap() {
                const blob = await toJpeg(this.$refs.video, 2400, 0.9);
                this.images.push({ blob, url: URL.createObjectURL(blob) });
                this.stopCamera();
            },
            async addImages(ev) {
                for (const f of ev.target.files) {
                    const img = await loadImage(URL.createObjectURL(f));
                    const blob = await toJpeg(img, 2400, 0.9);
                    this.images.push({ blob, url: URL.createObjectURL(blob) });
                }
                ev.target.value = '';
            },
            removeImage(i) { URL.revokeObjectURL(this.images[i].url); this.images.splice(i, 1); },
            setPdf(f) { if (f && f.type === 'application/pdf') this.pdf = f; else this.error = 'Bitte eine PDF-Datei wählen.'; },

            // ---- Erkennung ----------------------------------------------
            async recognize() {
                this.busy = true;
                this.error = '';
                this.progress = 0;
                try {
                    const fd = new FormData();
                    fd.append('mode', this.recognizer);
                    let result;
                    if (this.mode === 'photo') {
                        this.images.forEach((im, i) => fd.append('files[]', im.blob, 'bon' + i + '.jpg'));
                        if (this.recognizer === 'local') {
                            this.status = 'Texterkennung …';
                            const canvases = [];
                            for (const im of this.images) canvases.push(ocrCanvas(await loadImage(im.url)));
                            fd.append('text', await ocr(canvases, (p) => { this.progress = Math.round(p * 100); }));
                        } else {
                            this.status = 'KI liest den Bon …';
                        }
                        result = await HB.post('/purchases/recognize', fd);
                    } else {
                        fd.append('files[]', this.pdf, this.pdf.name);
                        this.status = this.recognizer === 'ai' ? 'KI liest das PDF …' : 'PDF wird gelesen …';
                        result = await HB.post('/purchases/recognize', fd);
                        if (result.needs_ocr) {
                            this.status = 'Gescanntes PDF – Texterkennung …';
                            const text = await ocr(await pdfToCanvases(this.pdf), (p) => { this.progress = Math.round(p * 100); });
                            const fd2 = new FormData();
                            fd2.append('mode', 'local');
                            fd2.append('text', text);
                            fd2.append('token', result.token);
                            result = await HB.post('/purchases/recognize', fd2);
                        }
                    }
                    this.apply(result);
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.busy = false;
                    this.status = '';
                }
            },
            apply(r) {
                this.token = r.token || this.token;
                this.rawText = r.raw_text || '';
                this.source = this.mode === 'pdf' ? 'pdf' : 'photo';
                if (r.date) this.date = r.date;
                if (r.store) this.store = r.store;
                this.bonTotal = r.total ?? null;
                this.items = (r.items || []).map((i) => newItem({
                    name: i.name, quantity: i.quantity, unit: i.unit || '', total: i.total_price === null ? '' : HB.num(i.total_price),
                    category: i.category_id ? String(i.category_id) : '', suggested: !!i.suggested, corrected: !!i.corrected,
                    missing: !!i.missing, suspect: i.suspect || '', ocr: i.ocr || '',
                }));
                const missing = this.items.filter((i) => i.missing).length;
                this.stats = {
                    rows: this.items.length, found: this.items.length - missing, missing,
                    unsure: this.items.filter((i) => i.suspect || i.corrected).length,
                };
                if (!this.items.length) {
                    this.error = '';
                    this.addItem(false);
                    alert('Es wurden keine Posten erkannt. Bitte von Hand ergänzen oder die KI-Erkennung versuchen.');
                }
                this.recognized = true;
                this.dupKey = '';
                this.checkDuplicate();
                this.loadCandidates();
            },

            // ---- Verknüpfung & Speichern --------------------------------
            async loadCandidates() {
                if (this.link !== 'existing') return;
                try {
                    const r = await HB.post('/purchases/candidates', { date: this.date, total: String(this.total()), store: this.store, current: 0 });
                    this.candidates = r.candidates;
                    const exact = r.candidates.find((c) => c.exact);
                    if (exact && !this.transactionId) this.transactionId = String(exact.id);
                } catch (e) { this.candidates = []; }
            },
            /** Gibt es die Ausgabe schon als Buchung (z. B. aus dem CSV-Import)? Dann verknüpfen statt doppelt buchen. */
            async checkDuplicate() {
                if (this.id || this.link === 'keep') return false;
                const total = this.total();
                const key = [this.date, this.store, total].join('|');
                if (key === this.dupKey) return false;
                this.dupKey = key;
                if (!total) { this.duplicate = null; return false; }
                try {
                    const r = await HB.post('/purchases/candidates', { date: this.date, total: String(total), store: this.store, current: 0 });
                    const hit = r.candidates.find((c) => c.exact) || null;
                    const changed = hit && (!this.duplicate || this.duplicate.id !== hit.id);
                    this.duplicate = hit;
                    if (!hit) return false;
                    this.candidates = r.candidates;
                    // Gleicher Betrag beim selben Anbieter: automatisch verknüpfen (solange nicht bewusst abgelehnt)
                    if (changed && hit.same_payee && !this.dupDismissed && this.link !== 'existing') this.useDuplicate();
                    return changed;
                } catch (e) { return false; }
            },
            useDuplicate() {
                if (!this.duplicate) return;
                if (!this.candidates.some((c) => c.id === this.duplicate.id)) this.candidates.unshift(this.duplicate);
                this.link = 'existing';
                this.transactionId = String(this.duplicate.id);
            },
            /** Platzhalter einer unlesbaren Bonzeile, noch ohne Preis */
            isOpen(it) { return it.missing && !String(it.total).trim(); },
            openCount() { return this.items.filter((i) => this.isOpen(i)).length; },
            async save() {
                this.error = '';
                const open = this.openCount();
                if (open && !confirm(open + (open === 1 ? ' Zeile' : ' Zeilen') + ' ohne Preis ' + (open === 1 ? 'wird' : 'werden') + ' nicht gespeichert. Trotzdem speichern?')) return;
                // Vor dem Neubuchen noch einmal prüfen – neu gefundene Doppelbuchung erst anzeigen
                if (this.link === 'new' && !this.dupDismissed && await this.checkDuplicate()) return;
                if (this.link === 'existing' && !this.transactionId) { this.error = 'Bitte eine Buchung auswählen.'; return; }
                this.busy = true;
                try {
                    const payload = {
                        purchase_date: this.date, store: this.store, account_id: this.accountId, note: this.note,
                        total: this.totalManual, source: this.source, raw_text: this.rawText, token: this.token,
                        link: this.link, transaction_id: this.transactionId,
                        items: this.items.filter((i) => i.name.trim() && !this.isOpen(i)).map((i) => ({
                            name: i.name, quantity: String(i.quantity).replace(',', '.'), unit: i.unit, total_price: i.total, category_id: i.category,
                        })),
                    };
                    const r = await HB.post(this.id ? '/purchases/' + this.id : '/purchases', payload);
                    location.href = r.redirect;
                } catch (e) {
                    this.error = e.message;
                    this.busy = false;
                }
            },
        };
    };
})();
