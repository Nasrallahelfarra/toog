import {
    AtSign,
    CalendarClock,
    Check,
    Copy,
    Ghost,
    Instagram,
    Mail,
    Megaphone,
    MessageCircle,
    Music2,
    PanelTop,
    Tag,
    Target,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { MarketingPlan } from '@/components/evaluation/types';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';

const CHANNEL_ICONS: Record<
    MarketingPlan['channels'][number]['channel'],
    LucideIcon
> = {
    snapchat: Ghost,
    tiktok: Music2,
    instagram: Instagram,
    whatsapp: MessageCircle,
    x: AtSign,
    influencer: Users,
    store_banner: PanelTop,
    email: Mail,
};

/** The expert's ready-to-run plan: selling angle, Arabic ad, offer, timing, channels and how to judge it. */
export function MarketingPlanCard({ plan }: { plan: MarketingPlan }) {
    const { t, tr, locale } = useI18n();
    const m = t.stagnant.marketing;
    const reduced = useReducedMotion();
    const [copied, setCopied] = useState(false);
    // Ads run in Arabic in this market, so the copy button always takes the Arabic text.
    const ad = [
        plan.copy.headline.ar,
        plan.copy.body.ar,
        plan.copy.cta.ar,
        plan.hashtags.join(' '),
    ]
        .filter(Boolean)
        .join('\n\n');

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(ad);
            setCopied(true);
            toast.success(m.copied);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error(m.copyFailed);
        }
    };

    const item = (i: number) => ({
        initial: reduced ? false : { opacity: 0, y: 12 },
        animate: { opacity: 1, y: 0 },
        transition: { duration: 0.35, delay: reduced ? 0 : 0.1 + i * 0.07 },
    });

    const facts: {
        icon: LucideIcon;
        label: string;
        title: string;
        body?: string;
    }[] = [
        {
            icon: Tag,
            label: m.offer,
            title: `${m.offers[plan.offer.type]} · ${plan.offer.value[locale]}`,
            body: [
                tr(m.duration, { n: plan.offer.duration_days }),
                plan.offer.rationale[locale],
            ].join(' — '),
        },
        {
            icon: CalendarClock,
            label: m.timing,
            title: plan.timing.occasion[locale],
            body: plan.timing.when[locale],
        },
        { icon: Target, label: m.kpi, title: plan.kpi[locale] },
    ];

    return (
        <motion.section
            {...item(0)}
            aria-labelledby="marketing-plan-title"
            className="relative overflow-hidden rounded-2xl border border-gold/35 bg-gradient-to-b from-gold-soft/70 to-transparent p-5"
        >
            <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gold text-gold-foreground shadow-sm">
                    <Megaphone className="size-5" />
                </span>
                <div className="min-w-0">
                    <h3 id="marketing-plan-title" className="font-semibold">
                        {m.title}
                    </h3>
                    <p className="text-xs leading-6 text-muted-foreground">
                        {m.hint}
                    </p>
                </div>
            </div>

            <motion.div {...item(1)} className="mt-4">
                <span className="text-xs font-medium text-muted-foreground">
                    {m.angle}
                </span>
                <p className="mt-1 text-base leading-8 font-semibold">
                    {plan.angle[locale]}
                </p>
            </motion.div>

            <motion.div
                {...item(2)}
                className="mt-4 rounded-2xl border bg-card p-4 shadow-sm"
            >
                <div className="mb-3 flex items-center justify-between gap-2">
                    <span className="text-xs font-medium text-muted-foreground">
                        {m.ad}
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-8"
                        onClick={copy}
                    >
                        {copied ? (
                            <Check className="size-4 text-success" />
                        ) : (
                            <Copy className="size-4" />
                        )}
                        {copied ? m.copied : m.copy}
                    </Button>
                </div>
                <div dir="rtl" lang="ar" className="space-y-2 text-start">
                    <p className="text-lg leading-8 font-semibold">
                        {plan.copy.headline.ar}
                    </p>
                    <p className="text-sm leading-7">{plan.copy.body.ar}</p>
                    <span className="inline-block rounded-full bg-primary px-3.5 py-1 text-xs font-medium text-primary-foreground">
                        {plan.copy.cta.ar}
                    </span>
                    {plan.hashtags.length > 0 && (
                        <p className="flex flex-wrap gap-x-2 gap-y-1 pt-1 text-xs text-primary">
                            {plan.hashtags.map((tag) => (
                                <span key={tag}>{tag}</span>
                            ))}
                        </p>
                    )}
                </div>
                {locale === 'en' && (
                    <p className="mt-3 border-t pt-3 text-xs leading-6 text-muted-foreground">
                        <span className="font-medium">{m.translation}: </span>
                        {plan.copy.headline.en} — {plan.copy.body.en} —{' '}
                        {plan.copy.cta.en}
                    </p>
                )}
            </motion.div>

            <div className="mt-4 grid gap-3 sm:grid-cols-3">
                {facts.map((fact, i) => (
                    <motion.div
                        key={fact.label}
                        {...item(3 + i)}
                        className="rounded-xl border bg-background/70 p-3"
                    >
                        <span className="flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
                            <fact.icon className="size-3.5" />
                            {fact.label}
                        </span>
                        <p className="mt-1.5 text-sm leading-6 font-semibold">
                            {fact.title}
                        </p>
                        {fact.body && (
                            <p className="mt-1 text-xs leading-6 text-muted-foreground">
                                {fact.body}
                            </p>
                        )}
                    </motion.div>
                ))}
            </div>

            {plan.channels.length > 0 && (
                <motion.div {...item(6)} className="mt-4 space-y-2">
                    <span className="text-xs font-medium text-muted-foreground">
                        {m.channels}
                    </span>
                    <ul className="space-y-2">
                        {plan.channels.map((channel) => {
                            const Icon = CHANNEL_ICONS[channel.channel];

                            return (
                                <li
                                    key={channel.channel}
                                    className="flex items-start gap-3 rounded-xl border bg-background/70 p-3"
                                >
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <Icon className="size-4" />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold">
                                            {m.channelNames[channel.channel]}
                                        </p>
                                        <p className="text-xs leading-6 text-muted-foreground">
                                            {channel.idea[locale]}
                                        </p>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </motion.div>
            )}

            <p className="mt-4 text-[11px] leading-5 text-muted-foreground">
                {m.hypothesis}
            </p>
        </motion.section>
    );
}
