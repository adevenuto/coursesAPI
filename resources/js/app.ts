import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

createInertiaApp({
    // Titles and meta are resolved server-side by Laravel Head and delivered in
    // the initial HTML. `serverHead` tells Inertia to adopt those elements and
    // keep them in sync across visits — do not add a `title` callback here.
    serverHead: true,
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name === 'Docs':
            case name === 'Privacy':
            case name === 'Explorer':
            case name === 'CourseShow':
            case name === 'CourseEdit':
            case name === 'ScorecardScan':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
