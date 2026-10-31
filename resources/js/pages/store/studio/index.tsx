import { Link, router, useForm, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    Camera,
    FileImage,
    Images,
    Lightbulb,
    Link2Off,
    Loader2,
    Palette,
    RefreshCw,
    Shirt,
    Smartphone,
    Sofa,
    Sparkles,
    Square,
    TrendingUp,
    WandSparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useState } from 'react';
import { ImageDrop } from '@/components/evaluation/image-drop';
import type { DesignItem, Modification, Recommendation } from '@/components/evaluation/types';
import InputError from '@/components/input-error';
import { ColorChips } from '@/components/mojo/color-chips';
import { DecisionBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { ScoreRing } from '@/components/mojo/score-ring';
import { SectionCard } from '@/components/mojo/section-card';
import { aspectClass, DesignCard } from '@/components/studio/design-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

type RequestType = 'modification' | 'new_idea' | 'mockup';
type OutputType = 'product_photo' | 'print_file' | 'mockup_white' | 'mockup_model' | 'lifestyle' | 'social';

type Source = {
    id: number;
    name: string;
    category: string | null;
    score: number | null;
    expected_score: number | null;
    recommendation: Recommendation | null;
    colors: string[];
    pattern: string | null;
    material: string | null;
    style: string | null;
    modifications: Modification[];
    image_url: string | null;
    prompt: string;
};

type DesignRequestRow = {
    id: number;
    type: RequestType;
    title: string | null;
    prompt: string;
    category: string | null;
    output_type: OutputType;
    aspect_ratio: string;
    variants_count: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    error: string | null;
    evaluation_id: number | null;
    created_at: string;
    designs: DesignItem[];
};

type Trend = { id: number; type: string; value: string | null; name_ar: string; name_en: string; momentum: number; saturation: number; in_catalog: boolean };

type Idea = {
    title_ar: string;
    title_en: string;
    description_ar: string;
    description_en: string;
    category: string | null;
    price: number | null;
    colors: string[];
    pattern: string | null;
    material: string | null;
    style: string | null;
    season: string | null;
    prompt: string;
    score: number;
    recommendation: Recommendation;
    from_model: boolean;
};

type Props = {
    prefillPrompt?: string | null;
    source: Source | null;
    brand: { colors?: string[]; fonts?: string[]; style_ar?: string; style_en?: string };
    trends: Trend[];
    categories: string[];
    requests: DesignRequestRow[];
    outputTypes: OutputType[];
    ideas?: Idea[];
};

const outputIcons: Record<OutputType, LucideIcon> = {
    product_photo: Camera,
    print_file: FileImage,
    mockup_white: Square,
    mockup_model: Shirt,
    lifestyle: Sofa,
    social: Smartphone,
};

const ratios = ['1:1', '4:5', '9:16', '16:9'];

export default function Studio({ source, prefillPrompt, brand, trends, categories, requests, outputTypes, ideas }: Props) {
    const { t, tr, field, relative, locale } = useI18n();
    const { label } = useVocabulary();
    const s = t.studio;
    const [loadingIdeas, setLoadingIdeas] = useState(false);
    const running = requests.some((r) => r.status === 'pending' || r.status === 'processing');

    const form = useForm({
        type: (source ? 'modification' : 'new_idea') as RequestType,
        title: source?.name ?? '',
        prompt: source?.prompt ?? prefillPrompt ?? '',
        category: source?.category ?? '',
        output_type: (source ? 'mockup_model' : 'product_photo') as OutputType,
        aspect_ratio: source ? '4:5' : '1:1',
        variants_count: 3,
        evaluation_id: source?.id ?? (null as number | null),
        source_image: null as File | null,
    });

    const appendToPrompt = (text: string) => form.setData('prompt', form.data.prompt ? `${form.data.prompt.trim()} ${text}` : text);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/store/studio', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('source_image'),
        });
    };

    const suggestIdeas = () =>
        router.reload({
            only: ['ideas'],
            onStart: () => setLoadingIdeas(true),
            onFinish: () => setLoadingIdeas(false),
        });

    const applyIdea = (idea: Idea) => {
        form.setData((data) => ({
            ...data,
            type: 'new_idea',
            evaluation_id: null,
            title: field(idea, 'title'),
            category: idea.category ?? data.category,
            prompt: `${field(idea, 'title')}. ${field(idea, 'description')} ${idea.prompt}`.trim(),
        }));
        document.getElementById('studio-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const evaluateIdea = (idea: Idea) => {
        const q = new URLSearchParams({
            name: field(idea, 'title'),
            ...(idea.category ? { category: idea.category } : {}),
            ...(idea.price ? { price: String(Math.round(idea.price)) } : {}),
            colors: idea.colors.join(','),
            ...(idea.pattern ? { pattern: idea.pattern } : {}),
            ...(idea.material ? { material: idea.material } : {}),
            ...(idea.style ? { style: idea.style } : {}),
            ...(idea.season ? { season: idea.season } : {}),
            description: field(idea, 'description'),
        });

        return `/store/evaluations/create?${q.toString()}`;
    };

    const brandStyle = locale === 'ar' ? brand.style_ar : brand.style_en;

    return (
        <PageBody>
            {running && <Poll />}
            <PageHeader
                title={s.title}
                description={s.subtitle}
                icon={WandSparkles}
                actions={
                    <Button variant="outline" asChild>
                        <Link href="/store/library">
                            <Images className="size-4" />
                            {s.openLibrary}
                        </Link>
                    </Button>
                }
            />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                {/* Request form */}
                <form id="studio-form" onSubmit={submit} className="space-y-5 xl:sticky xl:top-20 xl:self-start">
                    <SectionCard title={s.form.heading} icon={Sparkles}>
                        <div className="space-y-5">
                            <div className="grid grid-cols-3 gap-2">
                                {(['modification', 'new_idea', 'mockup'] as RequestType[]).map((type) => (
                                    <button
                                        key={type}
                                        type="button"
                                        disabled={type === 'modification' && !source}
                                        onClick={() => form.setData('type', type)}
                                        className={cn(
                                            'flex cursor-pointer flex-col gap-1 rounded-xl border p-3 text-start transition-all disabled:cursor-not-allowed disabled:opacity-40',
                                            form.data.type === type ? 'border-primary bg-primary/5 ring-primary/15 ring-2' : 'hover:border-primary/30',
                                        )}
                                    >
                                        <span className="text-sm font-medium">{s.form.types[type]}</span>
                                        <span className="text-muted-foreground text-[11px] leading-snug">{s.form.typeHints[type]}</span>
                                    </button>
                                ))}
                            </div>

                            <AnimatePresence>
                                {source && form.data.evaluation_id && (
                                    <motion.div
                                        initial={{ opacity: 0, height: 0 }}
                                        animate={{ opacity: 1, height: 'auto' }}
                                        exit={{ opacity: 0, height: 0 }}
                                        className="bg-gold-soft flex items-center gap-3 overflow-hidden rounded-xl p-3"
                                    >
                                        {source.image_url && <img src={source.image_url} alt="" className="size-12 rounded-lg object-cover" />}
                                        <div className="min-w-0 flex-1 text-xs leading-relaxed">
                                            {tr(s.form.fromEvaluation, { name: source.name, score: source.score ?? '—', expected: source.expected_score ?? '—' })}
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => form.setData((d) => ({ ...d, evaluation_id: null, type: d.type === 'modification' ? 'new_idea' : d.type }))}
                                            aria-label={s.form.clearSource}
                                        >
                                            <Link2Off className="size-4" />
                                        </Button>
                                    </motion.div>
                                )}
                            </AnimatePresence>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="title">{s.form.titleField}</Label>
                                    <Input id="title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder={s.form.titlePlaceholder} className="h-10" />
                                    <InputError message={form.errors.title} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="category">{s.form.category}</Label>
                                    <Input id="category" list="studio-categories" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} placeholder={s.form.categoryPlaceholder} className="h-10" />
                                    <datalist id="studio-categories">
                                        {categories.map((c) => (
                                            <option key={c} value={c} />
                                        ))}
                                    </datalist>
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="prompt">{s.form.prompt}</Label>
                                <Textarea id="prompt" rows={5} value={form.data.prompt} onChange={(e) => form.setData('prompt', e.target.value)} placeholder={s.form.promptPlaceholder} required />
                                <InputError message={form.errors.prompt} />

                                {(brand.colors?.length || brandStyle) && (
                                    <div className="flex flex-wrap items-center gap-1.5 pt-1">
                                        <span className="text-muted-foreground flex items-center gap-1 text-[11px]">
                                            <Palette className="size-3" />
                                            {s.form.chipsBrand}
                                        </span>
                                        {brand.colors?.map((hex) => (
                                            <button key={hex} type="button" onClick={() => appendToPrompt(hex)} className="hover:bg-muted flex cursor-pointer items-center gap-1 rounded-full border px-1.5 py-0.5 text-[11px]">
                                                <span className="size-3 rounded-full ring-1 ring-black/10" style={{ backgroundColor: hex }} />
                                                <span dir="ltr">{hex}</span>
                                            </button>
                                        ))}
                                        {brandStyle && (
                                            <button type="button" onClick={() => appendToPrompt(brandStyle)} className="hover:bg-muted max-w-full cursor-pointer truncate rounded-full border px-2 py-0.5 text-[11px]">
                                                {brandStyle}
                                            </button>
                                        )}
                                    </div>
                                )}
                                {!brand.colors?.length && !brandStyle && <p className="text-muted-foreground text-[11px]">{s.form.brandMissing}</p>}

                                {trends.length > 0 && (
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <span className="text-muted-foreground flex items-center gap-1 text-[11px]">
                                            <TrendingUp className="size-3" />
                                            {s.form.chipsTrends}
                                        </span>
                                        {trends.map((trend) => {
                                            const name = field(trend, 'name');

                                            return (
                                                <button
                                                    key={trend.id}
                                                    type="button"
                                                    onClick={() => appendToPrompt(name)}
                                                    className="bg-success-soft text-success hover:ring-success/30 cursor-pointer rounded-full px-2 py-0.5 text-[11px] hover:ring-2"
                                                >
                                                    {name}
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label>{s.form.outputType}</Label>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {outputTypes.map((type) => {
                                        const Icon = outputIcons[type] ?? Camera;

                                        return (
                                            <button
                                                key={type}
                                                type="button"
                                                onClick={() => form.setData('output_type', type)}
                                                className={cn(
                                                    'flex cursor-pointer items-center gap-2 rounded-xl border px-2.5 py-2 text-start text-xs transition-all',
                                                    form.data.output_type === type ? 'border-primary bg-primary/5 text-foreground font-medium' : 'text-muted-foreground hover:border-primary/30',
                                                )}
                                            >
                                                <Icon className="size-4 shrink-0" />
                                                {s.form.outputs[type]}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Segment label={s.form.aspect} options={ratios} value={form.data.aspect_ratio} onChange={(v) => form.setData('aspect_ratio', v)} ltr />
                                <Segment label={s.form.variants} options={['1', '2', '3', '4']} value={String(form.data.variants_count)} onChange={(v) => form.setData('variants_count', Number(v))} ltr />
                            </div>

                            <div className="grid gap-2">
                                <Label>{s.form.sourceImage}</Label>
                                <div className="flex items-start gap-3">
                                    <ImageDrop compact file={form.data.source_image} onChange={(f) => form.setData('source_image', f)} className="w-24 shrink-0" />
                                    <p className="text-muted-foreground text-xs leading-relaxed">
                                        {s.form.sourceHint}
                                        {form.data.evaluation_id && source?.image_url && !form.data.source_image && <span className="mt-1 block">{s.form.sourceFromEvaluation}</span>}
                                    </p>
                                </div>
                                <InputError message={form.errors.source_image} />
                            </div>

                            <Button type="submit" size="lg" variant="gold" className="h-11 w-full" disabled={form.processing}>
                                {form.processing ? <Spinner /> : <WandSparkles className="size-4" />}
                                {form.processing ? s.form.generating : s.form.generate}
                            </Button>
                        </div>
                    </SectionCard>
                </form>

                <div className="space-y-6">
                    {/* Proactive ideas (FR-4.4) */}
                    <SectionCard
                        title={s.ideas.title}
                        description={s.ideas.subtitle}
                        icon={Lightbulb}
                        action={
                            <Button size="sm" variant={ideas ? 'outline' : 'default'} onClick={suggestIdeas} disabled={loadingIdeas}>
                                {loadingIdeas ? <Loader2 className="size-3.5 animate-spin" /> : <Sparkles className="size-3.5" />}
                                {ideas ? s.ideas.refresh : s.ideas.suggest}
                            </Button>
                        }
                    >
                        {loadingIdeas ? (
                            <div className="grid gap-3 md:grid-cols-2">
                                {[0, 1, 2, 3].map((i) => (
                                    <div key={i} className="shimmer bg-muted/60 h-36 rounded-xl" />
                                ))}
                            </div>
                        ) : ideas?.length ? (
                            <Stagger className="grid gap-3 md:grid-cols-2">
                                {ideas.map((idea, i) => (
                                    <StaggerItem key={i} className="flex flex-col gap-3 rounded-xl border p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0 space-y-1">
                                                <h3 className="text-sm leading-snug font-semibold">{field(idea, 'title')}</h3>
                                                <p className="text-muted-foreground line-clamp-3 text-xs leading-relaxed">{field(idea, 'description')}</p>
                                            </div>
                                            <ScoreRing score={idea.score} size={48} stroke={5} className="shrink-0" />
                                        </div>
                                        <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
                                            <ColorChips colors={idea.colors} showLabels={false} />
                                            {idea.pattern && <Badge variant="neutral">{label('patterns', idea.pattern)}</Badge>}
                                            {idea.season && <Badge variant="neutral">{label('seasons', idea.season)}</Badge>}
                                            <DecisionBadge value={idea.recommendation} />
                                        </div>
                                        <div className="mt-auto flex items-center justify-between gap-2">
                                            <span className="text-muted-foreground text-[10px]">{idea.from_model ? s.ideas.byModel : s.ideas.byTemplate}</span>
                                            <div className="flex gap-1.5">
                                                <Button size="sm" variant="outline" asChild>
                                                    <Link href={evaluateIdea(idea)}>{s.ideas.evaluate}</Link>
                                                </Button>
                                                <Button size="sm" variant="soft" onClick={() => applyIdea(idea)}>
                                                    <WandSparkles className="size-3.5" />
                                                    {s.ideas.use}
                                                </Button>
                                            </div>
                                        </div>
                                    </StaggerItem>
                                ))}
                            </Stagger>
                        ) : (
                            <p className="text-muted-foreground py-4 text-center text-sm">{ideas ? t.common.noResults : s.ideas.empty}</p>
                        )}
                    </SectionCard>

                    {/* Requests with variants side by side (FR-4.9) */}
                    <div className="space-y-4">
                        <h2 className="text-[15px] font-semibold">{s.requests.title}</h2>
                        {requests.length === 0 ? (
                            <div className="bg-card rounded-2xl border">
                                <EmptyState icon={WandSparkles} title={s.requests.empty} />
                            </div>
                        ) : (
                            requests.map((request) => <RequestCard key={request.id} request={request} relative={relative} />)
                        )}
                    </div>
                </div>
            </div>
        </PageBody>
    );
}

function RequestCard({ request, relative }: { request: DesignRequestRow; relative: (v: string) => string }) {
    const { t, tr } = useI18n();
    const s = t.studio;
    const generating = request.status === 'pending' || request.status === 'processing';
    const statusTone = { pending: 'neutral', processing: 'info', completed: 'success', failed: 'danger' } as const;
    const cols = request.variants_count >= 4 ? 'sm:grid-cols-2 lg:grid-cols-4' : request.variants_count === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2';

    return (
        <motion.section layout initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} className="bg-card rounded-2xl border border-border/70 p-4 shadow-sm">
            <header className="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <h3 className="truncate text-sm font-semibold">{request.title ?? s.form.types[request.type]}</h3>
                    <div className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-[11px]">
                        <Badge variant="neutral">{s.form.types[request.type]}</Badge>
                        <span>{s.form.outputs[request.output_type]}</span>
                        <span>·</span>
                        <span dir="ltr">{request.aspect_ratio}</span>
                        <span>·</span>
                        <span>{tr(s.requests.variantsCount, { n: request.variants_count })}</span>
                        <span>·</span>
                        <span suppressHydrationWarning>{relative(request.created_at)}</span>
                        {request.evaluation_id && (
                            <Link href={`/store/evaluations/${request.evaluation_id}`} className="text-primary hover:underline">
                                {s.requests.fromEvaluation}
                            </Link>
                        )}
                    </div>
                </div>
                <Badge variant={statusTone[request.status]}>
                    {generating && <Loader2 className="animate-spin" />}
                    {s.requests.status[request.status]}
                </Badge>
            </header>

            {request.status === 'failed' && (
                <div className="bg-danger-soft text-danger mb-3 flex items-center justify-between gap-3 rounded-xl px-3 py-2 text-xs">
                    <span className="flex items-center gap-2">
                        <AlertTriangle className="size-4 shrink-0" />
                        {request.error}
                    </span>
                    <Button size="sm" variant="outline" onClick={() => router.post(`/store/studio/${request.id}/retry`, {}, { preserveScroll: true })}>
                        <RefreshCw className="size-3.5" />
                        {s.requests.retry}
                    </Button>
                </div>
            )}

            <div className={cn('grid gap-3', cols)}>
                {request.designs.map((design) => (
                    <DesignCard key={design.id} design={design} />
                ))}
                {generating &&
                    Array.from({ length: Math.max(0, request.variants_count - request.designs.length) }).map((_, i) => (
                        <div key={`p${i}`} className={cn('bg-muted/60 shimmer flex items-center justify-center rounded-2xl', aspectClass[request.aspect_ratio] ?? 'aspect-square')}>
                            <WandSparkles className="text-muted-foreground/50 size-6 animate-pulse" />
                        </div>
                    ))}
            </div>
            {generating && <p className="text-muted-foreground mt-3 text-center text-xs">{s.requests.generatingHint}</p>}
        </motion.section>
    );
}

function Segment({ label, options, value, onChange, ltr }: { label: string; options: string[]; value: string; onChange: (v: string) => void; ltr?: boolean }) {
    return (
        <div className="grid gap-2">
            <Label>{label}</Label>
            <div className="bg-muted flex rounded-xl p-1" dir={ltr ? 'ltr' : undefined}>
                {options.map((o) => (
                    <button
                        key={o}
                        type="button"
                        onClick={() => onChange(o)}
                        className={cn(
                            'relative flex-1 cursor-pointer rounded-lg py-1.5 text-xs font-medium transition-colors',
                            value === o ? 'text-foreground' : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {value === o && <motion.span layoutId={`seg-${label}`} className="bg-card absolute inset-0 rounded-lg shadow-sm" transition={{ type: 'spring', stiffness: 400, damping: 32 }} />}
                        <span className="relative">{o}</span>
                    </button>
                ))}
            </div>
        </div>
    );
}

function Poll() {
    usePoll(2500, { only: ['requests'] });

    return null;
}
