import { Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, CalendarPlus, Check, Clock, Pencil, Rocket, Sparkles, Trash2 } from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { ColorChips } from '@/components/mojo/color-chips';
import { ConfirmDialog } from '@/components/mojo/confirm-dialog';
import { EmptyState } from '@/components/mojo/empty-state';
import { FadeIn } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

type Event = {
    id: number;
    season_key: string | null;
    name_ar: string;
    name_en: string;
    starts_on: string;
    ends_on: string;
    prep_weeks: number;
    launch_on: string;
    days_until: number;
    days_to_launch: number;
    palette: string[];
    notes: string | null;
    is_global: boolean;
    is_active: boolean;
    phase: 'prep' | 'live' | 'upcoming' | 'past';
    ready: number;
};

const phaseStyle: Record<Event['phase'], string> = {
    prep: 'bg-gold-soft text-gold-foreground dark:text-gold',
    live: 'bg-success-soft text-success',
    upcoming: 'bg-info-soft text-info',
    past: 'bg-muted text-muted-foreground',
};

const NONE = '__none';

export default function Calendar({ events, seasons }: { events: Event[]; seasons: string[] }) {
    const { t } = useI18n();
    const [showPast, setShowPast] = useState(false);
    const [editing, setEditing] = useState<Event | 'new' | null>(null);

    const upcoming = events.filter((e) => e.phase !== 'past');
    const past = events.filter((e) => e.phase === 'past');
    const next = upcoming.find((e) => e.phase !== 'live') ?? upcoming[0];

    return (
        <PageBody>
            <PageHeader
                title={t.calendar.title}
                description={t.calendar.description}
                icon={CalendarDays}
                actions={
                    <Button onClick={() => setEditing('new')}>
                        <CalendarPlus className="size-4" />
                        {t.calendar.add}
                    </Button>
                }
            />

            {next && <NextUp event={next} />}

            {upcoming.length === 0 ? (
                <EmptyState icon={CalendarDays} title={t.calendar.empty} description={t.calendar.emptyHint} />
            ) : (
                <Timeline events={upcoming} onEdit={setEditing} />
            )}

            {past.length > 0 && (
                <div className="space-y-4">
                    <Button variant="ghost" size="sm" onClick={() => setShowPast((v) => !v)} className="text-muted-foreground">
                        <Clock className="size-4" />
                        {showPast ? t.calendar.hidePast : t.calendar.showPast} ({past.length})
                    </Button>
                    {showPast && <Timeline events={past} onEdit={setEditing} />}
                </div>
            )}

            <EventDialog key={editing === 'new' ? 'new' : (editing?.id ?? 'closed')} event={editing} seasons={seasons} onClose={() => setEditing(null)} />
        </PageBody>
    );
}

