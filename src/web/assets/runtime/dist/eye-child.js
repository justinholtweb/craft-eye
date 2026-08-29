/**
 * Eye — the child script.
 *
 * Include this on a page you want to embed *somewhere else* with auto height, when you control
 * both ends. It is the missing half of the cross-origin height problem: a parent page cannot
 * measure a frame it did not serve, so the frame has to say.
 *
 *     <script src="https://your-site.example/cpresources/…/eye-child.js" defer></script>
 *
 * It posts the document height to whatever framed it, on load, on resize, and whenever the
 * content itself changes. It reads nothing, stores nothing, and sends nothing but a number.
 */
(function () {
    'use strict';

    if (window.parent === window) return;

    var PROTOCOL = 1;
    var last = 0;

    function height() {
        var body = document.body;
        var html = document.documentElement;

        return Math.max(
            body ? body.scrollHeight : 0,
            body ? body.offsetHeight : 0,
            html ? html.clientHeight : 0,
            html ? html.scrollHeight : 0,
            html ? html.offsetHeight : 0
        );
    }

    function send(force) {
        var value = height();

        // A one-pixel jitter loop between a parent that rounds and a child that does not is the
        // classic way this goes wrong, so only a real change is worth a message.
        if (!force && Math.abs(value - last) < 2) return;

        last = value;

        // '*' because the child cannot know which origin framed it. Only a height goes out, and
        // the parent verifies the message came from this exact frame before believing it.
        window.parent.postMessage({ eye: 'height', v: PROTOCOL, height: value }, '*');
    }

    window.addEventListener('message', function (event) {
        var data = event.data;
        if (data && data.eye === 'ping' && data.v === PROTOCOL) send(true);
    });

    window.addEventListener('load', function () {
        send(true);
    });
    window.addEventListener('resize', function () {
        send(false);
    });

    if (typeof window.ResizeObserver === 'function') {
        new window.ResizeObserver(function () {
            send(false);
        }).observe(document.documentElement);
    } else {
        window.setInterval(function () {
            send(false);
        }, 500);
    }

    if (document.readyState !== 'loading') send(true);
})();
