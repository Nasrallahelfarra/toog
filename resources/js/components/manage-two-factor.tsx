import { Form } from '@inertiajs/react';
import { CircleAlert, MonitorSmartphone, ShieldCheck, Smartphone } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
    /** Stores whose owner requires two-factor sign-in of this staff member. */
    twoFactorRequiredBy?: string[];
};

export default function ManageTwoFactor(props: Props) {
    const { t, tr } = useI18n();
    const m = t.account.twoFactor;
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;
    const requiredBy = props.twoFactorRequiredBy ?? [];

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <div id="two-factor" className="scroll-mt-6 space-y-6">
            <div className="flex items-start justify-between gap-3">
                <Heading variant="small" title={t.account.twoFactorTitle} description={t.account.twoFactorDescription} />
                {twoFactorEnabled && (
                    <span className="bg-success-soft text-success inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium">
                        <ShieldCheck className="size-3.5" />
                        {m.enabled}
                    </span>
                )}
            </div>

            {requiredBy.length > 0 && !twoFactorEnabled && (
                <div className="border-warning/40 bg-warning-soft flex gap-3 rounded-xl border p-4">
                    <CircleAlert className="text-warning mt-0.5 size-5 shrink-0" />
                    <div className="space-y-1">
                        <p className="text-sm font-semibold">{m.requiredTitle}</p>
                        <p className="text-sm">{tr(m.requiredBody, { stores: requiredBy.join('، ') })}</p>
                    </div>
                </div>
            )}

            {twoFactorEnabled ? (
                <div className="flex flex-col items-start gap-4">
                    <p className="text-muted-foreground text-sm">{m.enabledText}</p>

                    <div className="bg-muted/40 flex w-full gap-3 rounded-xl border p-4">
                        <MonitorSmartphone className="text-muted-foreground mt-0.5 size-5 shrink-0" />
                        <div className="space-y-2">
                            <p className="text-sm font-medium">{m.devicesTitle}</p>
                            <p className="text-muted-foreground text-xs leading-relaxed">{m.devicesHint}</p>
                            <Button variant="outline" size="sm" onClick={() => setShowSetupModal(true)}>
                                <Smartphone className="size-4" />
                                {m.addDevice}
                            </Button>
                        </div>
                    </div>

                    <TwoFactorRecoveryCodes recoveryCodesList={recoveryCodesList} fetchRecoveryCodes={fetchRecoveryCodes} errors={errors} />

                    {requiredBy.length > 0 ? (
                        <p className="text-muted-foreground text-xs">{m.requiredKeep}</p>
                    ) : (
                        <Form {...disable.form()} options={{ preserveScroll: true }}>
                            {({ processing }) => (
                                <Button variant="ghost" type="submit" disabled={processing} className="text-danger hover:text-danger px-0 hover:bg-transparent">
                                    {m.disable}
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            ) : (
                <div className="flex flex-col items-start gap-4">
                    <p className="text-muted-foreground text-sm leading-relaxed">{m.intro}</p>

                    <ol className="grid w-full gap-2 sm:grid-cols-3">
                        {m.steps.map((step, index) => (
                            <li key={step} className="bg-muted/40 flex items-start gap-2.5 rounded-xl border p-3 text-sm">
                                <span className="bg-primary text-primary-foreground num inline-flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                                    {index + 1}
                                </span>
                                <span className="leading-snug">{step}</span>
                            </li>
                        ))}
                    </ol>
                    <p className="text-muted-foreground text-xs">{m.apps}</p>

                    {hasSetupData ? (
                        <Button onClick={() => setShowSetupModal(true)}>
                            <ShieldCheck />
                            {m.continueSetup}
                        </Button>
                    ) : (
                        <Form {...enable.form()} options={{ preserveScroll: true }} onSuccess={() => setShowSetupModal(true)}>
                            {({ processing }) => (
                                <Button type="submit" disabled={processing}>
                                    <ShieldCheck />
                                    {m.enable}
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />
        </div>
    );
}
