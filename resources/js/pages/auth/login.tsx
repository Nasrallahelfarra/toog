import { Form, Head } from '@inertiajs/react';
import { ShieldCheck, Store } from 'lucide-react';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/hooks/use-i18n';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

const demoAccounts = [
    { key: 'demoAdmin', email: 'admin@mojo.ai', icon: ShieldCheck },
    { key: 'demoOwner', email: 'tooog4ai@gmail.com', icon: Store },
] as const;

export default function Login({ status, canResetPassword }: Props) {
    const { t } = useI18n();
    // Demo shortcut: fill the form with a seeded account and submit it.
    const fillDemo = (email: string) => {
        const input = document.getElementById('email') as HTMLInputElement | null;
        const form = input?.form;

        if (!input || !form) {
            return;
        }

        input.value = email;
        (form.elements.namedItem('password') as HTMLInputElement).value = 'password';
        form.requestSubmit();
    };

    return (
        <>
            <Head title={t.auth.login} />

            {status && (
                <div className="bg-warning-soft text-warning rounded-xl px-4 py-3 text-sm font-medium">{status}</div>
            )}

            <PasskeyVerify label={t.auth.passkey} separator={t.auth.or} />

            <Form {...store.form()} resetOnSuccess={['password']} className="flex flex-col gap-5">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">{t.auth.email}</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="name@store.sa"
                                dir="ltr"
                                className="h-11"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-center">
                                <Label htmlFor="password">{t.auth.password}</Label>
                                {canResetPassword && (
                                    <TextLink href={request()} className="ms-auto text-sm" tabIndex={5}>
                                        {t.auth.forgot}
                                    </TextLink>
                                )}
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="••••••••"
                                className="h-11"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center gap-3">
                            <Checkbox id="remember" name="remember" tabIndex={3} />
                            <Label htmlFor="remember">{t.auth.remember}</Label>
                        </div>

                        <Button type="submit" size="lg" className="h-11 w-full" tabIndex={4} disabled={processing} data-test="login-button">
                            {processing && <Spinner />}
                            {t.auth.login}
                        </Button>

                        <div className="text-muted-foreground text-center text-sm">
                            {t.auth.noAccount}{' '}
                            <TextLink href={register()} tabIndex={5}>
                                {t.auth.createAccount}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>

            <div className="space-y-2.5 rounded-2xl border border-dashed p-4">
                <p className="text-muted-foreground text-xs font-medium">{t.auth.demoAccounts}</p>
                <div className="grid grid-cols-2 gap-2">
                    {demoAccounts.map((account) => (
                        <button
                            key={account.email}
                            type="button"
                            onClick={() => fillDemo(account.email)}
                            className="hover:border-primary/40 hover:bg-primary/5 flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-start transition-colors"
                        >
                            <account.icon className="text-primary size-4 shrink-0" />
                            <span className="min-w-0">
                                <span className="block text-xs font-medium">{t.auth[account.key]}</span>
                                <span className="text-muted-foreground block truncate text-[11px]" dir="ltr">
                                    {account.email}
                                </span>
                            </span>
                        </button>
                    ))}
                </div>
            </div>
        </>
    );
}

Login.layout = {
    title: 'loginTitle',
    description: 'loginSubtitle',
};
