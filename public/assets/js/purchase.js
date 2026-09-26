/* Einkauf erfassen: Posten, Kamera, lokale OCR (Tesseract.js), PDF (pdf.js), KI-Erkennung */
(function () {
    let keySeq = 0;
    const newItem = (d = {}) => Object.assign({ key: ++keySeq, name: '', quantity: 1, unit: '', total: '', category: '', suggested: false }, d);

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

    /** Aufbereitung für OCR: Graustufen + Kontrast strecken, Breite 1200–2400 px */
    function ocrCanvas(img) {
        const w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
        let scale = 1;
        if (w < 1200) scale = 1200 / w;
        if (w * scale > 2400) scale = 2400 / w;
        const c = document.createElement('canvas');
        c.width = Math.round(w * scale);
        c.height = Math.round(h * scale);
        const ctx = c.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, c.width, c.height);
        const d = ctx.getImageData(0, 0, c.width, c.height);
        const px = d.data;
        let min = 255, max = 0;
        const gray = new Uint8ClampedArray(px.length / 4);
        for (let i = 0, j = 0; i < px.length; i += 4, j++) {
            const g = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
            gray[j] = g;
            if (g < min) min = g;
            if (g > max) max = g;
        }
        const range = Math.max(1, max - min);
        for (let i = 0, j = 0; i < px.length; i += 4, j++) {
            let v = ((gray[j] - min) / range) * 255;
            v = v < 128 ? v * 0.7 : Math.min(255, v * 1.15); // Text dunkler, Papier heller
            px[i] = px[i + 1] = px[i + 2] = v;
        }
        ctx.putImageData(d, 0, 0);
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
            await worker.setParameters({ preserve_interword_spaces: '1' });
        }
        const texts = [];
        for (let i = 0; i < canvases.length; i++) {
            const { data } = await worker.recognize(canvases[i]);
            texts.push(data.text);
        }
        return texts.join('\n');
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
                    name: i.name, quantity: i.quantity, unit: i.unit || '', total: HB.num(i.total_price),
                    category: i.category_id ? String(i.category_id) : '', suggested: !!i.suggested, corrected: !!i.corrected,
                }));
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
            async save() {
                this.error = '';
                // Vor dem Neubuchen noch einmal prüfen – neu gefundene Doppelbuchung erst anzeigen
                if (this.link === 'new' && !this.dupDismissed && await this.checkDuplicate()) return;
                if (this.link === 'existing' && !this.transactionId) { this.error = 'Bitte eine Buchung auswählen.'; return; }
                this.busy = true;
                try {
                    const payload = {
                        purchase_date: this.date, store: this.store, account_id: this.accountId, note: this.note,
                        total: this.totalManual, source: this.source, raw_text: this.rawText, token: this.token,
                        link: this.link, transaction_id: this.transactionId,
                        items: this.items.filter((i) => i.name.trim()).map((i) => ({
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
