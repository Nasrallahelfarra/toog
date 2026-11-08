import { Head } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowUpRight,
    ChartNoAxesCombined,
    CheckCircle2,
    Clock,
    FileSearch,
    Globe,
    LoaderCircle,
    MessageSquare,
    Package,
    RefreshCw,
    Swords,
    Unplug,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/mojo/empty-state';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import { CompetitorAnalysis, CompetitorSources } from './competitor-analysis';
import { CompetitorProducts } from './competitor-products';
import { CompetitorScanProgress, isScanning } from './competitor-scan-progress';
import { CompetitorSocialOverview, SocialPostGrid } from './competitor-social';
import { isSocialAnalysis } from './competitor-types';
import type {
    Competitor,
    CompetitorDetails,
    ProductFilter,
} from './competitor-types';
import { PlatformBadge } from './platform-badge';

export function CompetitorWorkspace({
    competitor,
    details,
    scanning,
    onScan,
    onBack,
    errorMessage,
}: {
    competitor: Competitor;
    details: CompetitorDetails;
    scanning: boolean;
    onScan: () => void;
    onBack: () => void;
    errorMessage: string | null;
}) {
    const { t, relative, dateTime, number, dir } = useI18n();
    const c = t.market.competitors;
    const w = c.workspace;
    const analysis = details.analysis;
    const social = isSocialAnalysis(analysis) ? analysis : null;
    const store = analysis && !isSocialAnalysis(analysis) ? analysis : null;
    const [tab, setTab] = useState(
        competitor.is_social && !social ? 'posts' : 'overview',
    );
    const [filter, setFilter] = useState<ProductFilter>('all');
    const tabsRef = useRef<HTMLDivElement>(null);
    const headingRef = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        headingRef.current?.focus({ preventScroll: true });
    }, []);
    const browse = (value: ProductFilter) => {
        setFilter(value);
        setTab('products');
        tabsRef.current?.scrollIntoView({ block: 'start' });
        tabsRef.current
            ?.querySelector<HTMLButtonElement>('[data-tab="products"]')
            ?.focus({ preventScroll: true });
    };
    const showSources = () => {
        setTab('sources');
        tabsRef.current?.scrollIntoView({ block: 'start' });
        tabsRef.current
            ?.querySelector<HTMLButtonElement>('[data-tab="sources"]')
            ?.focus({ preventScroll: true });
    };
    const previousReport =
        details.analysis &&
        competitor.scan &&
        (competitor.scan.status === 'failed' || isScanning(competitor.scan)) &&
        Date.parse(details.analysis.crawl.observed_at) <
            Date.parse(competitor.scan.created_at);
    const showPosts = () => {
        setTab('posts');
        tabsRef.current?.scrollIntoView({ block: 'start' });
    };
    const navigation = [
        {
            value: 'overview',
            label: w.overview,
            icon: ChartNoAxesCombined,
            visible: !competitor.is_social || !!social,
        },
        {
            value: 'products',
            label: w.products,
            icon: Package,
            count: details.products.length,
            visible: !competitor.is_social || details.products.length > 0,
        },
        {
            value: 'sources',
            label: w.sources,
            icon: FileSearch,
            visible: !!store,
        },
        {
            value: 'posts',
            label: w.posts,
            icon: MessageSquare,
            count: details.posts.length,
            visible: competitor.is_social || details.posts.length > 0,
        },
    ];
    const canScan = competitor.is_active && competitor.auto_scan;

    return (
        <>
            <Head title={`${competitor.name} · ${w.eyebrow}`} />
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={onBack}
                    className="-ms-2"
                >
                    <ArrowLeft className="size-4 rtl:rotate-180" />
                    {w.back}
                </Button>
                <span aria-hidden="true">/</span>
                <span className="truncate">{w.eyebrow}</span>
            </div>

            <header className="relative overflow-hidden rounded-2xl border border-border/70 bg-card p-5 sm:p-7">
                <div className="pointer-events-none absolute inset-y-0 end-0 w-1/2 bg-gradient-to-r from-transparent to-primary/5 rtl:bg-gradient-to-l" />
                <div className="relative flex flex-col justify-between gap-5 lg:flex-row lg:items-center">
                    <div className="flex min-w-0 items-start gap-4">
                        <div
                            className="hidden size-16 shrink-0 items-center justify-center rounded-2xl border border-primary/10 bg-primary/8 text-2xl font-semibold text-primary sm:flex"
                            aria-hidden="true"
                        >
                            {competitor.name.trim().slice(0, 1).toUpperCase()}
                        </div>
                        <div className="min-w-0 space-y-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <PlatformBadge platform={competitor.platform} />
                                <span className="text-xs text-muted-foreground">
                                    {competitor.category ??
                                        (competitor.is_social
                                            ? c.socialKind
                                            : c.storeKind)}
                                </span>
                            </div>
                            <h1
                                ref={headingRef}
                                tabIndex={-1}
                                className="text-2xl font-semibold break-words outline-none sm:text-3xl"
                            >
                                <bdi>{competitor.name}</bdi>
                            </h1>
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground">
                                <span
                                    className="flex items-center gap-1.5"
                                    title={dateTime(competitor.last_checked_at)}
                                >
                                    <Clock className="size-3.5" />
                                    {c.lastChecked}:{' '}
                                    {competitor.last_checked_at
                                        ? relative(competitor.last_checked_at)
                                        : c.neverChecked}
                                </span>
                                {!scanning &&
                                    competitor.scan?.status === 'completed' && (
                                        <span
                                            role="status"
                                            className="flex items-center gap-1.5 text-success"
                                        >
                                            <CheckCircle2 className="size-3.5" />
                                            {w.ready}
                                        </span>
                                    )}
                                {!competitor.is_active && (
                                    <span className="text-warning">
                                        {c.paused}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {competitor.profile_url && (
                            <Button variant="outline" asChild>
                                <a
                                    href={competitor.profile_url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Globe className="size-4" />
                                    {competitor.is_social
                                        ? w.visitAccount
                                        : w.visit}
                                    <ArrowUpRight className="size-3.5 rtl:-scale-x-100" />
                                </a>
                            </Button>
                        )}
                        <Button
                            onClick={onScan}
                            disabled={scanning || !canScan}
                        >
                            <RefreshCw
                                className={cn(
                                    'size-4',
                                    scanning && 'animate-spin',
                                )}
                            />
                            {scanning
                                ? c.scanProgress.running
                                : competitor.scan?.status === 'failed'
                                  ? c.scanProgress.retry
                                  : details.analysis
                                    ? w.refresh
                                    : c.scanNow}
                        </Button>
                    </div>
                </div>
                {competitor.notes && (
                    <details className="relative mt-4 border-t pt-3 text-xs text-muted-foreground">
                        <summary className="w-fit cursor-pointer hover:text-foreground">
                            {c.notes}
                        </summary>
                        <p className="mt-2 text-sm leading-relaxed">
                            {competitor.notes}
                        </p>
                    </details>
                )}
            </header>

            {errorMessage && (
                <p
                    role="alert"
                    className="rounded-xl border border-danger/25 bg-danger-soft/30 p-4 text-sm text-danger"
                >
                    {errorMessage}
                </p>
            )}
            {competitor.last_error && !competitor.scan && (
                <p role="alert" className="text-sm text-danger">
                    {competitor.last_error}
                </p>
            )}
            {competitor.scan_blocker === 'needs_apify' && (
                <p
                    role="status"
                    className="flex items-start gap-2 rounded-xl bg-info-soft/50 px-4 py-3 text-sm leading-relaxed text-info"
                >
                    <Unplug className="mt-0.5 size-4 shrink-0" />
                    {c.apifyMissing}
                </p>
            )}
            {scanning && !isScanning(competitor.scan) && (
                <p
                    role="status"
                    className="flex items-center gap-2 rounded-xl bg-muted/40 p-4 text-sm"
                >
                    <LoaderCircle className="size-4 animate-spin" />
                    {c.scanProgress.starting}
                </p>
            )}
            {competitor.scan && competitor.scan.status !== 'completed' && (
                <CompetitorScanProgress
                    scan={competitor.scan}
                    social={competitor.is_social}
                />
            )}
            {previousReport && (
                <p
                    role="status"
                    className="rounded-xl bg-warning-soft/40 px-4 py-3 text-sm text-warning"
                >
                    {c.scanProgress.previousReport}
                </p>
            )}

            <Tabs
                dir={dir}
                value={tab}
                onValueChange={setTab}
                className="min-w-0 gap-6"
            >
                <div
                    ref={tabsRef}
                    className="min-w-0 scroll-mt-20 [scrollbar-width:none] overflow-x-auto border-b border-border/70 [&::-webkit-scrollbar]:hidden"
                >
                    <TabsList
                        aria-label={w.eyebrow}
                        className="h-12 w-max min-w-full justify-start gap-3 rounded-none bg-transparent p-0 sm:min-w-0 sm:gap-6"
                    >
                        {navigation
                            .filter((n) => n.visible)
                            .map(({ value, label, icon: Icon, count }) => (
                                <TabsTrigger
                                    key={value}
                                    data-tab={value}
                                    value={value}
                                    className="h-12 flex-none gap-2 rounded-none border-b-2 border-transparent px-1 text-muted-foreground data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary data-[state=active]:shadow-none"
                                >
                                    <Icon className="size-4" />
                                    {label}
                                    {count !== undefined && (
                                        <span className="num rounded-md bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">
                                            {number(count)}
                                        </span>
                                    )}
                                </TabsTrigger>
                            ))}
                    </TabsList>
                </div>
                <TabsContent value="overview">
                    {social ? (
                        <CompetitorSocialOverview
                            analysis={social}
                            posts={details.posts}
                            onPosts={showPosts}
                        />
                    ) : store ? (
                        <CompetitorAnalysis
                            analysis={store}
                            products={details.products}
                            onBrowse={browse}
                            onSources={showSources}
                        />
                    ) : (
                        <div className="rounded-2xl border bg-card">
                            <EmptyState
                                icon={scanning ? RefreshCw : Swords}
                                title={scanning ? w.pendingTitle : w.emptyTitle}
                                description={
                                    scanning
                                        ? c.scanProgress.reportPending
                                        : competitor.scan?.status === 'failed'
                                          ? c.scanProgress.noReport
                                          : c.analysisEmpty
                                }
                                action={
                                    !scanning && canScan ? (
                                        <Button onClick={onScan}>
                                            <RefreshCw className="size-4" />
                                            {c.scanNow}
                                        </Button>
                                    ) : undefined
                                }
                            />
                        </div>
                    )}
                </TabsContent>
                <TabsContent value="products">
                    <CompetitorProducts
                        products={details.products}
                        total={store?.facts.products}
                        filter={filter}
                        onFilterChange={setFilter}
                    />
                </TabsContent>
                <TabsContent value="sources">
                    {store && <CompetitorSources analysis={store} />}
                </TabsContent>
                <TabsContent value="posts">
                    <SocialPostGrid posts={details.posts} />
                </TabsContent>
            </Tabs>
        </>
    );
}
