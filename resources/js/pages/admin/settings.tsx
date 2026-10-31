import { router, useForm } from '@inertiajs/react';
import { CalendarDays, Check, CircleCheck, CircleDashed, Cpu, Palette, Pencil, Plus, Scale, Settings2, SlidersHorizontal, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { EventDialog } from '@/components/admin/event-dialog';
import type { SeasonalEventRow } from '@/components/admin/event-dialog';
import { Field } from '@/components/admin/field';
import { SliderField } from '@/components/admin/slider-field';
import { ConfirmModal } from '@/components/admin/store-actions';
import { ColorChips } from '@/components/mojo/color-chips';
import { EmptyState } from '@/components/mojo/empty-state';
import { FadeIn } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

type Weights = Record<'success_similarity' | 'trend_fit' | 'saudi_fit' | 'failure_risk' | 'saturation' | 'price_fit', number>;

type Props = {
    defaults: { accept_threshold: number; reject_threshold: number; proven_ratio: number; sync_frequency: 'daily' | 'weekly'; weights: Weights };
    saudiPalette: string[];
    ai: {
        provider: string;
        live: boolean;
        key_configured: boolean;
        text_model: string;
        image_model: string;
        pricing: { model: string; input: number; output: number; image?: number }[];
    };
    events: SeasonalEventRow[];
};

export default function AdminSettings({ defaults, saudiPalette, ai, events }: Props) {
    const { t, tr, usd, field } = useI18n();
    const s = t.admin.settings;
    const { options, label } = useVocabulary();
    const [eventDialog, setEventDialog] = useState<{ event: SeasonalEventRow | null } | null>(null);
    const [deleting, setDeleting] = useState<SeasonalEventRow | null>(null);

    const form = useForm({ ...defaults, weights: { ...defaults.weights }, saudi_palette: saudiPalette });
    const weightTotal = Object.values(form.data.weights).reduce((a, b) => a + b, 0);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/admin/settings', { preserveScroll: true });
    };

    const togglePalette = (key: string) =>
        form.setData(
            'saudi_palette',
            form.data.saudi_palette.includes(key) ? form.data.saudi_palette.filter((c) => c !== key) : [...form.data.saudi_palette, key],
        );

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <PageBody>
            <PageHeader title={s.title} description={s.subtitle} icon={Settings2} />

            <form onSubmit={submit} className="space-y-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <FadeIn delay={0.05}>
                        <SectionCard title={s.decisionDefaults} description={s.decisionDefaultsHint} icon={Scale} className="h-full">
                            <div className="space-y-7">
                                <SliderField
                                    label={s.acceptThreshold}
                                    value={form.data.accept_threshold}
                                    min={50}
                                    max={100}
                                    onChange={(v) => form.setData('accept_threshold', v)}
                                    error={form.errors.accept_threshold}
                                    tone="text-success"
                                />
                                <SliderField
                                    label={s.rejectThreshold}
                                    value={form.data.reject_threshold}
                                    min={0}
                                    max={90}
                                    onChange={(v) => form.setData('reject_threshold', v)}
                                    error={form.errors.reject_threshold}
                                    tone="text-danger"
                                />
                                <SliderField
                                    label={s.provenRatio}
                                    value={form.data.proven_ratio}
                                    min={50}
                                    max={95}
                                    onChange={(v) => form.setData('proven_ratio', v)}
                                    error={form.errors.proven_ratio}
                                    suffix="%"
                                    hint={tr(t.admin.detail.provenRatioHint, { proven: form.data.proven_ratio, novel: 100 - form.data.proven_ratio })}
                                />
                                <Field label={s.syncFrequency} error={form.errors.sync_frequency} className="sm:max-w-xs">
                                    <Select value={form.data.sync_frequency} onValueChange={(v) => form.setData('sync_frequency', v as 'daily' | 'weekly')}>
                                        <SelectTrigger className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="daily">{s.daily}</SelectItem>
                                            <SelectItem value="weekly">{s.weekly}</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </Field>
                            </div>
                        </SectionCard>
                    </FadeIn>

                    <FadeIn delay={0.1}>
                        <SectionCard
                            title={s.weights}
                            description={s.weightsHint}
                            icon={SlidersHorizontal}
                            className="h-full"
                            action={<span className="text-muted-foreground num text-xs">{tr(s.weightsTotal, { total: weightTotal })}</span>}
                        >
                            <div className="space-y-5">
                                {(Object.keys(form.data.weights) as (keyof Weights)[]).map((key) => {
                                    const share = weightTotal ? Math.round((form.data.weights[key] / weightTotal) * 100) : 0;

                                    return (
                                        <SliderField
                                            key={key}
                                            label={s.criteria[key]}
                                            value={form.data.weights[key]}
                                            min={0}
                                            max={50}
                                            onChange={(v) => form.setData('weights', { ...form.data.weights, [key]: v })}
                                            error={errors[`weights.${key}`]}
                                            hint={`${share}%`}
                                        />
                                    );
                                })}
                                {errors.weights && <p className="text-danger text-sm">{errors.weights}</p>}
                            </div>
                        </SectionCard>
                    </FadeIn>
                </div>

                <FadeIn delay={0.15}>
                    <SectionCard title={s.palette} description={s.paletteHint} icon={Palette}>
                        <div className="flex flex-wrap gap-2">
                            {options('colors').map((o) => {
                                const on = form.data.saudi_palette.includes(o.value);

                                return (
                                    <button
                                        key={o.value}
                                        type="button"
                                        onClick={() => togglePalette(o.value)}
                                        className={cn(
                                            'flex cursor-pointer items-center gap-2 rounded-full border py-1 ps-1 pe-3 text-sm transition-all',
                                            on ? 'border-primary bg-primary/8 shadow-sm' : 'text-muted-foreground hover:bg-muted border-border',
                                        )}
                                    >
                                        <span
                                            className="flex size-6 items-center justify-center rounded-full ring-1 ring-black/10 ring-inset"
                                            style={{ backgroundColor: o.hex }}
                                        >
                                            {on && <Check className="size-3.5 text-white mix-blend-difference" />}
                                        </span>
                                        {o.label}
                                    </button>
                                );
                            })}
                        </div>
                        {errors.saudi_palette && <p className="text-danger mt-2 text-sm">{errors.saudi_palette}</p>}
                    </SectionCard>
                </FadeIn>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing || !form.isDirty}>
                        {form.processing && <Spinner />}
                        {s.save}
                    </Button>
                </div>
            </form>

            <div className="grid gap-6 lg:grid-cols-5">
                <FadeIn delay={0.2} className="lg:col-span-2">
                    <SectionCard title={s.ai} description={s.aiHint} icon={Cpu} className="h-full">
                        <dl className="space-y-3 text-sm">
                            <InfoRow label={s.provider} value={<span className="capitalize">{ai.provider}</span>} />
                            <InfoRow label={s.textModel} value={<code dir="ltr" className="text-xs">{ai.text_model}</code>} />
                            <InfoRow label={s.imageModel} value={<code dir="ltr" className="text-xs">{ai.image_model}</code>} />
                            <InfoRow
                                label={s.apiKey}
                                value={
                                    ai.key_configured ? (
                                        <span className="text-success inline-flex items-center gap-1">
                                            <CircleCheck className="size-4" />
                                            {s.keyConfigured}
                                        </span>
                                    ) : (
                                        <span className="text-gold-foreground dark:text-gold inline-flex items-center gap-1 text-xs">
                                            <CircleDashed className="size-4" />
                                            {s.keyMissing}
                                        </span>
                                    )
                                }
                            />
                        </dl>
                    </SectionCard>
                </FadeIn>

                <FadeIn delay={0.25} className="lg:col-span-3">
                    <SectionCard title={s.pricing} flush className="h-full">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t.admin.costs.model}</TableHead>
                                    <TableHead className="text-end">{s.inputPrice}</TableHead>
                                    <TableHead className="text-end">{s.outputPrice}</TableHead>
                                    <TableHead className="text-end">{s.imagePrice}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {ai.pricing
                                    .filter((p) => p.model !== 'fake')
                                    .map((p) => (
                                        <TableRow key={p.model} className={cn((p.model === ai.text_model || p.model === ai.image_model) && 'bg-gold-soft/50')}>
                                            <TableCell dir="ltr" className="text-start font-mono text-xs">
                                                {p.model}
                                            </TableCell>
                                            <TableCell className="num text-end text-xs">{usd(p.input)}</TableCell>
                                            <TableCell className="num text-end text-xs">{usd(p.output)}</TableCell>
                                            <TableCell className="num text-end text-xs">{p.image !== undefined ? usd(p.image, 4) : '—'}</TableCell>
                                        </TableRow>
                                    ))}
                            </TableBody>
                        </Table>
                    </SectionCard>
                </FadeIn>
            </div>

            <FadeIn delay={0.3}>
                <SectionCard
                    title={s.calendar}
                    description={s.calendarHint}
                    icon={CalendarDays}
                    action={
                        <Button size="sm" onClick={() => setEventDialog({ event: null })}>
                            <Plus className="size-4" />
                            {s.addEvent}
                        </Button>
                    }
                >
                    {events.length === 0 ? (
                        <EmptyState icon={CalendarDays} title={s.noEvents} className="py-8" />
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {events.map((ev) => (
                                <EventCard
                                    key={ev.id}
                                    event={ev}
                                    name={field(ev, 'name')}
                                    seasonLabel={ev.season_key ? label('seasons', ev.season_key) : null}
                                    onEdit={() => setEventDialog({ event: ev })}
                                    onDelete={() => setDeleting(ev)}
                                />
                            ))}
                        </div>
                    )}
                </SectionCard>
            </FadeIn>

            <EventDialog open={eventDialog !== null} onOpenChange={(open) => !open && setEventDialog(null)} event={eventDialog?.event ?? null} />
            <ConfirmModal
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={s.deleteEvent}
                description={deleting ? field(deleting, 'name') : ''}
                confirmLabel={t.common.delete}
                destructive
                onConfirm={(done) =>
                    router.delete(`/admin/settings/events/${deleting?.id}`, { preserveScroll: true, onSuccess: () => setDeleting(null), onFinish: done })
                }
            />
        </PageBody>
    );
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