function NextUp({ event }: { event: Event }) {
    const { t, tr, field, date } = useI18n();
    const launchReached = event.days_to_launch <= 0;
    const prepDays = event.prep_weeks * 7;
    const progress = prepDays > 0 ? Math.max(0, Math.min(100, ((prepDays - Math.max(0, event.days_until)) / prepDays) * 100)) : 100;

    return (
        <FadeIn>
            <section className="relative overflow-hidden rounded-2xl bg-[oklch(0.22_0.045_257)] p-6 text-white shadow-lg md:p-8">
                <div className="bg-sadu pointer-events-none absolute inset-0 text-white/[0.035]" />
                <div className="pointer-events-none absolute -top-24 -end-24 size-72 rounded-full bg-[radial-gradient(circle,oklch(0.78_0.11_82/0.3),transparent_65%)]" />
                <div className="relative grid gap-6 md:grid-cols-[1fr_auto] md:items-center">
                    <div className="space-y-3">
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs text-[#EADBB4]">
                            <Sparkles className="size-3.5" />
                            {t.calendar.nextUp}
                        </span>
                        <h2 className="text-3xl font-semibold">{field(event, 'name')}</h2>
                        <p className="text-sm text-white/70">
                            {date(event.starts_on)} — {date(event.ends_on)} · {tr(t.calendar.prepWindow, { weeks: event.prep_weeks })}
                        </p>
                        <div className="max-w-md space-y-1.5">
                            <div className="h-2 overflow-hidden rounded-full bg-white/10">
                                <motion.div
                                    className="h-full rounded-full bg-gradient-to-l from-[#EADBB4] to-[#C9A24A]"
                                    initial={{ width: 0 }}
                                    animate={{ width: `${progress}%` }}
                                    transition={{ duration: 1.2, ease: [0.22, 1, 0.36, 1] }}
                                />
                            </div>
                            <div className="flex justify-between text-xs text-white/60">
                                <span>
                                    {t.calendar.launchOn}: {date(event.launch_on)}
                                </span>
                                <span>{launchReached ? t.calendar.launchNow : tr(t.calendar.launchIn, { days: event.days_to_launch })}</span>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-3 pt-1">
                            {event.palette.length > 0 && <ColorChips colors={event.palette} showLabels={false} size="md" />}
                            <span className="text-xs text-white/70">{event.ready ? tr(t.calendar.ready, { count: event.ready }) : t.calendar.noneReady}</span>
                        </div>
                    </div>
                    <div className="flex flex-col items-center gap-3">
                        <div className="flex size-32 flex-col items-center justify-center rounded-3xl bg-white/[0.07] ring-1 ring-white/15">
                            <motion.span
                                initial={{ scale: 0.6, opacity: 0 }}
                                animate={{ scale: 1, opacity: 1 }}
                                transition={{ type: 'spring', stiffness: 200, damping: 16, delay: 0.2 }}
                                className="num text-5xl font-semibold text-[#EADBB4]"
                            >
                                {Math.max(0, event.days_until)}
                            </motion.span>
                            <span className="text-xs text-white/60">{t.calendar.daysLeft}</span>
                        </div>
                        {event.season_key && (
                            <Button asChild variant="gold" size="sm">
                                <Link href={`/store/evaluations/create?season=${event.season_key}`}>
                                    <Rocket className="size-4" />
                                    {t.calendar.prepare}
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            </section>
        </FadeIn>
    );
}

function Timeline({ events, onEdit }: { events: Event[]; onEdit: (e: Event) => void }) {
    const { t, tr, field, date } = useI18n();

    return (
        <ol className="relative space-y-4 ps-8 before:absolute before:inset-y-2 before:start-3 before:w-px before:bg-border">
            {events.map((event, i) => (
                <motion.li
                    key={event.id}
                    initial={{ opacity: 0, x: 12 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ delay: Math.min(i * 0.05, 0.5), duration: 0.4 }}
                    className="relative"
                >
                    <span
                        className={cn(
                            'absolute -start-[1.65rem] top-5 size-3.5 rounded-full ring-4 ring-background',
                            event.phase === 'live' ? 'bg-success' : event.phase === 'prep' ? 'bg-gold' : event.phase === 'past' ? 'bg-muted-foreground/40' : 'bg-primary',
                        )}
                    />
                    <div className={cn('bg-card rounded-2xl border border-border/70 p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] md:p-5', event.phase === 'past' && 'opacity-70')}>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h3 className="font-semibold">{field(event, 'name')}</h3>
                                    <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium', phaseStyle[event.phase])}>{t.calendar.phase[event.phase]}</span>
                                    <span className="text-muted-foreground text-[11px]">{event.is_global ? t.calendar.platform : t.calendar.custom}</span>
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    {date(event.starts_on)}
                                    {event.ends_on !== event.starts_on && <> — {date(event.ends_on)}</>}
                                </p>
                            </div>
                            <div className="text-end">
                                <div className="num text-sm font-semibold">
                                    {event.days_until >= 0 ? tr(t.calendar.startsIn, { days: event.days_until }) : tr(t.calendar.startedAgo, { days: -event.days_until })}
                                </div>
                                {event.phase !== 'past' && (
                                    <div className={cn('text-xs', event.days_to_launch <= 0 ? 'text-gold-foreground dark:text-gold font-medium' : 'text-muted-foreground')}>
                                        {t.calendar.launchOn}: {date(event.launch_on)}
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
                            <span className="text-muted-foreground">{tr(t.calendar.prepWindow, { weeks: event.prep_weeks })}</span>
                            {event.palette.length > 0 && (
                                <span className="flex items-center gap-2">
                                    <span className="text-muted-foreground">{t.calendar.palette}</span>
                                    <ColorChips colors={event.palette} showLabels={false} />
                                </span>
                            )}
                            {event.season_key && (
                                <span className={cn('flex items-center gap-1', event.ready ? 'text-success' : 'text-muted-foreground')}>
                                    {event.ready ? <Check className="size-3.5" /> : null}
                                    {event.ready ? tr(t.calendar.ready, { count: event.ready }) : t.calendar.noneReady}
                                </span>
                            )}
                            <span className="ms-auto flex items-center gap-1">
                                {event.season_key && event.phase !== 'past' && (
                                    <Button asChild size="sm" variant="ghost" className="h-7">
                                        <Link href={`/store/evaluations/create?season=${event.season_key}`}>
                                            <Rocket className="size-3.5" />
                                            {t.calendar.prepare}
                                        </Link>
                                    </Button>
                                )}
                                {!event.is_global && (
                                    <>
                                        <Button size="icon" variant="ghost" className="size-7" onClick={() => onEdit(event)} aria-label={t.calendar.edit}>
                                            <Pencil className="size-3.5" />
                                        </Button>
                                        <ConfirmDialog
                                            title={t.calendar.deleteTitle}
                                            destructive
                                            confirmLabel={t.common.delete}
                                            onConfirm={() =>
                                                new Promise<void>((resolve) => router.delete(`/store/calendar/${event.id}`, { preserveScroll: true, onFinish: () => resolve() }))
                                            }
                                            trigger={
                                                <Button size="icon" variant="ghost" className="text-danger hover:text-danger size-7" aria-label={t.common.delete}>
                                                    <Trash2 className="size-3.5" />
                                                </Button>
                                            }
                                        />
                                    </>
                                )}
                            </span>
                        </div>
                        {event.notes && <p className="text-muted-foreground mt-2 text-xs leading-relaxed">{event.notes}</p>}
                    </div>
                </motion.li>
            ))}
        </ol>
    );
}

function EventDialog({ event, seasons, onClose }: { event: Event | 'new' | null; seasons: string[]; onClose: () => void }) {
    const { t } = useI18n();
    const { label, options } = useVocabulary();
    const existing = event && event !== 'new' ? event : null;

    const form = useForm({
        name_ar: existing?.name_ar ?? '',
        name_en: existing?.name_en ?? '',
        season_key: existing?.season_key ?? '',
        starts_on: existing?.starts_on ?? '',
        ends_on: existing?.ends_on ?? '',
        prep_weeks: existing?.prep_weeks ?? 4,
        palette: existing?.palette ?? ([] as string[]),
        notes: existing?.notes ?? '',
    });

    const togglePalette = (key: string) =>
        form.setData('palette', form.data.palette.includes(key) ? form.data.palette.filter((c) => c !== key) : [...form.data.palette, key].slice(0, 6));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, season_key: data.season_key || null }));
        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (existing) {
            form.put(`/store/calendar/${existing.id}`, options);
        } else {
            form.post('/store/calendar', options);
        }
    };

    return (
        <Dialog open={event !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>{existing ? t.calendar.edit : t.calendar.add}</DialogTitle>
                        <DialogDescription>{t.calendar.description}</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name_ar">{t.calendar.form.nameAr}</Label>
                            <Input id="name_ar" dir="rtl" value={form.data.name_ar} onChange={(e) => form.setData('name_ar', e.target.value)} required />
                            <InputError message={form.errors.name_ar} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="name_en">{t.calendar.form.nameEn}</Label>
                            <Input id="name_en" dir="ltr" value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} required />
                            <InputError message={form.errors.name_en} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="starts_on">{t.calendar.form.startsOn}</Label>
                            <Input id="starts_on" type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} required />
                            <InputError message={form.errors.starts_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ends_on">{t.calendar.form.endsOn}</Label>
                            <Input id="ends_on" type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} required />
                            <InputError message={form.errors.ends_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label>{t.calendar.form.season}</Label>
                            <Select value={form.data.season_key || NONE} onValueChange={(v) => form.setData('season_key', v === NONE ? '' : v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>{t.calendar.form.none}</SelectItem>
                                    {seasons.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {label('seasons', s)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="prep_weeks">{t.calendar.form.prepWeeks}</Label>
                            <Input
                                id="prep_weeks"
                                type="number"
                                min={0}
                                max={26}
                                value={form.data.prep_weeks}
                                onChange={(e) => form.setData('prep_weeks', Number(e.target.value))}
                            />
                            <InputError message={form.errors.prep_weeks} />
                        </div>
                        <div className="grid gap-2 sm:col-span-2">
                            <Label>{t.calendar.form.palette}</Label>
                            <div className="flex flex-wrap gap-2">
                                {options('colors').map((c) => {
                                    const on = form.data.palette.includes(c.value);

                                    return (
                                        <button
                                            key={c.value}
                                            type="button"
                                            title={c.label}
                                            onClick={() => togglePalette(c.value)}
                                            className={cn(
                                                'relative size-7 cursor-pointer rounded-full ring-1 ring-black/10 transition-transform hover:scale-110',
                                                on && 'ring-primary ring-offset-background ring-2 ring-offset-2',
                                            )}
                                            style={{ backgroundColor: c.hex }}
                                        >
                                            {on && <Check className="absolute inset-0 m-auto size-3.5 text-white mix-blend-difference" />}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="notes">{t.calendar.form.notes}</Label>
                            <Textarea id="notes" rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t.common.cancel}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {t.common.save}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
