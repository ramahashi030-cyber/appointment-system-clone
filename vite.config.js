import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // Bootstrap remains CDN-loaded; these entries contain the
            // application and authentication layout styles imported by Blade.
            input: [
                'resources/css/app.css',
                'resources/css/auth.css',
                'resources/css/patient-dashboard.css',
                'resources/css/patient-dashboard-theme.css',
                'resources/css/admin-dashboard.css',
                'resources/css/doctor-dashboard.css',
                'resources/css/admin-css/variables.css',
                'resources/css/admin-css/layout.css',
                'resources/css/admin-css/sidebar.css',
                'resources/css/admin-css/header.css',
                'resources/css/admin-css/dashboard.css',
                'resources/css/admin-css/doctor.css',
                'resources/css/admin-css/responsive.css',
                'resources/js/app.js',
                'resources/js/doctor-dashboard.js',
            ],
            refresh: true,
        }),
    ],
});
