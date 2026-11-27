{{-- App instalável (PWA): manifest, ícones e service worker. A instalação só é oferecida em endereço seguro (localhost/127.0.0.1 ou https). --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#1f9d55">
<meta name="application-name" content="Finanças de Casa">
<meta name="apple-mobile-web-app-title" content="Finanças">
<link rel="icon" type="image/svg+xml" href="{{ asset('icons/icon.svg') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<script>
    (() => {
        let prompt = null;

        window.fcPwa = {
            canInstall: () => prompt !== null,
            async install() {
                if (! prompt) {
                    return;
                }

                prompt.prompt();
                await prompt.userChoice;
                prompt = null;
                window.dispatchEvent(new CustomEvent('fc-pwa-changed'));
            },
        };

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            prompt = event;
            window.dispatchEvent(new CustomEvent('fc-pwa-changed'));
        });

        window.addEventListener('appinstalled', () => {
            prompt = null;
            window.dispatchEvent(new CustomEvent('fc-pwa-changed'));
        });

        if ('serviceWorker' in navigator && window.isSecureContext) {
            window.addEventListener('load', () => navigator.serviceWorker.register('{{ asset('sw.js') }}', { scope: '/' }).catch(() => {}));
        }
    })();
</script>
