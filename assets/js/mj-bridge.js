/**
 * ImperaTagadaBridge — кросс-доменный мост N (магазин / витрина) <-> I (iframe формы карты на прокси).
 * Чистый vanilla JS (ES2017+), без фреймворков и без сборки.
 * UMD: в сборщике -> import; на странице -> <script src> и window.ImperaTagadaBridge.
 *
 * Файл одинаковый в impera-tagada-gateway, impera-tagada-pro и impera-tagada-pro-headless
 * (и в Next-витрине) — меняется только во всех сразу.
 *
 * Каждое сообщение несёт ns = NS, а имена событий/методов на проводе — NS + ':' + name,
 * так что сообщения других плагинов (и чужие сообщения с похожими именами) игнорируются.
 * Данные внутри untrusted.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.ImperaTagadaBridge = factory();
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const NS = 'impera-tagada-bridge/v1';

    const wire = (name) => NS + ':' + name;

    const isOurs = (data) => !!data && typeof data === 'object' && data.ns === NS;

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

        _send(msg) {
            msg.ns = NS;
            this.port.postMessage(msg);
        }

        // Вызвать метод на той стороне и дождаться ответа.
        request(method, args, timeoutMs) {
            if (timeoutMs === undefined) timeoutMs = 10000;
            const id = ++this.seq;
            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => {
                    this.pending.delete(id);
                    reject(new Error('bridge: "' + method + '" timed out'));
                }, timeoutMs);
                this.pending.set(id, { resolve: resolve, reject: reject, timer: timer });
                this._send({ t: 'req', id: id, method: wire(method), args: args });
            });
        }

        // Зарегистрировать метод, который может вызвать та сторона.
        handle(method, fn) { this.handlers.set(wire(method), fn); }

        // Событие без ответа.
        emit(name, data) { this._send({ t: 'evt', name: wire(name), data: data }); }

        // Подписка на событие. Возвращает функцию отписки.
        on(name, fn) {
            const key = wire(name);
            let set = this.listeners.get(key);
            if (!set) { set = new Set(); this.listeners.set(key, set); }
            set.add(fn);
            return () => { const s = this.listeners.get(key); if (s) s.delete(fn); };
        }

        destroy() {
            this.pending.forEach((p) => { clearTimeout(p.timer); p.reject(new Error('bridge: destroyed')); });
            this.pending.clear();
            this.port.close();
        }

        async _onMessage(msg) {
            if (!isOurs(msg)) return;
            if (msg.t === 'res') {
                const p = this.pending.get(msg.id);
                if (!p) return;
                clearTimeout(p.timer);
                this.pending.delete(msg.id);
                if (msg.ok) p.resolve(msg.data);
                else p.reject(new Error(msg.error || 'bridge: error'));
            } else if (msg.t === 'req') {
                const fn = this.handlers.get(msg.method);
                if (!fn) {
                    this._send({ t: 'res', id: msg.id, ok: false, error: 'no handler: ' + msg.method });
                    return;
                }
                try {
                    const data = await fn(msg.args);
                    this._send({ t: 'res', id: msg.id, ok: true, data: data });
                } catch (err) {
                    this._send({ t: 'res', id: msg.id, ok: false, error: String((err && err.message) || err) });
                }
            } else if (msg.t === 'evt') {
                const set = this.listeners.get(msg.name);
                if (set) set.forEach((l) => l(msg.data));
            }
        }
    }

    // N-сторона (страница магазина). Хендшейк инициирует iframe -> гонки загрузки нет.
    // proxyOrigin: строка, массив или функция (origin, source) => boolean,
    // которая проверяется в момент сообщения (iframe мог смениться).
    function connectFrame(proxyOrigin) {
        const allows = typeof proxyOrigin === 'function'
            ? proxyOrigin
            : ((set) => (origin) => set.has(origin))(new Set([].concat(proxyOrigin)));
        return new Promise((resolve) => {
            const onMsg = (e) => {
                if (!isOurs(e.data) || e.data.type !== 'ready') return;
                if (!allows(e.origin, e.source)) return;         // <- критично
                window.removeEventListener('message', onMsg);
                const channel = new MessageChannel();
                e.source.postMessage({ ns: NS, type: 'connect' }, e.origin, [channel.port2]);
                resolve(new Bridge(channel.port1));
            };
            window.addEventListener('message', onMsg);
        });
    }

    // I-сторона (iframe на прокси). Пингует parent, пока не получит порт.
    // parentOrigin: строка или массив origin'ов; '*' (или ничего) — любой родитель:
    // тогда "ready" уходит с targetOrigin '*' (в нём нет данных), а "connect"
    // принимается только от самого window.parent.
    function acceptHost(parentOrigin) {
        const list = parentOrigin === undefined ? ['*'] : [].concat(parentOrigin);
        const any = list.indexOf('*') !== -1;
        const allowed = new Set(list);
        return new Promise((resolve) => {
            const onMsg = (e) => {
                if (!isOurs(e.data) || e.data.type !== 'connect' || !e.ports[0]) return;
                if (e.source !== window.parent) return;          // <- критично
                if (!any && !allowed.has(e.origin)) return;      // <- критично
                window.removeEventListener('message', onMsg);
                clearInterval(pinger);
                resolve(new Bridge(e.ports[0]));
            };
            window.addEventListener('message', onMsg);
            const ping = () => (any ? ['*'] : list)
                .forEach((o) => window.parent.postMessage({ ns: NS, type: 'ready' }, o));
            const pinger = setInterval(ping, 200);
            ping();
        });
    }

    return { NS: NS, Bridge: Bridge, connectFrame: connectFrame, acceptHost: acceptHost };
});
