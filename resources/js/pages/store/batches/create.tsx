import { Link, useForm } from '@inertiajs/react';
import { ArrowRight, ClipboardPaste, Layers, Plus, Sparkles, Trash2 } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useState } from 'react';
import { AttributeSelect, ColorPicker } from '@/components/evaluation/attribute-select';
import { ImageDrop } from '@/components/evaluation/image-drop';
import InputError from '@/components/input-error';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';

type Row = {
    name: string;
    category: string;
    price: string;
    material: string | null;
    colors: string[];
    pattern: string | null;
    style: string | null;
    season: string | null;
    image: File | null;
};

const blank = (): Row => ({ name: '', category: '', price: '', material: null, colors: [], pattern: null, style: null, season: null, image: null });

export default function BatchCreate({ categories }: { categories: string[] }) {
    const { t, tr } = useI18n();
    const { vocabulary } = useVocabulary();
    const b = t.evaluation.batch;
    const f = t.evaluation.form;
    const fields = t.evaluation.mods.fields;
    const removeRow = (i: number) => form.setData('rows', form.data.rows.filter((_, j) => j !== i));
    const [csv, setCsv] = useState('');
    const [showCsv, setShowCsv] = useState(false);

    const form = useForm<{ name: string; rows: Row[] }>({ name: '', rows: [blank(), blank()] });
    const errors = form.errors as Record<string, string | undefined>;

    const update = (i: number, patch: Partial<Row>) => form.setData('rows', form.data.rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    // name, category, price, material, colours (a;b), pattern, style, season
    const parseCsv = () => {
        const known = (group: keyof NonNullable<typeof vocabulary>, v?: string) => {
            const key = v?.trim().toLowerCase().replace(/\s+/g, '_');

            return key && vocabulary?.[group]?.[key] ? key : null;
        };

        const rows = csv
            .split(/\r?\n/)
            .map((line) => line.split(/\t|,/).map((c) => c.trim()))
            .filter((cells) => cells[0])
            .map<Row>((cells) => ({
                name: cells[0],
                category: cells[1] ?? '',
                price: cells[2]?.replace(/[^\d.]/g, '') ?? '',
                material: known('materials', cells[3]),
                colors: (cells[4] ?? '').split(';').map((c) => known('colors', c)).filter((c): c is string => !!c).slice(0, 4),
                pattern: known('patterns', cells[5]),
                style: known('styles', cells[6]),
                season: known('seasons', cells[7]),
                image: null,
            }));

        if (rows.length) {
            form.setData('rows', [...form.data.rows.filter((r) => r.name), ...rows].slice(0, 30));
            setCsv('');
            setShowCsv(false);
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/store/batches', { forceFormData: true });
    };

    return (
        <PageBody className="max-w-6xl">
            <PageHeader
                title={b.createTitle}
                description={b.createSubtitle}
                icon={Layers}
                actions={
                    <Button variant="ghost" asChild>
                        <Link href="/store/evaluations">
                            <ArrowRight className="size-4 ltr:rotate-180" />
                            {t.common.back}
                        </Link>
                    </Button>
                }
            />

            {/* Phones: bottom padding so the fixed action bar never covers the last product. */}
            <form onSubmit={submit} className="space-y-6 pb-24 md:pb-0">
                <SectionCard>
                    <div className="grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
                        <div className="grid gap-2">
                            <Label htmlFor="batch-name">{b.name}</Label>
                            <Input id="batch-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={b.namePlaceholder} className="h-10" required />
                            <InputError message={form.errors.name} />
                        </div>
                        <Button type="button" variant="outline" onClick={() => setShowCsv((v) => !v)}>
                            <ClipboardPaste className="size-4" />
                            {b.pasteCsv}
                        </Button>
                    </div>

                    <AnimatePresence>
                        {showCsv && (
                            <motion.div initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }} className="overflow-hidden">
                                <div className="mt-4 space-y-2">
                                    <p className="text-muted-foreground text-xs">{b.pasteHint}</p>
                                    <Textarea
                                        rows={5}
                                        dir="ltr"
                                        value={csv}
                                        onChange={(e) => setCsv(e.target.value)}
                                        className="font-mono text-xs"
                                        placeholder={'Sage abaya, عبايات, 360, linen, sage;ivory, solid, minimal, ramadan'}
                                    />
                                    <Button type="button" size="sm" onClick={parseCsv} disabled={!csv.trim()}>
                                        {b.parse}
                                    </Button>
                                </div>
                            </motion.div>
                        )}
                    </AnimatePresence>
                </SectionCard>

                <datalist id="batch-categories">
                    {categories.map((c) => (
                        <option key={c} value={c} />
                    ))}
                </datalist>

                <div className="space-y-3">
                    <h2 className="text-sm font-semibold">
                        {b.rows} <span className="text-muted-foreground num">({form.data.rows.length})</span>
                    </h2>
                    <AnimatePresence initial={false}>
                        {form.data.rows.map((row, i) => (
                            <motion.div
                                key={i}
                                layout
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, scale: 0.97 }}
                                className="bg-card grid gap-4 rounded-2xl border border-border/70 p-4 md:grid-cols-[96px_1fr]"
                            >
                                {/* Phone: a numbered header with delete in reach at the inline end. */}
                                <div className="-mt-1 -mb-2 flex items-center justify-between gap-2 sm:hidden">
                                    <h3 className="text-sm font-semibold">{tr(b.rowN, { n: i + 1 })}</h3>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="-me-2 size-11"
                                        disabled={form.data.rows.length <= 1}
                                        onClick={() => removeRow(i)}
                                        aria-label={b.removeRow}
                                    >
                                        <Trash2 className="text-danger size-4" />
                                    </Button>
                                </div>
                                <ImageDrop compact file={row.image} onChange={(image) => update(i, { image })} className="w-24" />
                                <div className="space-y-3">
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-[2fr_1fr_1fr_auto]">
                                        <div className="col-span-2 grid gap-1 sm:col-span-1">
                                            <Input value={row.name} onChange={(e) => update(i, { name: e.target.value })} placeholder={f.name} className="h-9" />
                                            <InputError message={errors[`rows.${i}.name`]} />
                                        </div>
                                        <Input value={row.category} onChange={(e) => update(i, { category: e.target.value })} list="batch-categories" placeholder={f.category} className="h-9" />
                                        <Input value={row.price} onChange={(e) => update(i, { price: e.target.value })} type="number" min={0} placeholder={f.price} className="h-9" dir="ltr" />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="hidden sm:inline-flex"
                                            disabled={form.data.rows.length <= 1}
                                            onClick={() => removeRow(i)}
                                            aria-label={b.removeRow}
                                        >
                                            <Trash2 className="text-danger size-4" />
                                        </Button>
                                    </div>
                                    {/* Labels show on phones only; on wider screens they stay for screen readers. */}
                                    <div className="grid grid-cols-2 gap-x-2 gap-y-3 sm:grid-cols-4 sm:gap-2">
                                        {(
                                            [
                                                ['materials', 'material', fields.material],
                                                ['patterns', 'pattern', fields.pattern],
                                                ['styles', 'style', fields.style],
                                                ['seasons', 'season', fields.season],
                                            ] as const
                                        ).map(([group, key, label]) => (
                                            <div key={group} className="grid min-w-0 gap-1">
                                                <Label htmlFor={`row-${i}-${key}`} className="text-muted-foreground text-xs sm:sr-only">
                                                    {label}
                                                </Label>
                                                <AttributeSelect
                                                    id={`row-${i}-${key}`}
                                                    group={group}
                                                    value={row[key]}
                                                    onChange={(value) => update(i, { [key]: value })}
                                                    className="h-10 sm:h-9"
                                                />
                                            </div>
                                        ))}
                                    </div>
                                    <ColorPicker size="sm" value={row.colors} onChange={(colors) => update(i, { colors })} />
                                </div>
                            </motion.div>
                        ))}
                    </AnimatePresence>
                    <InputError message={form.errors.rows} />
                </div>

                {/* Phones: a floating bar keeps add and submit in thumb reach; inline from md up. */}
                <div className="bg-card fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-30 flex items-center gap-2 rounded-2xl border p-2 shadow-lg md:static md:flex-wrap md:justify-between md:gap-3 md:rounded-none md:border-0 md:bg-transparent md:p-0 md:shadow-none">
                    <Button type="button" variant="outline" className="h-11 flex-1 md:h-9 md:flex-none" onClick={() => form.setData('rows', [...form.data.rows, blank()])} disabled={form.data.rows.length >= 30}>
                        <Plus className="size-4" />
                        {b.addRow}
                    </Button>
                    <Button type="submit" size="lg" className="h-11 flex-1 md:min-w-48 md:flex-none" disabled={form.processing}>
                        {form.processing ? <Spinner /> : <Sparkles className="size-4" />}
                        {b.submit}
                    </Button>
                </div>
            </form>
        </PageBody>
    );
}
