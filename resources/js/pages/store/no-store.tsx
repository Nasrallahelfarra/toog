import { Head, router } from '@inertiajs/react';
import { LogOut, Store } from 'lucide-react';
import { motion } from 'motion/react';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';

export default function NoStore() {
    const { locale } = useI18n();
    const ar = locale === 'ar';

    return (
        <div dir={ar ? 'rtl' : 'ltr'} className="bg-background flex min-h-svh items-center justify-center p-6">
            <Head title={ar ? 'لا يوجد متجر' : 'No store'} />
            <motion.div
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                className="bg-card w-full max-w-md space-y-6 rounded-3xl border p-8 text-center shadow-sm"
            >
                <div className="bg-primary/8 text-primary mx-auto flex size-16 items-center justify-center rounded-2xl">
                    <Store className="size-8" />
                </div>
                <div className="space-y-2">
                    <h1 className="text-xl font-semibold">{ar ? 'لا يوجد متجر مرتبط بحسابك' : 'No store is linked to your account'}</h1>
                    <p className="text-muted-foreground text-sm leading-relaxed">
                        {ar
                            ? 'تواصل مع إدارة المنصة لربط متجرك بحسابك.'
                            : 'Ask the platform administrator to link your store to your account.'}
                    </p>
                </div>
                <Button variant="outline" onClick={() => router.post('/logout')}>
                    <LogOut className="size-4" />
                    {ar ? 'تسجيل الخروج' : 'Log out'}
                </Button>
            </motion.div>
        </div>
    );
}
