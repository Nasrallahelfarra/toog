import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    BarChart3,
    CalendarDays,
    CheckCircle2,
    Radar,
    Scale,
    ScanSearch,
    WandSparkles,
} from 'lucide-react';
import { motion } from 'motion/react';
import { useEffect } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LanguageToggle } from '@/components/app-sidebar-header';
import { FadeIn, Stagger, StaggerItem } from '@/components/mojo/motion';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';

const copy = {
    ar: {
        badge: 'مصمم للتجارة الإلكترونية في السعودية',
        title: 'لا تطرح منتجًا قبل أن تعرف فرصته',
        subtitle:
            'Mojo AI يقيّم كل منتج أو تصميم جديد مقابل مبيعات متجرك وتوجهات المنافسين وذوق العميل السعودي، ثم يوصي بالقبول أو الرفض أو التعديل — ويولّد النسخة المحسّنة بهوية متجرك.',
        start: 'ابدأ مجانًا',
        login: 'تسجيل الدخول',
        dashboard: 'لوحة التحكم',
        stats: [
            ['6', 'معايير تقييم شفافة'],
            ['3', 'قرارات واضحة: قبول، رفض، تعديل'],
            ['70/30', 'توازن ذكي بين المجرّب والمبتكر'],
        ],
        modulesTitle: 'منصة واحدة من البيانات حتى الطرح',
        modules: [
            ['أداء المتجر', 'ربط مباشر مع زد، تصنيف المنتجات إلى رابحة ومتوسطة وراكدة، وملف السمات الناجحة.'],
            ['رصد السوق', 'متابعة سلة وزد وShopify وتيك توك وإنستغرام وسناب، وكشف الفجوات في كتالوجك.'],
            ['بوابة القرار', 'درجة من 100 بستة معايير، مع تفسير بلغة بسيطة وتوصيات تعديل قابلة للتنفيذ.'],
            ['استوديو التصميم', 'صور منتجات ونماذج عرض وملفات طباعة بهوية متجرك، تُقيَّم تلقائيًا قبل الاعتماد.'],
            ['توازن الكتالوج', 'مؤشر تنوع لكل فئة، ونسبة 70/30، وتنبيهات قبل أن يمل جمهورك من التكرار.'],
            ['التقويم الموسمي', 'رمضان والأعياد واليوم الوطني ويوم التأسيس والجمعة البيضاء مع توقيت الطرح المقترح.'],
        ],
        stepsTitle: 'كيف تعمل',
        steps: ['اربط متجرك', 'ارفع المنتج المرشح', 'استلم القرار والتفسير', 'طوّر التصميم واعتمده'],
        cta: 'جاهز لقرارات منتجات أذكى؟',
        ctaBody: 'أنشئ حساب متجرك وقيّم أول منتج خلال دقائق.',
    },
    en: {
        badge: 'Built for Saudi e-commerce',
        title: 'Never launch a product before you know its odds',
        subtitle:
            'Mojo AI scores every new product or design against your store’s sales, competitor trends and Saudi shopper taste, recommends accept, reject or modify — and generates the improved version in your brand.',
        start: 'Get started',
        login: 'Sign in',
        dashboard: 'Dashboard',
        stats: [
            ['6', 'transparent scoring criteria'],
            ['3', 'clear decisions: accept, reject, modify'],
            ['70/30', 'balance of proven and new'],
        ],
        modulesTitle: 'One platform from data to launch',
        modules: [
            ['Store performance', 'Direct Zid connection, products classified into best sellers, average and slow movers, plus a success profile.'],
            ['Market radar', 'Salla, Zid, Shopify, TikTok, Instagram and Snapchat monitoring, with gaps in your catalog highlighted.'],
            ['Decision gate', 'A score out of 100 across six criteria, explained in plain language with actionable fixes.'],
            ['Design studio', 'Product photos, mockups and print files in your brand, auto-evaluated before approval.'],
            ['Catalog balance', 'Diversity index per category, a 70/30 ratio and alerts before your audience tires of repetition.'],
            ['Seasonal calendar', 'Ramadan, Eids, National Day, Founding Day and White Friday with suggested launch timing.'],
        ],
        stepsTitle: 'How it works',
        steps: ['Connect your store', 'Upload a candidate', 'Get the decision and why', 'Improve and approve'],
        cta: 'Ready for smarter product decisions?',
        ctaBody: 'Create your store account and evaluate your first product in minutes.',
    },
};

const icons = [BarChart3, Radar, ScanSearch, WandSparkles, Scale, CalendarDays];

