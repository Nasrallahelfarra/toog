import { useForm } from '@inertiajs/react';
import { Ban, Palette, Plus, Store, Type, X } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { FadeIn } from '@/components/mojo/motion';
import { SectionCard } from '@/components/mojo/section-card';
import { SettingsShell } from '@/components/store/settings-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';

type Guide = {
    colors: string[];
    fonts: string[];
    style_ar: string;
    style_en: string;
    avoid_ar: string;
    avoid_en: string;
};

type Props = {
    guide: Guide;
    store: { name: string; logo_url: string | null };
};

export default function BrandSettings({ guide, store }: Props) {
    const { t, locale } = useI18n();
    const s = t.settings.brand;
    const form = useForm<Guide>(guide);
    const [newColor, setNewColor] = useState('#C9A24A');
    const [font, setFont] = useState('');

    const addColor = () => {
        const hex = newColor.toUpperCase();

        if (/^#[0-9A-F]{6}$/.test(hex) && !form.data.colors.includes(hex) && form.data.colors.length < 10) {
            form.setData('colors', [...form.data.colors, hex]);
        }
    };

    const addFont = () => {
        const name = font.trim();

        if (name && !form.data.fonts.includes(name) && form.data.fonts.length < 5) {
            form.setData('fonts', [...form.data.fonts, name]);
        }

        setFont('');
    };

    const errorFor = (prefix: string) =>
        Object.entries(form.errors).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1];

    const palette = form.data.colors.length ? form.data.colors : ['#1B3A5C', '#C9A24A', '#EFE8D8'];
    const previewFont = form.data.fonts[0];

    return (
        <SettingsShell>
            <div className="grid gap-6 xl:grid-cols-5">
                <div className="space-y-6 xl:col-span-3">
                    <FadeIn>
                        <SectionCard title={s.colorsTitle} description={s.colorsHint} icon={Palette}>
                            <div className="flex flex-wrap items-center gap-3">
                                <AnimatePresence>
                                    {form.data.colors.map((color) => (
                                        <motion.div
                                            key={color}
                                            layout
                                            initial={{ opacity: 0, scale: 0.6 }}
                                            animate={{ opacity: 1, scale: 1 }}
                                            exit={{ opacity: 0, scale: 0.6 }}
                                            className="group relative"
                                        >
                                            <div className="size-16 rounded-2xl shadow-sm ring-1 ring-black/10" style={{ backgroundColor: color }} />
                                            <span className="text-muted-foreground mt-1 block text-center font-mono text-[10px]" dir="ltr">
                                                {color}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => form.setData('colors', form.data.colors.filter((c) => c !== color))}
                                                className="bg-card absolute -end-1.5 -top-1.5 flex size-5 cursor-pointer items-center justify-center rounded-full opacity-0 shadow ring-1 ring-border transition-opacity group-hover:opacity-100"
                                                aria-label={t.common.delete}
                                            >
                                                <X className="size-3" />
                                            </button>
                                        </motion.div>
                                    ))}
                                </AnimatePresence>
                                {form.data.colors.length === 0 && <p className="text-muted-foreground text-sm">{s.noColors}</p>}
                            </div>
                            <div className="mt-5 flex flex-wrap items-center gap-2">
                                <label className="relative size-10 cursor-pointer overflow-hidden rounded-xl ring-1 ring-border">
                                    <input type="color" value={newColor} onChange={(e) => setNewColor(e.target.value)} className="absolute inset-0 size-full cursor-pointer opacity-0" />
                                    <span className="block size-full" style={{ backgroundColor: newColor }} />
                                </label>
                                <Input value={newColor} onChange={(e) => setNewColor(e.target.value)} className="w-32 font-mono" dir="ltr" />
                                <Button type="button" variant="outline" onClick={addColor}>
                                    <Plus className="size-4" />
                                    {s.addColor}
                                </Button>
                            </div>
                            <InputError message={errorFor('colors')} className="mt-2" />
                        </SectionCard>
                    </FadeIn>

                    <FadeIn delay={0.05}>
                        <SectionCard title={s.fontsTitle} description={s.fontsHint} icon={Type}>
                            <div className="flex flex-wrap gap-2">
                                {form.data.fonts.map((f) => (
                                    <span key={f} className="bg-muted inline-flex items-center gap-1.5 rounded-full py-1 ps-3 pe-1.5 text-sm" style={{ fontFamily: f }}>
                                        {f}
                                        <button
                                            type="button"
                                            onClick={() => form.setData('fonts', form.data.fonts.filter((x) => x !== f))}
                                            className="hover:bg-background flex size-5 cursor-pointer items-center justify-center rounded-full"
                                            aria-label={t.common.delete}
                                        >
                                            <X className="size-3" />
                                        </button>
                                    </span>
                                ))}
                            </div>
                            <Input
                                value={font}
                                onChange={(e) => setFont(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        addFont();
                                    }
                                }}
                                onBlur={addFont}
                                placeholder={s.fontPlaceholder}
                                className="mt-3"
                            />
                            <InputError message={errorFor('fonts')} className="mt-2" />
                        </SectionCard>
                    </FadeIn>

                    <FadeIn delay={0.1}>
                        <SectionCard title={s.styleTitle} description={s.styleHint} icon={Palette}>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="style_ar">{s.styleAr}</Label>
                                    <Textarea id="style_ar" rows={4} value={form.data.style_ar} onChange={(e) => form.setData('style_ar', e.target.value)} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="style_en">{s.styleEn}</Label>
                                    <Textarea id="style_en" rows={4} dir="ltr" value={form.data.style_en} onChange={(e) => form.setData('style_en', e.target.value)} />
                                </div>
                            </div>
                        </SectionCard>
                    </FadeIn>

                    <FadeIn delay={0.14}>
                        <SectionCard title={s.avoidTitle} description={s.avoidHint} icon={Ban}>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="avoid_ar">{s.styleAr}</Label>
                                    <Textarea id="avoid_ar" rows={3} value={form.data.avoid_ar} onChange={(e) => form.setData('avoid_ar', e.target.value)} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="avoid_en">{s.styleEn}</Label>
                                    <Textarea id="avoid_en" rows={3} dir="ltr" value={form.data.avoid_en} onChange={(e) => form.setData('avoid_en', e.target.value)} />
                                </div>
                            </div>
                        </SectionCard>
                    </FadeIn>
                </div>

                <FadeIn delay={0.08} className="xl:col-span-2">
                    <div className="sticky top-24 space-y-4">
                        <SectionCard title={s.previewTitle} icon={Store}>
                            <div className="overflow-hidden rounded-2xl ring-1 ring-border">
                                <div className="relative flex h-40 items-end p-5" style={{ background: `linear-gradient(135deg, ${palette[0]} 0%, ${palette[1] ?? palette[0]} 100%)` }}>
                                    <div className="bg-sadu pointer-events-none absolute inset-0 text-white/10" />
                                    <div className="relative flex items-center gap-3">
                                        <div className="flex size-12 items-center justify-center overflow-hidden rounded-xl bg-white/90 shadow">
                                            {store.logo_url ? <img src={store.logo_url} alt="" className="size-full object-contain p-1" /> : <Store className="size-5 text-black/60" />}
                                        </div>
                                        <span className="text-lg font-semibold text-white drop-shadow" style={{ fontFamily: previewFont }}>
                                            {store.name}
                                        </span>
                                    </div>
                                </div>
                                <div className="space-y-3 p-5" style={{ backgroundColor: palette[2] ?? 'var(--card)' }}>
                                    <h4 className="text-xl font-semibold" style={{ fontFamily: previewFont, color: palette[0] }}>
                                        {s.sampleHeadline}
                                    </h4>
                                    <p className="text-sm text-black/60" style={{ fontFamily: previewFont }}>
                                        {s.sampleBody}
                                    </p>
                                    <p className="text-xs leading-relaxed text-black/55">{locale === 'ar' ? form.data.style_ar : form.data.style_en}</p>
                                    <div className="flex gap-1.5 pt-1">
                                        {palette.map((c) => (
                                            <span key={c} className="h-2 flex-1 rounded-full" style={{ backgroundColor: c }} />
                                        ))}
                                    </div>
                                </div>
                            </div>
                            <p className="text-muted-foreground mt-3 text-xs">{s.logoManaged}</p>
                        </SectionCard>
                        <Button
                            size="lg"
                            className="w-full"
                            onClick={() => form.put('/store/settings/brand', { preserveScroll: true })}
                            disabled={form.processing || !form.isDirty}
                        >
                            {form.processing && <Spinner />}
                            {t.common.saveChanges}
                        </Button>
                    </div>
                </FadeIn>
            </div>
        </SettingsShell>
    );
}
