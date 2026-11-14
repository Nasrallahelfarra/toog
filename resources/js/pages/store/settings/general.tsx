import { useForm } from '@inertiajs/react';
import { ImageUp, Store, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { FadeIn } from '@/components/mojo/motion';
import { SectionCard } from '@/components/mojo/section-card';
import { SettingsRow, SettingsShell } from '@/components/store/settings-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/hooks/use-i18n';

type Props = {
    store: {
        name: string;
        /** Connected to Zid: the name is Zid's and is not edited here. */
        name_from_zid: boolean;
        domain: string | null;
        category: string | null;
        platform: string;
        slug: string;
        logo_url: string | null;
        created_at: string | null;
    };
    categoryNames: string[];
};

export default function GeneralSettings({ store }: Props) {
    const { t } = useI18n();
    const s = t.settings.general;
    const fileRef = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(store.logo_url);

    const form = useForm<{ name: string; domain: string; category: string; logo: File | null; remove_logo: boolean }>({
        name: store.name,
        domain: store.domain ?? '',
        category: store.category ?? '',
        logo: null,
        remove_logo: false,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/store/settings/general', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => form.setData((d) => ({ ...d, logo: null, remove_logo: false })),
        });
    };

    const pickLogo = (file: File | null) => {
        form.setData((d) => ({ ...d, logo: file, remove_logo: false }));
        setPreview(file ? URL.createObjectURL(file) : store.logo_url);
    };

    return (
        <SettingsShell>
            <FadeIn>
                <form onSubmit={submit}>
                    <SectionCard title={s.title} description={s.hint} icon={Store}>
                        <SettingsRow title={s.logo} description={s.logoHint}>
                            <div className="flex items-center gap-4">
                                <div className="bg-muted flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl ring-1 ring-border">
                                    {preview ? (
                                        <img src={preview} alt="" className="size-full object-contain p-1.5" />
                                    ) : (
                                        <Store className="text-muted-foreground size-7" />
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <input
                                        ref={fileRef}
                                        type="file"
                                        accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                        className="hidden"
                                        onChange={(e) => pickLogo(e.target.files?.[0] ?? null)}
                                    />
                                    <Button type="button" variant="outline" onClick={() => fileRef.current?.click()}>
                                        <ImageUp className="size-4" />
                                        {preview ? s.changeLogo : s.uploadLogo}
                                    </Button>
                                    {preview && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="text-danger hover:text-danger"
                                            onClick={() => {
                                                form.setData((d) => ({ ...d, logo: null, remove_logo: true }));
                                                setPreview(null);
                                            }}
                                        >
                                            <Trash2 className="size-4" />
                                            {s.removeLogo}
                                        </Button>
                                    )}
                                </div>
                            </div>
                            <InputError message={form.errors.logo} className="mt-2" />
                        </SettingsRow>

                        <SettingsRow title={s.storeName}>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">{s.storeName}</Label>
                                    <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required disabled={store.name_from_zid} />
                                    {store.name_from_zid && <p className="text-muted-foreground text-xs">{s.nameFromZid}</p>}
                                    <InputError message={form.errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="category">{s.category}</Label>
                                    <Input
                                        id="category"
                                        value={form.data.category}
                                        placeholder={s.categoryPlaceholder}
                                        onChange={(e) => form.setData('category', e.target.value)}
                                    />
                                    <InputError message={form.errors.category} />
                                </div>
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="domain">{s.domain}</Label>
                                    <Input
                                        id="domain"
                                        dir="ltr"
                                        value={form.data.domain}
                                        placeholder="mystore.zid.store"
                                        onChange={(e) => form.setData('domain', e.target.value)}
                                    />
                                    <InputError message={form.errors.domain} />
                                </div>
                            </div>
                        </SettingsRow>

                        <SettingsRow title={s.platform}>
                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                <div className="bg-muted/50 rounded-xl p-3">
                                    <dt className="text-muted-foreground text-xs">{s.platform}</dt>
                                    <dd className="mt-0.5 font-medium">{t.dashboard.platformNames[store.platform as 'zid'] ?? store.platform}</dd>
                                </div>
                                <div className="bg-muted/50 rounded-xl p-3">
                                    <dt className="text-muted-foreground text-xs">{s.slug}</dt>
                                    <dd className="mt-0.5 font-mono text-xs" dir="ltr">
                                        {store.slug}
                                    </dd>
                                </div>
                            </dl>
                        </SettingsRow>

                        <div className="flex justify-end pt-2">
                            <Button type="submit" disabled={form.processing || !form.isDirty}>
                                {form.processing && <Spinner />}
                                {t.common.saveChanges}
                            </Button>
                        </div>
                    </SectionCard>
                </form>
            </FadeIn>
        </SettingsShell>
    );
}
