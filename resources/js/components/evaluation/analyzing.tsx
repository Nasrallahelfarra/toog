import { Check, Loader2, Sparkles } from 'lucide-react';
import { motion } from 'motion/react';
import { useEffect, useState } from 'react';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

/**
 * Animated "analysing" state shown while an evaluation runs: a scanning
 * ring and the pipeline steps ticking through.
 */
export function Analyzing({ title, body }: { title?: string; body?: string }) {
    const { t } = useI18n();
    const steps = t.evaluation.show.analyzingSteps;
    const [active, setActive] = useState(0);

    useEffect(() => {
        const timer = setInterval(() => setActive((a) => Math.min(a + 1, steps.length - 1)), 1600);

        return () => clearInterval(timer);
    }, [steps.length]);

    return (
        <div className="flex flex-col items-center gap-8 py-10 text-center">
            <div className="relative size-36">
                <motion.div
                    className="border-primary/15 absolute inset-0 rounded-full border-[6px]"
                    animate={{ scale: [1, 1.06, 1] }}
                    transition={{ duration: 2.4, repeat: Infinity }}
                />
                <motion.svg className="absolute inset-0" viewBox="0 0 100 100" animate={{ rotate: 360 }} transition={{ duration: 2.2, repeat: Infinity, ease: 'linear' }}>
                    <circle cx="50" cy="50" r="46" fill="none" stroke="var(--gold)" strokeWidth="6" strokeLinecap="round" strokeDasharray="70 220" />
                </motion.svg>
                <div className="absolute inset-0 flex items-center justify-center">
                    <motion.div animate={{ scale: [1, 1.12, 1] }} transition={{ duration: 1.8, repeat: Infinity }}>
                        <Sparkles className="text-primary size-10" />
                    </motion.div>
                </div>
            </div>

            <div className="space-y-1.5">
                <h2 className="text-lg font-semibold">{title ?? t.evaluation.show.analyzingTitle}</h2>
                <p className="text-muted-foreground max-w-md text-sm">{body ?? t.evaluation.show.analyzingBody}</p>
            </div>

            <ul className="w-full max-w-sm space-y-2.5 text-start">
                {steps.map((step, i) => (
                    <motion.li
                        key={step}
                        initial={{ opacity: 0, y: 6 }}
                        animate={{ opacity: i <= active ? 1 : 0.45, y: 0 }}
                        transition={{ delay: i * 0.08 }}
                        className="flex items-center gap-3 text-sm"
                    >
                        <span
                            className={cn(
                                'flex size-6 shrink-0 items-center justify-center rounded-full',
                                i < active ? 'bg-success-soft text-success' : i === active ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {i < active ? <Check className="size-3.5" /> : i === active ? <Loader2 className="size-3.5 animate-spin" /> : <span className="size-1.5 rounded-full bg-current" />}
                        </span>
                        {step}
                    </motion.li>
                ))}
            </ul>
        </div>
    );
}
