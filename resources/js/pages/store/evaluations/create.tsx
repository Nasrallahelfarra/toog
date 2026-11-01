import { Link, useForm } from '@inertiajs/react';
import { ArrowRight, Sparkles, Wand2 } from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';
import {
    AttributeSelect,
    ColorPicker,
} from '@/components/evaluation/attribute-select';
import { ImageDrop } from '@/components/evaluation/image-drop';
import { useModificationText } from '@/components/evaluation/modification-text';
import type {
    Modification,
    Recommendation,
} from '@/components/evaluation/types';
import InputError from '@/components/input-error';
import { DecisionBadge } from '@/components/mojo/decision-badge';
import { FadeIn } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';

type Prefill = {
    name?: string;
    category?: string | null;
    price?: string | number | null;
    material?: string | null;
    colors?: string[];
    pattern?: string | null;
    style?: string | null;
    season?: string | null;
    description?: string | null;
    target_audience?: string | null;
};

type Props = {
    parent: {
        id: number;
        name: string;
        version: number;
        score: number | null;
        recommendation: Recommendation | null;
        image_url: string | null;
        modifications: Modification[];
        expected_score: number | null;
    } | null;
    prefill: Prefill;
    categories: string[];
};

export default function EvaluationCreate({
    parent,
    prefill,
    categories,
}: Props) {
    const { t, tr } = useI18n();
    const e = t.evaluation;
    const modText = useModificationText();
    const [applied, setApplied] = useState(false);

    const form = useForm({
        parent_id: parent?.id ?? null,
        name: prefill.name ?? '',
        category: prefill.category ?? '',
        price: prefill.price ? String(prefill.price) : '',
        material: prefill.material ?? null,
        colors: prefill.colors ?? [],
        pattern: prefill.pattern ?? null,
        style: prefill.style ?? null,
        season: prefill.season ?? null,
        description: prefill.description ?? '',
        target_audience: prefill.target_audience ?? '',
        image: null as File | null,
    });

    const applySuggestions = () => {
        const changes = (parent?.modifications ?? []).reduce<
            Record<string, unknown>
        >((acc, m) => ({ ...acc, ...m.change }), {});

        form.setData((data) => ({
            ...data,
            price:
                changes.price !== undefined
                    ? String(Math.round(Number(changes.price)))
                    : data.price,
            colors: (changes.colors as string[] | undefined) ?? data.colors,
            pattern: (changes.pattern as string | undefined) ?? data.pattern,
            material: (changes.material as string | undefined) ?? data.material,
            style: (changes.style as string | undefined) ?? data.style,
            season: (changes.season as string | undefined) ?? data.season,
        }));
        setApplied(true);
    };

    const submit = (ev: React.FormEvent) => {
        ev.preventDefault();
        form.post('/store/evaluations', { forceFormData: true });
    };

    const title = parent ? e.form.versionTitle : e.form.title;

    return (
        <PageBody className="max-w-6xl">
            <PageHeader
                title={title}
                description={
                    parent
                        ? tr(e.form.versionOf, {
                              name: parent.name,
                              n: parent.version + 1,
                          })
                        : e.form.subtitle
                }
                icon={Sparkles}
                actions={
                    <Button variant="ghost" asChild>
                        <Link
                            href={
                                parent
                                    ? `/store/evaluations/${parent.id}`
                                    : '/store/evaluations'
                            }
                        >
                            <ArrowRight className="size-4 ltr:rotate-180" />
                            {t.common.back}
                        </Link>
                    </Button>
                }
            />

            <form
                onSubmit={submit}
                className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]"
            >
                <div className="space-y-6">
                    <SectionCard
                        title={e.form.image}
                        description={e.form.imageHint}
                    >
                        <ImageDrop
                            file={form.data.image}
                            onChange={(file) => form.setData('image', file)}
                            existingUrl={parent?.image_url}
                        />
                        <InputError
                            message={form.errors.image}
                            className="mt-2"
                        />
                    </SectionCard>

                    {parent && parent.modifications.length > 0 && (
                        <FadeIn>
                            <SectionCard
                                title={e.form.parentSuggestions}
                                icon={Wand2}
                                action={
                                    parent.recommendation && (
                                        <DecisionBadge
                                            value={parent.recommendation}
                                        />
                                    )
                                }
                            >
                                <div className="mb-3 flex items-center justify-between text-xs text-muted-foreground">
                                    <span>
                                        {e.form.previousScore}:{' '}
                                        <strong className="num text-foreground">
                                            {parent.score}
                                        </strong>
                                    </span>
                                    {parent.expected_score && (
                                        <span>
                                            →{' '}
                                            <strong className="num text-success">
                                                {parent.expected_score}
                                            </strong>
                                        </span>
                                    )}
                                </div>
                                <ul className="space-y-2">
                                    {parent.modifications.map((m, i) => (
                                        <li
                                            key={i}
                                            className="flex items-start justify-between gap-3 rounded-xl bg-muted/50 px-3 py-2 text-sm"
                                        >
                                            <span>{modText(m).title}</span>
                                            <span className="num shrink-0 text-xs font-semibold text-success">
                                                +{m.expected_gain}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <Button
                                    type="button"
                                    variant={applied ? 'soft' : 'gold'}
                                    className="mt-4 w-full"
                                    onClick={applySuggestions}
                                >
                                    <Wand2 className="size-4" />
                                    {applied
                                        ? e.form.applied
                                        : e.form.applySuggestions}
                                </Button>
                            </SectionCard>
                        </FadeIn>
                    )}
                </div>

                <div className="space-y-6">
                    <SectionCard title={e.form.basics}>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="target_audience">
                                    {t.productReview.audience}
                                </Label>
                                <Textarea
                                    id="target_audience"
                                    rows={2}
                                    maxLength={1000}
                                    value={form.data.target_audience}
                                    onChange={(ev) =>
                                        form.setData(
                                            'target_audience',
                                            ev.target.value,
                                        )
                                    }
                                    placeholder={t.productReview.audienceHint}
                                />
                                <InputError
                                    message={form.errors.target_audience}
                                />
                            </div>
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="name">{e.form.name}</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(ev) =>
                                        form.setData('name', ev.target.value)
                                    }
                                    placeholder={e.form.namePlaceholder}
                                    required
                                    className="h-10"
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="category">
                                    {e.form.category}
                                </Label>
                                <Input
                                    id="category"
                                    list="store-categories"
                                    value={form.data.category ?? ''}
                                    onChange={(ev) =>
                                        form.setData(
                                            'category',
                                            ev.target.value,
                                        )
                                    }
                                    placeholder={e.form.categoryPlaceholder}
                                    className="h-10"
                                />
                                <datalist id="store-categories">
                                    {categories.map((c) => (
                                        <option key={c} value={c} />
                                    ))}
                                </datalist>
                                <InputError message={form.errors.category} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="price">{e.form.price}</Label>
                                <Input
                                    id="price"
                                    type="number"
                                    min={0}
                                    step="1"
                                    inputMode="decimal"
                                    value={form.data.price}
                                    onChange={(ev) =>
                                        form.setData('price', ev.target.value)
                                    }
                                    className="h-10"
                                    dir="ltr"
                                />
                                <InputError message={form.errors.price} />
                            </div>
                        </div>
                    </SectionCard>

                    <SectionCard title={e.form.attributes}>
                        <div className="space-y-5">
                            <div className="grid gap-2">
                                <div className="flex items-baseline justify-between">
                                    <Label>{e.form.colors}</Label>
                                    <span className="text-xs text-muted-foreground">
                                        {e.form.colorsHint}
                                    </span>
                                </div>
                                <ColorPicker
                                    value={form.data.colors}
                                    onChange={(colors) =>
                                        form.setData('colors', colors)
                                    }
                                />
                                <InputError message={form.errors.colors} />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label={e.form.material}
                                    error={form.errors.material}
                                >
                                    <AttributeSelect
                                        group="materials"
                                        value={form.data.material}
                                        onChange={(v) =>
                                            form.setData('material', v)
                                        }
                                    />
                                </Field>
                                <Field
                                    label={e.form.pattern}
                                    error={form.errors.pattern}
                                >
                                    <AttributeSelect
                                        group="patterns"
                                        value={form.data.pattern}
                                        onChange={(v) =>
                                            form.setData('pattern', v)
                                        }
                                    />
                                </Field>
                                <Field
                                    label={e.form.style}
                                    error={form.errors.style}
                                >
                                    <AttributeSelect
                                        group="styles"
                                        value={form.data.style}
                                        onChange={(v) =>
                                            form.setData('style', v)
                                        }
                                    />
                                </Field>
                                <Field
                                    label={e.form.season}
                                    error={form.errors.season}
                                >
                                    <AttributeSelect
                                        group="seasons"
                                        value={form.data.season}
                                        onChange={(v) =>
                                            form.setData('season', v)
                                        }
                                    />
                                </Field>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">
                                    {e.form.description}
                                </Label>
                                <Textarea
                                    id="description"
                                    rows={3}
                                    value={form.data.description ?? ''}
                                    onChange={(ev) =>
                                        form.setData(
                                            'description',
                                            ev.target.value,
                                        )
                                    }
                                    placeholder={e.form.descriptionPlaceholder}
                                />
                                <InputError message={form.errors.description} />
                            </div>
                        </div>
                    </SectionCard>

                    <motion.div layout className="flex justify-end">
                        <Button
                            type="submit"
                            size="lg"
                            className="h-11 min-w-48"
                            disabled={form.processing}
                        >
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <Sparkles className="size-4" />
                            )}
                            {form.processing
                                ? e.form.submitting
                                : e.form.submit}
                        </Button>
                    </motion.div>
                </div>
            </form>
        </PageBody>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
