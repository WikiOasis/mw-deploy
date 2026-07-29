{{--
    Resolves the stored appearance onto the root element before the first paint.

    Deliberately inline and deliberately synchronous: any deferred script runs
    after the browser has already painted, and what you would see is the console
    rendering light and then flipping to dark — once per navigation, on every
    server-rendered page.

    resources/js/theme.js owns the same logic for the rest of the session and reads
    the same key. This is only the head start.
--}}
<script>
    (function () {
        try {
            var stored = localStorage.getItem('console-theme');
            var dark = stored === 'dark'
                || (stored !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches);

            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        } catch (error) {
            // No storage, or it threw. The stylesheet falls back to the OS
            // preference on its own, so there is nothing to do here.
        }
    })();
</script>
