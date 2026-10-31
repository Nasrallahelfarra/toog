import { usePage } from '@inertiajs/react';
import { motion } from 'motion/react';
import { useEffect } from 'react';
import { AdminBanner } from '@/components/admin-banner';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { useI18n } from '@/hooks/use-i18n';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: AppLayoutProps) {
    const { locale, dir } = useI18n();
    const { url } = usePage();

    // Keep <html> in sync when the user switches language without a reload.
    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }, [locale, dir]);

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AdminBanner />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <motion.div
                    key={url.split('?')[0]}
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
                    className="flex flex-1 flex-col"
                >
                    {children}
                </motion.div>
            </AppContent>
        </AppShell>
    );
}
