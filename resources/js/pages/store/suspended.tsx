import { Head, Link, router } from '@inertiajs/react';
import { LogOut, PauseCircle } from 'lucide-react';
import { motion } from 'motion/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';

export default function Suspended({ reason }: { reason?: string | null }) {
    const { locale } = useI18n();
    const ar = locale === 'ar';

    return (
        <div dir={ar ? 'rtl' : 'ltr'} className="bg-background flex min-h-svh items-center justify-center p-6">
            <Head title={ar ? 'المتجر موقوف' : 'Store suspended'} />
            <motion.div
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                className="bg-card w-full max-w-md space-y-6 rounded-3xl border p-8 text-center shadow-sm"
            >
                <div className="bg-warning-soft text-warning mx-auto flex size-16 items-center justify-center rounded-2xl">
                    <PauseCircle className="size-8" />
                </div>
                <div className="space-y-2">
                    <h1 className="text-xl font-semibold">{ar ? 'تم إيقاف هذا المتجر مؤقتًا' : 'This store is suspended'}</h1>
                    <p className="text-muted-foreground text-sm leading-relaxed">
                        {ar
                            ? 'لا يمكن الوصول إلى لوحة المتجر حاليًا. تواصل مع إدارة منصة طوق لإعادة التفعيل.'
                            : 'The store dashboard is unavailable. Contact the طوق platform team to reactivate it.'}
                    </p>
                    {reason && <p className="bg-muted rounded-xl px-4 py-2.5 text-sm">{reason}</p>}
                </div>
                <div className="flex items-center justify-center gap-2">
                    <Button variant="outline" onClick={() => router.post('/logout')}>
                        <LogOut className="size-4" />
                        {ar ? 'تسجيل الخروج' : 'Log out'}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href="/">
                            <AppLogoIcon className="size-5" />
                            طوق
                        </Link>
                    </Button>
                </div>
            </motion.div>
        </div>
    );
}
