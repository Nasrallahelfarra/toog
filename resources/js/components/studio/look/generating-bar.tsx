import { Loader2 } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useI18n } from '@/hooks/use-i18n';
import { isGenerating, lookSlots } from './look-request-card';
import type { LookRequestRow } from './types';

function progress(request: LookRequestRow) {
    if (request.type === 'look') {
        const slots = lookSlots(request);

        return { done: slots.filter((slot) => slot.design).length, total: slots.length };
    }

    return { done: request.designs.length, total: Math.max(request.variants_count, request.designs.length) };
}

/**
 * Phones only: while anything is generating, a bar at the bottom shows how
 * far it is and scrolls to the request on a tap.
 */
export function GeneratingBar({ requests }: { requests: LookRequestRow[] }) {
    const { t, tr } = useI18n();
    const reduced = useReducedMotion();
    const running = requests.filter(isGenerating);
    const first = running[0];
    const totals = running.map(progress).reduce((sum, p) => ({ done: sum.done + p.done, total: sum.total + p.total }), { done: 0, total: 0 });

    return (
        <AnimatePresence>
            {first && (
                <motion.button
                    type="button"
                    initial={reduced ? false : { y: 24, opacity: 0 }}
                    animate={{ y: 0, opacity: 1 }}
                    exit={{ y: 24, opacity: 0 }}
                    onClick={() => document.getElementById(`request-${first.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
                    className="bg-card fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-30 flex min-h-12 cursor-pointer items-center justify-center gap-2.5 rounded-2xl border px-4 text-sm font-medium shadow-lg md:hidden"
                    aria-live="polite"
                >
                    <Loader2 className="text-primary size-4 animate-spin" />
                    <span>{tr(t.studio.look.bar.generating, { done: totals.done, total: totals.total })}</span>
                </motion.button>
            )}
        </AnimatePresence>
    );
}