function EventCard({
    event,
    name,
    seasonLabel,
    onEdit,
    onDelete,
}: {
    event: SeasonalEventRow;
    name: string;
    seasonLabel: string | null;
    onEdit: () => void;
    onDelete: () => void;
}) {
    const { t, tr, date } = useI18n();
    const s = t.admin.settings;
    const upcoming = new Date(event.ends_on) >= new Date();

    return (
        <div className={cn('group bg-background/60 relative rounded-xl border p-4 transition-shadow hover:shadow-sm', !event.is_active && 'opacity-60')}>
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate font-medium">{name}</p>
                    <p className="text-muted-foreground text-xs">
                        {date(event.starts_on, { day: 'numeric', month: 'short', year: 'numeric' })}
                        {event.ends_on !== event.starts_on && ` – ${date(event.ends_on, { day: 'numeric', month: 'short' })}`}
                    </p>
                </div>
                <div className="flex shrink-0 gap-1 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100">
                    <Button variant="ghost" size="icon" className="size-7" onClick={onEdit} aria-label={s.editEvent}>
                        <Pencil className="size-3.5" />
                    </Button>
                    <Button variant="ghost" size="icon" className="text-danger hover:text-danger size-7" onClick={onDelete} aria-label={s.deleteEvent}>
                        <Trash2 className="size-3.5" />
                    </Button>
                </div>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-2 text-xs">
                {seasonLabel && <span className="bg-primary/8 text-primary rounded-md px-1.5 py-0.5">{seasonLabel}</span>}
                <span className="text-muted-foreground">{tr(s.prepWeeksValue, { n: event.prep_weeks })}</span>
                {!upcoming && <span className="text-muted-foreground">· {t.common.inactive}</span>}
            </div>
            {event.palette && event.palette.length > 0 && <ColorChips colors={event.palette} showLabels={false} className="mt-3" />}
        </div>
    );
}
