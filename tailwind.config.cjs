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
            colors: {
                inst: {
                    900: '#0F2747',
                    700: '#1D4E89',
                    50: '#EAF0F7',
                },
                fondo: '#F4F6F8',
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
            },
        },
    },
};
