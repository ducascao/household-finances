{{-- Modo privacidade: aplica o estado salvo neste navegador antes de desenhar a página (sem piscar os valores). --}}
<script>
    (() => {
        const key = 'fc-privacy';
        const root = document.documentElement;

        try {
            if (localStorage.getItem(key) === '1') {
                root.classList.add('fc-private');
            }
        } catch (e) {}

        window.fcPrivacy = {
            isOn: () => root.classList.contains('fc-private'),
            toggle() {
                const on = root.classList.toggle('fc-private');

                try {
                    on ? localStorage.setItem(key, '1') : localStorage.removeItem(key);
                } catch (e) {}

                // Gráficos são desenhados em canvas: redesenha para os eixos e tooltips seguirem o modo.
                document.querySelectorAll('.fi-wi-chart-canvas-ctn').forEach((el) => {
                    try {
                        window.Alpine?.$data(el)?.updateChartTheme?.();
                    } catch (e) {}
                });

                return on;
            },
        };
    })();
</script>
