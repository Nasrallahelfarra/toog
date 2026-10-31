// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { useI18n } from '@/hooks/use-i18n';

export default function VerifyEmail({ status }: { status?: string }) {
    const { t } = useI18n();

    return (
        <>
            <Head title={t.auth.verifyTitle} />

            {status === 'verification-link-sent' && (
                <div className="bg-success-soft text-success mb-4 rounded-xl px-4 py-3 text-center text-sm font-medium">
                    {t.account.linkSent}
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            {t.auth.resend}
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            {t.common.logout}
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'verifyTitle',
    description: 'verifySubtitle',
};
