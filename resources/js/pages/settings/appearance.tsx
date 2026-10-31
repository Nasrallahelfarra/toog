import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { useI18n } from '@/hooks/use-i18n';

export default function Appearance() {
    const { t } = useI18n();

    return (
        <>
            <Head title={t.account.appearance} />

            <h1 className="sr-only">{t.account.appearance}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t.account.appearanceTitle}
                    description={t.account.appearanceDescription}
                />
                <AppearanceTabs />
            </div>
        </>
    );
}


