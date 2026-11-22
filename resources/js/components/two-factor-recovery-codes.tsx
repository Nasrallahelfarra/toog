import { Form } from '@inertiajs/react';
import { Check, Copy, Eye, EyeOff, LockKeyhole, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useClipboard } from '@/hooks/use-clipboard';
import { useI18n } from '@/hooks/use-i18n';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

type Props = {
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    errors: string[];
};

export default function TwoFactorRecoveryCodes({ recoveryCodesList, fetchRecoveryCodes, errors }: Props) {
    const { t } = useI18n();
    const m = t.account.twoFactor.recovery;
    const [codesAreVisible, setCodesAreVisible] = useState<boolean>(false);
    const codesSectionRef = useRef<HTMLDivElement | null>(null);
    const canRegenerateCodes = recoveryCodesList.length > 0 && codesAreVisible;
    const [copiedText, copy] = useClipboard();
    const allCodes = recoveryCodesList.join('\n');
    const copied = allCodes !== '' && copiedText === allCodes;

    const toggleCodesVisibility = useCallback(async () => {
        if (!codesAreVisible && !recoveryCodesList.length) {
            await fetchRecoveryCodes();
        }

        setCodesAreVisible(!codesAreVisible);

        if (!codesAreVisible) {
            setTimeout(() => {
                codesSectionRef.current?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                });
            });
        }
    }, [codesAreVisible, recoveryCodesList.length, fetchRecoveryCodes]);

    useEffect(() => {
        if (!recoveryCodesList.length) {
            void fetchRecoveryCodes();
        }
    }, [recoveryCodesList.length, fetchRecoveryCodes]);

    const RecoveryCodeIconComponent = codesAreVisible ? EyeOff : Eye;

    return (
        <Card className="w-full">
            <CardHeader>
                <CardTitle className="flex gap-3">
                    <LockKeyhole className="size-4" aria-hidden="true" />
                    {m.title}
                </CardTitle>
                <CardDescription>{m.description}</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="flex flex-wrap items-center gap-2 select-none">
                    <Button onClick={toggleCodesVisibility} className="w-fit" aria-expanded={codesAreVisible} aria-controls="recovery-codes-section">
                        <RecoveryCodeIconComponent className="size-4" aria-hidden="true" />
                        {codesAreVisible ? m.hide : m.show}
                    </Button>

                    {canRegenerateCodes && (
                        <>
                            <Button variant="outline" onClick={() => copy(allCodes)}>
                                {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                                {copied ? m.copied : m.copy}
                            </Button>
                            <Form {...regenerateRecoveryCodes.form()} options={{ preserveScroll: true }} onSuccess={fetchRecoveryCodes}>
                                {({ processing }) => (
                                    <Button variant="secondary" type="submit" disabled={processing} aria-describedby="regenerate-warning">
                                        <RefreshCw /> {m.regenerate}
                                    </Button>
                                )}
                            </Form>
                        </>
                    )}
                </div>
                <div
                    id="recovery-codes-section"
                    className={`relative overflow-hidden transition-all duration-300 ${codesAreVisible ? 'h-auto opacity-100' : 'h-0 opacity-0'}`}
                    aria-hidden={!codesAreVisible}
                >
                    <div className="mt-3 space-y-3">
                        {errors?.length ? (
                            <AlertError errors={errors} />
                        ) : (
                            <>
                                <div
                                    ref={codesSectionRef}
                                    dir="ltr"
                                    className="bg-muted grid gap-1 rounded-lg p-4 font-mono text-sm sm:grid-cols-2"
                                    role="list"
                                    aria-label={m.list}
                                >
                                    {recoveryCodesList.length ? (
                                        recoveryCodesList.map((code, index) => (
                                            <div key={index} role="listitem" className="select-text">
                                                {code}
                                            </div>
                                        ))
                                    ) : (
                                        <div className="space-y-2" aria-label={m.loading}>
                                            {Array.from({ length: 8 }, (_, index) => (
                                                <div key={index} className="bg-muted-foreground/20 h-4 animate-pulse rounded" aria-hidden="true" />
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <p id="regenerate-warning" className="text-muted-foreground text-xs select-none">
                                    {m.note}
                                </p>
                            </>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
