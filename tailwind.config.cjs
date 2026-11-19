module.exports = {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './routes/**/*.php',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
            fontSize: {
                // Mínimo legible para metadatos densos (sustituye 9–10.5 px).
                '2xs': ['0.6875rem', { lineHeight: '1rem' }],
            },
            colors: {
                // Azul institucional: 50, 700 y 900 conservan los valores originales.
                inst: {
                    50: '#EAF0F7',
                    100: '#D5E1EF',
                    200: '#AFC5DF',
                    300: '#83A3CB',
                    400: '#5480B4',
                    500: '#33669F',
                    600: '#255A94',
                    700: '#1D4E89',
                    800: '#163B69',
                    900: '#0F2747',
                    950: '#0A1A30',
                },
                fondo: '#F4F6F8',
                linea: '#E3E8EE',
                tx: {
                    DEFAULT: '#17202A',
                    soft: '#5B6573',
                },
                estado: {
                    exito: '#198754',
                    adv: '#D99A00',
                    error: '#C0392B',
                    info: '#2563A6',
                },
            },
            boxShadow: {
                card: '0 1px 2px rgba(15,39,71,0.06), 0 1px 3px rgba(15,39,71,0.08)',
                pop: '0 10px 30px -8px rgba(15,39,71,0.25), 0 2px 6px rgba(15,39,71,0.08)',
            },
        },
    },
};
