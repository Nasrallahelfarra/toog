import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'طوق';

// Full-screen pages that render without the sidebar shell.
const bare = ['welcome', 'store/no-store', 'store/suspended'];

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case bare.includes(name) || name.endsWith('/print'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster position="top-center" richColors closeButton />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#C9A24A',
    },
});

// A page chunk can vanish when a deploy replaces the build under an open
// tab. Reload once to pick up the new build instead of failing silently;
// the timestamp stops a loop if the chunk is genuinely missing.
window.addEventListener('vite:preloadError', (event) => {
    const key = 'toog:chunk-reload';
    let last = 0;
    try {
        last = Number(sessionStorage.getItem(key) ?? 0);
        sessionStorage.setItem(key, String(Date.now()));
    } catch {
        // Storage can be blocked; reloading once is still the right call.
    }
    if (Date.now() - last > 10_000) {
        event.preventDefault();
        window.location.reload();
    }
});

// This will set light / dark mode on load...
initializeTheme();
