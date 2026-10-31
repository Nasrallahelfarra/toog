import { router, usePage } from '@inertiajs/react';
import { ArrowLeftRight, ShieldCheck } from 'lucide-react';
import { motion } from 'motion/react';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';

/**
 * Shown while the platform admin is inside a store's dashboard, so it is
 * always clear whose data is on screen and how to get back.
 */
export function AdminBanner() {
    const { t, tr } = useI18n();
    const { url, props } = usePage();
    const { actingAsAdmin, currentStore } = props;

    if (!actingAsAdmin || !currentStore || url.startsWith('/admin')) {
        return null;
    }

    return (
        <motion.div
            initial={{ opacity: 0, y: -8 }}
            animate={{ opacity: 1, y: 0 }}
            className="from-gold/95 to-gold/80 text-gold-foreground flex flex-wrap items-center justify-between gap-2 bg-gradient-to-l px-4 py-2 text-sm md:rounded-t-xl md:px-6"
        >
            <span className="flex items-center gap-2">
                <ShieldCheck className="size-4" />
                <strong className="font-semibold">{t.nav.adminMode}</strong>
                <span className="hidden sm:inline">— {tr(t.nav.viewingStoreAs, { store: currentStore.name })}</span>
            </span>
            <Button
                size="sm"
                variant="ghost"
                className="text-gold-foreground h-7 gap-1.5 bg-white/30 hover:bg-white/50"
                onClick={() => router.post('/stores/exit')}
            >
                <ArrowLeftRight className="size-3.5" />
                {t.nav.backToAdmin}
            </Button>
        </motion.div>
    );
}
