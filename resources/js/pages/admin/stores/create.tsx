import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Store, UserPlus, UserRound } from 'lucide-react';
import { motion } from 'motion/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/admin/field';
import { FadeIn } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Props = {
    owners: { id: number; name: string; email: string }[];
};

export default function CreateStore({ owners }: Props) {
    const { t } = useI18n();
    const c = t.admin.create;

    const form = useForm({
        name: '',
        domain: '',
        category: '',
        platform: 'zid',
        ai_monthly_budget: '',
        owner_mode: 'new' as 'new' | 'existing',
        owner_name: '',
        owner_email: '',
        owner_phone: '',
        owner_password: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/admin/stores');
    };

    const mode = (value: 'new' | 'existing') => {
        form.setData((data) => ({ ...data, owner_mode: value, owner_email: '' }));
        form.clearErrors();
    };

    return (
        <PageBody className="max-w-4xl">
            <PageHeader
                title={c.title}
                description={c.subtitle}
                icon={Store}
                actions={
                    <Button variant="ghost" asChild>
                        <Link href="/admin/stores">
                            <ArrowLeft className="size-4 rtl:rotate-180" />
                            {t.common.back}
                        </Link>
                    </Button>
                }
            />

            <form onSubmit={submit} className="space-y-6">
                <FadeIn delay={0.05}>
                    <SectionCard title={c.storeSection} icon={Store}>
                        <div className="grid gap-5 sm:grid-cols-2">
                            <Field label={c.storeName} error={form.errors.name} className="sm:col-span-2">
                                <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required autoFocus />
                            </Field>
                            <Field label={c.domain} error={form.errors.domain} hint={c.domainHint}>
                                <Input value={form.data.domain} onChange={(e) => form.setData('domain', e.target.value)} dir="ltr" placeholder="mystore.zid.store" />
                            </Field>
                            <Field label={c.category} error={form.errors.category}>
                                <Input value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} placeholder={c.categoryPlaceholder} />
                            </Field>
                            {/* Only Zid is integrated for now, so the platform is fixed. */}
                            <Field label={c.platform} error={form.errors.platform}>
                                <div className="bg-muted/60 flex h-9 items-center rounded-lg border px-3 text-sm font-medium">{t.admin.platforms.zid}</div>
                            </Field>
                            <Field label={c.budget} error={form.errors.ai_monthly_budget} hint={c.budgetHint}>
                                <Input
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    dir="ltr"
                                    value={form.data.ai_monthly_budget}
                                    onChange={(e) => form.setData('ai_monthly_budget', e.target.value)}
                                    placeholder="50"
                                />
                            </Field>
                        </div>
                    </SectionCard>
                </FadeIn>

                <FadeIn delay={0.1}>
                    <SectionCard title={c.ownerSection} icon={UserRound}>
                        <div className="mb-5 grid grid-cols-2 gap-2 sm:max-w-md">
                            {(['new', 'existing'] as const).map((value) => {
                                const active = form.data.owner_mode === value;
                                const Icon = value === 'new' ? UserPlus : UserRound;

                                return (
                                    <button
                                        key={value}
                                        type="button"
                                        onClick={() => mode(value)}
                                        className={cn(
                                            'relative flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2.5 text-sm font-medium transition-colors',
                                            active ? 'border-primary text-primary' : 'text-muted-foreground hover:bg-muted/60',
                                        )}
                                    >
                                        {active && (
                                            <motion.span layoutId="owner-mode" className="bg-primary/6 absolute inset-0 rounded-xl" transition={{ type: 'spring', stiffness: 400, damping: 32 }} />
                                        )}
                                        <Icon className="relative size-4" />
                                        <span className="relative">{value === 'new' ? c.ownerNew : c.ownerExisting}</span>
                                    </button>
                                );
                            })}
                        </div>

                        {form.data.owner_mode === 'new' ? (
                            <div className="grid gap-5 sm:grid-cols-2">
                                <Field label={c.ownerName} error={form.errors.owner_name}>
                                    <Input value={form.data.owner_name} onChange={(e) => form.setData('owner_name', e.target.value)} required />
                                </Field>
                                <Field label={c.ownerEmail} error={form.errors.owner_email}>
                                    <Input
                                        type="email"
                                        dir="ltr"
                                        value={form.data.owner_email}
                                        onChange={(e) => form.setData('owner_email', e.target.value)}
                                        required
                                        placeholder="owner@store.sa"
                                    />
                                </Field>
                                <Field label={c.ownerPhone} error={form.errors.owner_phone}>
                                    <Input dir="ltr" value={form.data.owner_phone} onChange={(e) => form.setData('owner_phone', e.target.value)} placeholder="05xxxxxxxx" />
                                </Field>
                                <Field label={c.ownerPassword} error={form.errors.owner_password} hint={c.ownerPasswordHint}>
                                    <PasswordInput
                                        value={form.data.owner_password}
                                        onChange={(e) => form.setData('owner_password', e.target.value)}
                                        required
                                        autoComplete="new-password"
                                    />
                                </Field>
                            </div>
                        ) : (
                            <Field label={c.selectOwner} error={form.errors.owner_email} className="sm:max-w-md">
                                <Select value={form.data.owner_email} onValueChange={(v) => form.setData('owner_email', v)}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder={c.selectOwner} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {owners.map((o) => (
                                            <SelectItem key={o.id} value={o.email}>
                                                {o.name} — <span dir="ltr">{o.email}</span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                    </SectionCard>
                </FadeIn>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href="/admin/stores">{t.common.cancel}</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {c.submit}
                    </Button>
                </div>
            </form>
        </PageBody>
    );
}
