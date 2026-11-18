import vue from '@vitejs/plugin-vue';
import autoprefixer from 'autoprefixer';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from 'tailwindcss';
import { defineConfig } from 'vite';

// Dentro do container o Vite precisa escutar em 0.0.0.0, mas o navegador
// acessa via localhost.
const server = process.env.VITE_DOCKER
    ? { host: '0.0.0.0', port: 5173, strictPort: true, hmr: { host: 'localhost' } }
    : undefined;

export default defineConfig({
    server,
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    css: {
        postcss: {
            plugins: [tailwindcss, autoprefixer],
        },
    },
});