export default function Welcome() {
    const { locale, dir } = useI18n();
    const { auth } = usePage().props;
    const c = copy[locale];
    const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight;

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }, [locale, dir]);

    return (
        <div className="bg-background min-h-svh">
            <Head title={c.title} />

            {/* Hero */}
            <section className="relative overflow-hidden bg-[oklch(0.2_0.045_257)] text-white">
                <div className="bg-sadu pointer-events-none absolute inset-0 text-white/[0.03]" />
                <div className="pointer-events-none absolute -top-48 start-1/2 size-[720px] -translate-x-1/2 rounded-full bg-[radial-gradient(circle,oklch(0.78_0.11_82/0.22),transparent_62%)]" />

                <header className="relative mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
                    <Link href="/" className="flex items-center gap-2.5">
                        <span className="flex size-10 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/15">
                            <AppLogoIcon className="size-6 text-[#EADBB4]" />
                        </span>
                        <span className="text-lg font-semibold">Mojo AI</span>
                    </Link>
                    <nav className="flex items-center gap-2">
                        <LanguageToggle className="gap-1.5 px-2.5 text-white hover:bg-white/10 hover:text-white" />
                        {auth.user ? (
                            <Button asChild variant="gold">
                                <Link href="/dashboard">{c.dashboard}</Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild variant="ghost" className="text-white hover:bg-white/10 hover:text-white">
                                    <Link href="/login">{c.login}</Link>
                                </Button>
                                <Button asChild variant="gold" className="hidden sm:inline-flex">
                                    <Link href="/register">{c.start}</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-6 pt-10 pb-24 lg:grid-cols-[1.1fr_1fr] lg:pt-16">
                    <div className="space-y-7">
                        <FadeIn>
                            <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs text-[#EADBB4] ring-1 ring-white/15">
                                <span className="size-1.5 rounded-full bg-[#C9A24A]" />
                                {c.badge}
                            </span>
                        </FadeIn>
                        <FadeIn delay={0.08}>
                            <h1 className="text-4xl leading-[1.15] font-semibold text-balance md:text-5xl">{c.title}</h1>
                        </FadeIn>
                        <FadeIn delay={0.16}>
                            <p className="max-w-xl text-lg leading-relaxed text-white/70">{c.subtitle}</p>
                        </FadeIn>
                        <FadeIn delay={0.24} className="flex flex-wrap gap-3">
                            <Button asChild size="lg" variant="gold" className="h-12 px-6 text-base">
                                <Link href={auth.user ? '/dashboard' : '/register'}>
                                    {auth.user ? c.dashboard : c.start}
                                    <Arrow className="size-4" />
                                </Link>
                            </Button>
                            {!auth.user && (
                                <Button asChild size="lg" variant="ghost" className="h-12 px-6 text-base text-white ring-1 ring-white/20 hover:bg-white/10 hover:text-white">
                                    <Link href="/login">{c.login}</Link>
                                </Button>
                            )}
                        </FadeIn>
                    </div>

                    <HeroCard locale={locale} />
                </div>
            </section>

            {/* Stats */}
            <section className="relative mx-auto -mt-10 max-w-5xl px-6">
                <Stagger className="bg-card grid gap-px overflow-hidden rounded-2xl border shadow-lg sm:grid-cols-3">
                    {c.stats.map(([value, label]) => (
                        <StaggerItem key={label} className="bg-card p-6 text-center">
                            <div className="text-primary text-3xl font-semibold">{value}</div>
                            <div className="text-muted-foreground mt-1 text-sm">{label}</div>
                        </StaggerItem>
                    ))}
                </Stagger>
            </section>

            {/* Modules */}
            <section className="mx-auto max-w-6xl px-6 py-24">
                <FadeIn className="mb-12 text-center">
                    <h2 className="text-3xl font-semibold tracking-tight">{c.modulesTitle}</h2>
                </FadeIn>
                <Stagger className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {c.modules.map(([title, body], i) => {
                        const Icon = icons[i];

                        return (
                            <StaggerItem key={title}>
                                <motion.div
                                    whileHover={{ y: -4 }}
                                    className="bg-card group h-full rounded-2xl border p-6 shadow-sm transition-shadow hover:shadow-md"
                                >
                                    <span className="bg-primary/8 text-primary group-hover:bg-gold group-hover:text-gold-foreground mb-4 flex size-11 items-center justify-center rounded-xl transition-colors">
                                        <Icon className="size-5" />
                                    </span>
                                    <h3 className="mb-2 font-semibold">{title}</h3>
                                    <p className="text-muted-foreground text-sm leading-relaxed">{body}</p>
                                </motion.div>
                            </StaggerItem>
                        );
                    })}
                </Stagger>
            </section>

            {/* Steps */}
            <section className="bg-muted/50 border-y py-20">
                <div className="mx-auto max-w-5xl px-6">
                    <h2 className="mb-12 text-center text-3xl font-semibold tracking-tight">{c.stepsTitle}</h2>
                    <Stagger className="grid gap-6 md:grid-cols-4">
                        {c.steps.map((step, i) => (
                            <StaggerItem key={step} className="relative text-center">
                                <div className="bg-primary text-primary-foreground mx-auto mb-4 flex size-12 items-center justify-center rounded-2xl text-lg font-semibold shadow-md">
                                    {i + 1}
                                </div>
                                <p className="font-medium">{step}</p>
                            </StaggerItem>
                        ))}
                    </Stagger>
                </div>
            </section>

            {/* CTA */}
            <section className="mx-auto max-w-4xl px-6 py-24 text-center">
                <FadeIn className="space-y-5">
                    <h2 className="text-3xl font-semibold tracking-tight">{c.cta}</h2>
                    <p className="text-muted-foreground">{c.ctaBody}</p>
                    <Button asChild size="lg" className="h-12 px-8 text-base">
                        <Link href={auth.user ? '/dashboard' : '/register'}>
                            {auth.user ? c.dashboard : c.start}
                            <Arrow className="size-4" />
                        </Link>
                    </Button>
                </FadeIn>
            </section>

            <footer className="text-muted-foreground border-t py-8 text-center text-xs">© {new Date().getFullYear()} Mojo AI</footer>
        </div>
    );
}

function HeroCard({ locale }: { locale: 'ar' | 'en' }) {
    const ar = locale === 'ar';
    const criteria = ar
        ? [['التشابه مع الرابحة', 82], ['التوافق مع التوجهات', 91], ['ملاءمة السوق السعودي', 88], ['ملاءمة السعر', 64]]
        : [['Best-seller similarity', 82], ['Trend fit', 91], ['Saudi market fit', 88], ['Price fit', 64]];

    return (
        <motion.div
            initial={{ opacity: 0, y: 24, rotate: ar ? 2 : -2 }}
            animate={{ opacity: 1, y: 0, rotate: 0 }}
            transition={{ duration: 0.8, delay: 0.2, ease: [0.22, 1, 0.36, 1] }}
            className="relative"
        >
            <div className="rounded-3xl bg-white/[0.07] p-6 ring-1 ring-white/10 backdrop-blur-md">
                <div className="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <div className="text-xs text-white/55">{ar ? 'نتيجة التقييم' : 'Evaluation result'}</div>
                        <div className="mt-1 font-semibold">{ar ? 'عباية كتان مريمية' : 'Sage linen abaya'}</div>
                    </div>
                    <span className="inline-flex items-center gap-1 rounded-full bg-[oklch(0.6_0.13_158/0.22)] px-2.5 py-1 text-xs text-[oklch(0.86_0.12_158)]">
                        <CheckCircle2 className="size-3.5" />
                        {ar ? 'قبول' : 'Accept'}
                    </span>
                </div>
                <div className="mb-6 flex items-end gap-2">
                    <span className="text-6xl leading-none font-semibold">82</span>
                    <span className="mb-1 text-white/50">/100</span>
                </div>
                <div className="space-y-3">
                    {criteria.map(([label, value], i) => (
                        <div key={label as string} className="space-y-1.5">
                            <div className="flex justify-between text-xs text-white/65">
                                <span>{label}</span>
                                <span>{value}</span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-white/10">
                                <motion.div
                                    className="h-full rounded-full bg-gradient-to-l from-[#EADBB4] to-[#C9A24A]"
                                    initial={{ width: 0 }}
                                    animate={{ width: `${value}%` }}
                                    transition={{ duration: 1.2, delay: 0.6 + i * 0.12 }}
                                />
                            </div>
                        </div>
                    ))}
                </div>
                <p className="mt-5 rounded-xl bg-white/[0.06] p-3 text-xs leading-relaxed text-white/70">
                    {ar
                        ? 'يتوافق مع توجه «الأخضر المريمي» الصاعد بنسبة 34% ويقع ضمن الشريحة السعرية الناجحة. مقترح الطرح: قبل رمضان بـ 8 أسابيع.'
                        : 'Matches the rising “sage green” trend (+34%) and sits in the winning price band. Suggested launch: 8 weeks before Ramadan.'}
                </p>
            </div>
            <motion.div
                className="absolute -top-7 -end-4 hidden rounded-2xl bg-white p-3 text-[oklch(0.24_0.035_256)] shadow-xl sm:block"
                animate={{ y: [0, -6, 0] }}
                transition={{ duration: 5, repeat: Infinity, ease: 'easeInOut' }}
            >
                <div className="text-[11px] text-[oklch(0.52_0.02_256)]">{ar ? 'توجه صاعد' : 'Rising trend'}</div>
                <div className="flex items-center gap-2 text-sm font-semibold">
                    <span className="size-3 rounded-full bg-[#A3B49A]" />
                    {ar ? 'نقشة السدو' : 'Sadu weave'} <span dir="ltr" className="text-[oklch(0.6_0.13_158)]">+41%</span>
                </div>
            </motion.div>
        </motion.div>
    );
}
