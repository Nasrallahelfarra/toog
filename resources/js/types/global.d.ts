import type { Vocabulary } from '@/hooks/use-vocabulary';
import type { Locale } from '@/i18n';
import type { Auth, CurrentStore } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            locale: Locale;
            dir: 'rtl' | 'ltr';
            auth: Auth;
            currentStore: CurrentStore | null;
            stores: { id: number; name: string }[];
            actingAsAdmin: boolean;
            unreadNotifications: number;
            aiLive: boolean;
            aiError: string | null;
            vocabulary: Vocabulary | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
