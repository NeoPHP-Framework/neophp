/*!
 * NeoPHP QrCode — <neo-qr-reader>
 *
 * Scans QR codes with the camera (BarcodeDetector API) and always offers the manual entry of the short code
 * shown under the QR code: when the camera is refused, the browser has no BarcodeDetector or the code is unreadable.
 *
 * Attributes: endpoint, code-length, cooldown (ms), facing (environment | user), autostart (true | false),
 * manual (true | false | only), csrf-token, csrf-header, label-* (texts).
 * Events: qr-scan (cancelable, detail: {content, source}), qr-result (detail: {result, status, content, source}), qr-error (detail: {error}).
 * Methods: start(), stop(), submit(content, source).
 */
(() => {
    if (customElements.get('neo-qr-reader')) {
        return;
    }

    const LABELS = {
        start: 'Start the camera',
        stop: 'Stop the camera',
        torch: 'Torch',
        manual: 'Type the code',
        submit: 'Check',
        placeholder: 'Code under the QR code',
        unsupported: 'This browser cannot scan: type the code shown under the QR code.',
        denied: 'The camera is not available: type the code shown under the QR code.',
        scanning: 'Point the camera at the QR code',
        checking: 'Checking…',
        invalid: 'Invalid code',
        valid: 'Valid code',
    };

    const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    const STYLE = `
        :host { display: block; max-width: 480px; font: inherit; color: inherit; --qr-accent: #2563eb; --qr-valid: #15803d; --qr-invalid: #b42318; --qr-radius: 16px; }
        .box { position: relative; aspect-ratio: 1; border-radius: var(--qr-radius); overflow: hidden; background: #05060d; }
        video { width: 100%; height: 100%; object-fit: cover; display: block; }
        .frame { position: absolute; inset: 18%; border: 4px solid rgba(255, 255, 255, .9); border-radius: 18px; box-shadow: 0 0 0 999px rgba(5, 6, 13, .45); transition: border-color .2s; }
        :host([data-state="valid"]) .frame { border-color: var(--qr-valid); }
        :host([data-state="invalid"]) .frame { border-color: var(--qr-invalid); }
        .status { position: absolute; left: 12px; right: 12px; bottom: 12px; padding: 8px 12px; border-radius: 10px; background: rgba(0, 0, 0, .6); color: #fff; font-size: 14px; text-align: center; }
        .tools { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        button, input { font: inherit; min-height: 44px; border-radius: 12px; box-sizing: border-box; }
        button { padding: 0 16px; border: 1px solid rgba(127, 127, 127, .35); background: transparent; color: inherit; cursor: pointer; }
        button[data-primary] { border: none; background: var(--qr-accent); color: #fff; font-weight: 700; }
        form { display: flex; gap: 8px; margin-top: 10px; }
        input { flex: 1; min-width: 0; padding: 0 14px; border: 1px solid rgba(127, 127, 127, .5); background: transparent; color: inherit; font-size: 20px; letter-spacing: .12em; text-transform: uppercase; }
        input::placeholder { font-size: 15px; letter-spacing: normal; text-transform: none; }
        .message { margin-top: 8px; font-size: 14px; min-height: 1.4em; }
        :host([data-state="valid"]) .message { color: var(--qr-valid); }
        :host([data-state="invalid"]) .message { color: var(--qr-invalid); }
        [hidden] { display: none !important; }
    `;

    class NeoQrReader extends HTMLElement {
        constructor() {
            super();
            this.stream = null;
            this.detector = null;
            this.timer = null;
            this.busy = false;
            this.last = { content: null, at: 0 };
            this.attachShadow({ mode: 'open' });
        }

        label(name) {
            return this.getAttribute('label-' + name) || LABELS[name];
        }

        get codeLength() {
            return parseInt(this.getAttribute('code-length') || '8', 10);
        }

        get cooldown() {
            return parseInt(this.getAttribute('cooldown') || '2500', 10);
        }

        connectedCallback() {
            const manual = (this.getAttribute('manual') || 'true').toLowerCase();
            const length = this.codeLength;
            const formatted = length + Math.floor((length - 1) / 4);

            this.shadowRoot.innerHTML = `
                <style>${STYLE}</style>
                <div class="box" part="camera" ${manual === 'only' ? 'hidden' : ''}>
                    <video part="video" muted playsinline></video>
                    <div class="frame" part="frame"></div>
                    <div class="status" part="status" role="status"></div>
                </div>
                <div class="tools" part="tools" ${manual === 'only' ? 'hidden' : ''}>
                    <button type="button" data-action="toggle" data-primary></button>
                    <button type="button" data-action="torch" hidden></button>
                    <button type="button" data-action="manual" ${manual === 'false' ? 'hidden' : ''}></button>
                </div>
                <form part="form" ${manual === 'only' ? '' : 'hidden'}>
                    <input part="input" name="code" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="${formatted}" inputmode="text" aria-label="">
                    <button type="submit" part="submit" data-primary></button>
                </form>
                <div class="message" part="message" aria-live="polite"></div>
            `;

            const $ = (selector) => this.shadowRoot.querySelector(selector);
            this.video = $('video');
            this.status = $('.status');
            this.message = $('.message');
            this.form = $('form');
            this.input = $('input');
            this.toggleButton = $('[data-action="toggle"]');
            this.torchButton = $('[data-action="torch"]');
            this.manualButton = $('[data-action="manual"]');

            this.input.setAttribute('aria-label', this.label('placeholder'));
            this.input.placeholder = this.label('placeholder');
            $('button[type="submit"]').textContent = this.label('submit');
            this.manualButton.textContent = this.label('manual');
            this.torchButton.textContent = this.label('torch');
            this.toggleButton.textContent = this.label('start');

            this.toggleButton.addEventListener('click', () => (this.stream ? this.stop() : this.start()));
            this.manualButton.addEventListener('click', () => this.showManual(true));
            this.torchButton.addEventListener('click', () => this.toggleTorch());
            this.input.addEventListener('input', () => this.formatInput());
            this.form.addEventListener('submit', (event) => {
                event.preventDefault();
                const value = this.input.value.trim();

                if (value !== '') {
                    this.submit(value, 'code');
                }
            });

            if (manual !== 'only' && (this.getAttribute('autostart') || 'true') !== 'false') {
                this.start();
            }
        }

        disconnectedCallback() {
            this.stop();
        }

        async start() {
            if (this.stream) {
                return;
            }

            if (!('BarcodeDetector' in window)) {
                this.fallback(this.label('unsupported'));

                return;
            }

            try {
                const formats = await window.BarcodeDetector.getSupportedFormats();

                if (!formats.includes('qr_code')) {
                    this.fallback(this.label('unsupported'));

                    return;
                }

                this.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: this.getAttribute('facing') || 'environment', width: { ideal: 1280 }, height: { ideal: 1280 } },
                    audio: false,
                });
            } catch (error) {
                this.stream = null;
                this.fallback(this.label('denied'));
                this.emit('qr-error', { error: String(error && error.name ? error.name : error) });

                return;
            }

            this.video.srcObject = this.stream;
            await this.video.play().catch(() => {});
            this.toggleButton.textContent = this.label('stop');
            this.status.textContent = this.label('scanning');

            const track = this.stream.getVideoTracks()[0];
            const capabilities = track && track.getCapabilities ? track.getCapabilities() : {};
            this.torchButton.hidden = !capabilities.torch;
            this.loop();
        }

        stop() {
            clearTimeout(this.timer);

            if (this.stream) {
                this.stream.getTracks().forEach((track) => track.stop());
            }

            this.stream = null;

            if (this.video) {
                this.video.srcObject = null;
                this.status.textContent = '';
                this.toggleButton.textContent = this.label('start');
                this.torchButton.hidden = true;
            }
        }

        async loop() {
            if (!this.stream) {
                return;
            }

            if (!this.busy && this.video.readyState >= 2) {
                try {
                    const codes = await this.detector.detect(this.video);

                    if (codes.length > 0 && codes[0].rawValue) {
                        this.submit(codes[0].rawValue, 'qr');
                    }
                } catch (error) {
                    // A frame that cannot be analysed: the next one is tried.
                }
            }

            this.timer = setTimeout(() => this.loop(), 150);
        }

        async toggleTorch() {
            const track = this.stream && this.stream.getVideoTracks()[0];

            if (!track) {
                return;
            }

            this.torch = !this.torch;
            await track.applyConstraints({ advanced: [{ torch: this.torch }] }).catch(() => {});
        }

        showManual(focus) {
            this.form.hidden = false;

            if (focus) {
                this.input.focus();
            }
        }

        fallback(text) {
            this.status.textContent = text;
            this.message.textContent = text;
            this.toggleButton.textContent = this.label('start');

            if ((this.getAttribute('manual') || 'true') !== 'false') {
                this.showManual(false);
            }
        }

        formatInput() {
            const raw = [...this.input.value.toUpperCase().replace(/O/g, '0').replace(/[IL]/g, '1')].filter((char) => ALPHABET.includes(char)).join('');

            this.input.value = (raw.match(/.{1,4}/g) || []).join('-').slice(0, this.codeLength + Math.floor((this.codeLength - 1) / 4));
        }

        emit(name, detail, cancelable = false) {
            return this.dispatchEvent(new CustomEvent(name, { detail, bubbles: true, composed: true, cancelable }));
        }

        async submit(content, source = 'qr') {
            const now = Date.now();

            if (this.busy || (content === this.last.content && now - this.last.at < this.cooldown)) {
                return;
            }

            this.last = { content, at: now };

            if (!this.emit('qr-scan', { content, source }, true)) {
                return;
            }

            const endpoint = this.getAttribute('endpoint');

            if (!endpoint) {
                return;
            }

            this.busy = true;
            this.message.textContent = this.label('checking');
            const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
            const token = this.getAttribute('csrf-token');

            if (token) {
                headers[this.getAttribute('csrf-header') || 'X-CSRF-Token'] = token;
            }

            try {
                const response = await fetch(endpoint, { method: 'POST', headers, credentials: 'same-origin', body: JSON.stringify({ content, source }) });
                const result = await response.json().catch(() => ({ valid: false, message: response.statusText }));
                const valid = response.ok && result.valid !== false;

                this.dataset.state = valid ? 'valid' : 'invalid';
                this.message.textContent = result.message || this.label(valid ? 'valid' : 'invalid');

                if (navigator.vibrate) {
                    navigator.vibrate(valid ? 80 : [60, 60, 60]);
                }

                if (source === 'code' && valid) {
                    this.input.value = '';
                }

                this.emit('qr-result', { result, status: response.status, content, source });
            } catch (error) {
                this.dataset.state = 'invalid';
                this.message.textContent = String(error && error.message ? error.message : error);
                this.emit('qr-error', { error: String(error), content, source });
            } finally {
                this.busy = false;
                setTimeout(() => {
                    if (Date.now() - this.last.at >= this.cooldown) {
                        delete this.dataset.state;
                    }
                }, this.cooldown);
            }
        }
    }

    customElements.define('neo-qr-reader', NeoQrReader);
})();