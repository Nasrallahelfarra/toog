import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Info, Instagram, LayoutGrid, Radio } from 'lucide-react';
import { Stagger, StaggerItem } from '@/components/mojo/motion';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { RecommendCard, TrendingPostCard } from './cards';
import {
    CrossIdeas,
    DriverBars,
    RadarAdvice,
    RadarAlerts,
    RadarReading,
    TrendHero,
} from './insights';
import { ConfidenceChip, HowWeRead, SectionHead } from './parts';
import type { Days, Driver, RadarData, Reading } from './types';

/**
 * The radar tab: the standout idea, alerts and advice beside it, the most
 * engaging posts, the designs worth adding, the AI reading, what drives
 * engagement and the ideas shared across competitors.
 */
export function RadarView({
    radar,
    reading,
    onTab,
}: {
    radar: RadarData;
    reading: Reading;
    onTab: (tab: 'products' | 'accounts') => void;
}) {
    const { t, tr, number, locale } = useI18n();
    const { label } = useVocabulary();
    const r = t.radar;
    const c = radar.coverage;
    const readings = reading?.from_model ? reading.posts : {};

    const driverLabel = (d: Driver) => {
        switch (d.dimension) {
            case 'hook':
                return (
                    r.tags.hooks[d.key as keyof typeof r.tags.hooks] ?? d.key
                );
            case 'format':
                return (
                    r.tags.formats[d.key as keyof typeof r.tags.formats] ??
                    d.key
                );
            case 'color':
                return label('colors', d.key);
            case 'pattern':
                return label('patterns', d.key);
            case 'occasion':
                return (
                    r.tags.occasions[d.key as keyof typeof r.tags.occasions] ??
                    label('seasons', d.key)
                );
            default:
                return garment(d.key);
        }
    };
    const garment = (key: string) =>
        (
            {
                abaya: ['عباية', 'Abaya'],
                dress_daily: ['فستان يومي', 'Day dress'],
                dress_evening: ['فستان سهرة', 'Evening dress'],
                jalabiya: ['جلابية', 'Jalabiya'],
                kaftan: ['قفطان', 'Kaftan'],
                kimono: ['كيمونو', 'Kimono'],
                coord_set: ['طقم قطعتين', 'Co-ord set'],
                prayer_set: ['جلال صلاة', 'Prayer set'],
            } as Record<string, [string, string]>
        )[key]?.[locale === 'ar' ? 0 : 1] ?? key;

    const widen = (days: Days) =>
        router.get(
            '/store/competitors',
            { days },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['radar', 'reading', 'days'],
            },
        );

    return (
        <div className="space-y-8">
            {c.demo && (
                <p className="flex items-center gap-2 rounded-xl bg-info-soft/70 px-4 py-2.5 text-xs text-info">
                    <Info className="size-4 shrink-0" />
                    {r.demo}
                </p>
            )}
            {c.confidence === 'low' && c.social_accounts > 0 && (
                <p className="rounded-xl bg-warning-soft/70 px-4 py-2.5 text-xs text-warning">
                    {tr(r.confidence.lowBanner, { p: c.eligible })}
                </p>
            )}

            <div className="grid gap-6 2xl:grid-cols-12">
                <div className="2xl:col-span-8">
                    <TrendHero hero={radar.hero} />
                </div>
                <div className="grid gap-6 lg:grid-cols-2 2xl:col-span-4 2xl:grid-cols-1 2xl:content-start">
                    <RadarAlerts alerts={radar.alerts} limit={4} />
                    <RadarAdvice advice={radar.advice} />
                </div>
            </div>

            <section className="space-y-4">
                <SectionHead
                    title={r.posts.title}
                    hint={r.posts.hint}
                    action={
                        <div className="flex items-center gap-3">
                            {c.social_accounts > 0 && (
                                <ConfidenceChip coverage={c} />
                            )}
                            <HowWeRead />
                        </div>
                    }
                />
                {c.social_accounts === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed px-6 py-10 text-center">
                        <Instagram className="size-8 text-muted-foreground" />
                        <p className="font-medium">{r.empty.noSocial}</p>
                        <p className="max-w-md text-sm text-muted-foreground">
                            {r.empty.noSocialBody}
                        </p>
                        <Button
                            variant="gold"
                            onClick={() => onTab('accounts')}
                        >
                            {r.empty.addSocial}
                        </Button>
                    </div>
                ) : radar.posts.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed px-6 py-10 text-center">
                        <Radio className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            {tr(r.posts.empty, { d: r.windows[radar.days] })}
                        </p>
                        {c.wider && (
                            <Button
                                variant="outline"
                                onClick={() => widen(c.wider!)}
                            >
                                {tr(r.posts.widen, { d: c.wider })}
                            </Button>
                        )}
                    </div>
                ) : (
                    <Stagger className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        {radar.posts.map((post, i) => (
                            <StaggerItem key={post.id}>
                                <TrendingPostCard
                                    post={post}
                                    rank={i + 1}
                                    reading={readings[String(post.id)]}
                                />
                            </StaggerItem>
                        ))}
                    </Stagger>
                )}
            </section>

            <section className="space-y-4">
                <SectionHead
                    title={r.recs.title}
                    hint={r.recs.hint}
                    action={
                        radar.recommendations.length > 4 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => onTab('products')}
                            >
                                <LayoutGrid className="size-4" />
                                {r.recs.all} (
                                {number(radar.recommendations.length)})
                                <ArrowLeft className="size-3.5 ltr:rotate-180" />
                            </Button>
                        )
                    }
                />
                {radar.recommendations.length === 0 ? (
                    <p className="rounded-2xl border border-dashed px-6 py-8 text-center text-sm text-muted-foreground">
                        {r.recs.empty}
                    </p>
                ) : (
                    <Stagger className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        {radar.recommendations.slice(0, 4).map((rec, i) => (
                            <StaggerItem key={`${rec.kind}-${rec.id}`}>
                                <RecommendCard rec={rec} rank={i + 1} />
                            </StaggerItem>
                        ))}
                    </Stagger>
                )}
            </section>

            <RadarReading
                reading={reading}
                posts={radar.posts}
                days={radar.days}
                demo={c.demo}
            />

            <div className="grid gap-6 xl:grid-cols-12">
                <div className="xl:col-span-7">
                    <DriverBars drivers={radar.drivers} label={driverLabel} />
                </div>
                <div className="xl:col-span-5">
                    <CrossIdeas ideas={radar.ideas} />
                </div>
            </div>

            <footer className="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-2xl border border-border/70 bg-muted/30 px-5 py-3 text-[11px] text-muted-foreground">
                <span>{tr(r.coverage.accounts, { n: c.social_accounts })}</span>
                <span>{tr(r.coverage.stores, { n: c.store_competitors })}</span>
                <span>{tr(r.coverage.posts, { n: c.posts })}</span>
                <span>{tr(r.coverage.images, { n: c.with_images })}</span>
                {c.thin_accounts.length > 0 && (
                    <Link
                        href="/store/competitors?tab=accounts"
                        className="hover:underline"
                    >
                        {tr(r.coverage.thin, {
                            names: c.thin_accounts
                                .map((x) => x.name)
                                .join('، '),
                        })}
                    </Link>
                )}
                <span className="basis-full">{r.coverage.footnote}</span>
            </footer>
        </div>
    );
}
