/**
 * Mj — кросс-доменный мост N (Next) <-> I (iframe на прокси).
 * Чистый vanilla JS (ES2017+), без фреймворков и без сборки.
 * UMD: в Next -> import; на прокси-странице -> <script src> и window.Mj.
 * Безопасность = проверка origin на хендшейке. Данные внутри untrusted.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.Mj = factory();
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    class Bridge {
        constructor(port) {
            this.port = port;
            this.seq = 0;
            this.pending = new Map();
            this.handlers = new Map();
            this.listeners = new Map();
            this.port.onmessage = (e) => this._onMessage(e.data);
            if (this.port.start) this.port.start();
        }

        // Вызвать метод на той стороне и дождаться ответа.
        request(method, args, timeoutMs) {
            if (timeoutMs === undefined) timeoutMs = 10000;
            const id = ++this.seq;
            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => {
                    this.pending.delete(id);
                    reject(new Error('mj: "' + method + '" timed out'));
                }, timeoutMs);
                this.pending.set(id, { resolve: resolve, reject: reject, timer: timer });
                this.port.postMessage({ t: 'req', id: id, method: method, args: args });
            });
        }

        // Зарегистрировать метод, который может вызвать та сторона.
        handle(method, fn) { this.handlers.set(method, fn); }

        // Событие без ответа.
        emit(name, data) { this.port.postMessage({ t: 'evt', name: name, data: data }); }

        // Подписка на событие. Возвращает функцию отписки.
        on(name, fn) {
            let set = this.listeners.get(name);
            if (!set) { set = new Set(); this.listeners.set(name, set); }
            set.add(fn);
            return () => { const s = this.listeners.get(name); if (s) s.delete(fn); };
        }

        destroy() {
            this.pending.forEach((p) => { clearTimeout(p.timer); p.reject(new Error('mj: destroyed')); });
            this.pending.clear();
            this.port.close();
        }

        async _onMessage(msg) {
            if (!msg || typeof msg !== 'object') return;
            if (msg.t === 'res') {
                const p = this.pending.get(msg.id);
                if (!p) return;
                clearTimeout(p.timer);
                this.pending.delete(msg.id);
                if (msg.ok) p.resolve(msg.data);
                else p.reject(new Error(msg.error || 'mj: error'));
            } else if (msg.t === 'req') {
                const fn = this.handlers.get(msg.method);
                if (!fn) {
                    this.port.postMessage({ t: 'res', id: msg.id, ok: false, error: 'no handler: ' + msg.method });
                    return;
                }
                try {
                    const data = await fn(msg.args);
                    this.port.postMessage({ t: 'res', id: msg.id, ok: true, data: data });
                } catch (err) {
                    this.port.postMessage({ t: 'res', id: msg.id, ok: false, error: String((err && err.message) || err) });
                }
            } else if (msg.t === 'evt') {
                const set = this.listeners.get(msg.name);
                if (set) set.forEach((l) => l(msg.data));
            }
        }
    }

    // N-сторона (домен мерчанта). Хендшейк инициирует iframe -> гонки загрузки нет.
    // proxyOrigin: строка, массив или функция (origin, source) => boolean,
    // которая проверяется в момент сообщения (iframe мог смениться).
    function connectFrame(proxyOrigin) {
        const allows = typeof proxyOrigin === 'function'
            ? proxyOrigin
            : ((set) => (origin) => set.has(origin))(new Set([].concat(proxyOrigin)));
        return new Promise((resolve) => {
            const onMsg = (e) => {
                if (!e.data || e.data.__mj !== 'ready') return;
                if (!allows(e.origin, e.source)) return;         // <- критично
                window.removeEventListener('message', onMsg);
                const channel = new MessageChannel();
                e.source.postMessage({ __mj: 'connect' }, e.origin, [channel.port2]);
                resolve(new Bridge(channel.port1));
            };
            window.addEventListener('message', onMsg);
        });
    }

    // I-сторона (домен прокси, внутри iframe). Пингует parent пока не получит порт.
    // merchantOrigin: строка или массив (allowlist, если магазинов несколько).
    function acceptHost(merchantOrigin) {
        const allowed = new Set(Array.isArray(merchantOrigin) ? merchantOrigin : [merchantOrigin]);
        return new Promise((resolve) => {
            const onMsg = (e) => {
                if (!allowed.has(e.origin)) return;              // <- критично
                if (!e.data || e.data.__mj !== 'connect' || !e.ports[0]) return;
                window.removeEventListener('message', onMsg);
                clearInterval(pinger);
                resolve(new Bridge(e.ports[0]));
            };
            window.addEventListener('message', onMsg);
            const ping = () => allowed.forEach((o) => window.parent.postMessage({ __mj: 'ready' }, o));
            const pinger = setInterval(ping, 200);
            ping();
        });
    }

    return { Bridge: Bridge, connectFrame: connectFrame, acceptHost: acceptHost };
});
