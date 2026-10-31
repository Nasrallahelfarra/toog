import { Link } from '@inertiajs/react';
import { BadgeCheck, Radar, WandSparkles } from 'lucide-react';
import { motion } from 'motion/react';
import { useEffect } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LanguageToggle } from '@/components/app-sidebar-header';
import { useI18n } from '@/hooks/use-i18n';
import type { Dictionary } from '@/i18n';

type AuthKey = keyof Dictionary['auth'];

/**
 * Split sign-in layout: brand story on one side, the form on the other.
 * `title` / `description` are keys of the auth translations (or plain text).
 */
export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    const { t, locale, dir } = useI18n();
    const text = (value: string) => (value in t.auth ? t.auth[value as AuthKey] : value);

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }, [locale, dir]);

    const points = [
        { icon: BadgeCheck, text: t.auth.brandPoint1 },
        { icon: Radar, text: t.auth.brandPoint2 },
        { icon: WandSparkles, text: t.auth.brandPoint3 },
    ];

    return (
        <div className="bg-background grid min-h-svh lg:grid-cols-[1.05fr_1fr]">
            {/* Brand panel */}
            <div className="relative hidden overflow-hidden bg-[oklch(0.2_0.045_257)] text-white lg:flex lg:flex-col lg:justify-between lg:p-12">
                <div className="bg-sadu pointer-events-none absolute inset-0 text-white/[0.035]" />
                <div className="pointer-events-none absolute -top-40 -end-40 size-[520px] rounded-full bg-[radial-gradient(circle,oklch(0.78_0.11_82/0.28),transparent_65%)]" />
                <div className="pointer-events-none absolute -bottom-48 -start-32 size-[480px] rounded-full bg-[radial-gradient(circle,oklch(0.55_0.09_250/0.35),transparent_65%)]" />

                <Link href="/" className="relative flex items-center gap-3">
                    <span className="flex size-11 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15">
                        <AppLogoIcon className="size-7 text-[#EADBB4]" />
                    </span>
                    <span>
                        <span className="block text-lg font-semibold">طوق</span>
                        <span className="block text-xs text-white/55">{t.common.tagline}</span>
                    </span>
                </Link>

                <div className="relative max-w-lg space-y-8">
                    <motion.h2
                        initial={{ opacity: 0, y: 14 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.6 }}
                        className="text-4xl leading-tight font-semibold text-balance"
                    >
                        {t.auth.brandHeadline}
                    </motion.h2>
                    <motion.p
                        initial={{ opacity: 0, y: 14 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.6, delay: 0.1 }}
                        className="text-base leading-relaxed text-white/70"
                    >
                        {t.auth.brandBody}
                    </motion.p>
                    <ul className="space-y-3">
                        {points.map((p, i) => (
                            <motion.li
                                key={p.text}
                                initial={{ opacity: 0, x: dir === 'rtl' ? 16 : -16 }}
                                animate={{ opacity: 1, x: 0 }}
                                transition={{ duration: 0.5, delay: 0.25 + i * 0.1 }}
                                className="flex items-center gap-3 text-sm text-white/85"
                            >
                                <span className="flex size-8 items-center justify-center rounded-lg bg-[oklch(0.78_0.11_82/0.18)] text-[#EADBB4]">
                                    <p.icon className="size-4" />
                                </span>
                                {p.text}
                            </motion.li>
                        ))}
                    </ul>
                </div>

                <ScorePreview />
            </div>

            {/* Form panel */}
            <div className="relative flex flex-col">
                <div className="flex items-center justify-between p-5 lg:justify-end">
                    <Link href="/" className="flex items-center gap-2 lg:hidden">
                        <span className="bg-primary flex size-9 items-center justify-center rounded-xl">
                            <AppLogoIcon className="size-6 text-[#EADBB4]" />
                        </span>
                        <span className="font-semibold">طوق</span>
                    </Link>
                    <LanguageToggle />
                </div>
                <div className="flex flex-1 items-center justify-center px-6 pb-12">
                    <motion.div
                        initial={{ opacity: 0, y: 12 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.5 }}
                        className="w-full max-w-sm space-y-7"
                    >
                        {title && (
                            <div className="space-y-1.5">
                                <h1 className="text-2xl font-semibold tracking-tight">{text(title)}</h1>
                                {description && <p className="text-muted-foreground text-sm">{text(description)}</p>}
                            </div>
                        )}
                        {children}
                    </motion.div>
                </div>
            </div>
        </div>
    );
}

/**
 * A small animated evaluation card to show what the product does.
 */
function ScorePreview() {
    const { t, locale } = useI18n();
    const ar = locale === 'ar';
    const rows = [
        { label: ar ? 'التوافق مع التوجهات' : 'Trend fit', value: 86 },
        { label: ar ? 'ملاءمة السوق السعودي' : 'Saudi market fit', value: 92 },
        { label: ar ? 'ملاءمة السعر' : 'Price fit', value: 71 },
    ];

    return (
        <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.7, delay: 0.5 }}
            className="relative w-full max-w-sm animate-float rounded-2xl bg-white/[0.06] p-5 ring-1 ring-white/10 backdrop-blur"
        >
            <div className="mb-4 flex items-center justify-between">
                <span className="text-sm text-white/70">{t.common.score}</span>
                <span className="rounded-full bg-[oklch(0.6_0.13_158/0.25)] px-2.5 py-0.5 text-xs font-medium text-[oklch(0.85_0.12_158)]">
                    {t.common.decision.accept}
                </span>
            </div>
            <div className="mb-4 text-4xl font-semibold">
                84<span className="text-base text-white/50">/100</span>
            </div>
            <div className="space-y-2.5">
                {rows.map((r, i) => (
                    <div key={r.label} className="space-y-1">
                        <div className="flex justify-between text-xs text-white/65">
                            <span>{r.label}</span>
                            <span>{r.value}</span>
                        </div>
                        <div className="h-1.5 overflow-hidden rounded-full bg-white/10">
                            <motion.div
                                className="h-full rounded-full bg-[#C9A24A]"
                                initial={{ width: 0 }}
                                animate={{ width: `${r.value}%` }}
                                transition={{ duration: 1.1, delay: 0.8 + i * 0.15 }}
                            />
                        </div>
                    </div>
                ))}
            </div>
        </motion.div>
    );
}
