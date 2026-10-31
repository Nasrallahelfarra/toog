import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/admin/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

export type SeasonalEventRow = {
    id: number;
    season_key: string | null;
    name_ar: string;
    name_en: string;
    starts_on: string;
    ends_on: string;
    prep_weeks: number;
    palette: string[] | null;
    notes: string | null;
    is_active: boolean;
};

const NONE = '__none__';

/**
 * Add or edit a platform-wide seasonal event.
 */
export function EventDialog({ open, onOpenChange, event }: { open: boolean; onOpenChange: (open: boolean) => void; event: SeasonalEventRow | null }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <EventForm event={event} onDone={() => onOpenChange(false)} />
            </DialogContent>
        </Dialog>
    );
}

function EventForm({ event, onDone }: { event: SeasonalEventRow | null; onDone: () => void }) {
    const { t } = useI18n();
    const s = t.admin.settings;
    const { options } = useVocabulary();

    const form = useForm({
        season_key: event?.season_key ?? NONE,
        name_ar: event?.name_ar ?? '',
        name_en: event?.name_en ?? '',
        starts_on: event?.starts_on?.slice(0, 10) ?? '',
        ends_on: event?.ends_on?.slice(0, 10) ?? '',
        prep_weeks: event?.prep_weeks ?? 6,
        palette: event?.palette ?? ([] as string[]),
        is_active: event?.is_active ?? true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, season_key: data.season_key === NONE ? null : data.season_key }));
        const options = { preserveScroll: true, onSuccess: onDone };

        if (event) {
            form.put(`/admin/settings/events/${event.id}`, options);
        } else {
            form.post('/admin/settings/events', options);
        }
    };

    const togglePalette = (key: string) =>
        form.setData('palette', form.data.palette.includes(key) ? form.data.palette.filter((c) => c !== key) : [...form.data.palette, key]);

    return (
        <form onSubmit={submit} className="space-y-5">
            <DialogHeader>
                <DialogTitle>{event ? s.editEvent : s.addEvent}</DialogTitle>
            </DialogHeader>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={s.eventNameAr} error={form.errors.name_ar}>
                    <Input dir="rtl" value={form.data.name_ar} onChange={(e) => form.setData('name_ar', e.target.value)} required />
                </Field>
                <Field label={s.eventNameEn} error={form.errors.name_en}>
                    <Input dir="ltr" value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} required />
                </Field>
                <Field label={s.seasonKey} error={form.errors.season_key}>
                    <Select value={form.data.season_key} onValueChange={(v) => form.setData('season_key', v)}>
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>{s.noSeason}</SelectItem>
                            {options('seasons').map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field label={s.prepWeeks} error={form.errors.prep_weeks}>
                    <Input type="number" min={1} max={26} dir="ltr" value={form.data.prep_weeks} onChange={(e) => form.setData('prep_weeks', Number(e.target.value))} />
                </Field>
                <Field label={s.startsOn} error={form.errors.starts_on}>
                    <Input type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} required />
                </Field>
                <Field label={s.endsOn} error={form.errors.ends_on}>
                    <Input type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} required />
                </Field>
                <Field label={s.eventPalette} error={form.errors.palette} className="sm:col-span-2">
                    <div className="flex flex-wrap gap-1.5">
                        {options('colors').map((o) => {
                            const on = form.data.palette.includes(o.value);

                            return (
                                <button
                                    key={o.value}
                                    type="button"
                                    onClick={() => togglePalette(o.value)}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-1.5 rounded-full border py-0.5 ps-0.5 pe-2 text-xs transition-colors',
                                        on ? 'border-primary bg-primary/8 text-foreground' : 'text-muted-foreground hover:bg-muted',
                                    )}
                                >
                                    <span className="size-4 rounded-full ring-1 ring-black/10 ring-inset" style={{ backgroundColor: o.hex }} />
                                    {o.label}
                                </button>
                            );
                        })}
                    </div>
                </Field>
                <label className="flex items-center gap-3 text-sm sm:col-span-2">
                    <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                    {s.isActive}
                </label>
            </div>
            <DialogFooter className="gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    {t.common.cancel}
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {t.common.save}
                </Button>
            </DialogFooter>
        </form>
    );
}
