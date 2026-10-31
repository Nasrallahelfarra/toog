import AppLogoIcon from '@/components/app-logo-icon';
import { useI18n } from '@/hooks/use-i18n';

export default function AppLogo() {
    const { t } = useI18n();

    return (
        <>
            <div className="from-sidebar-accent to-sidebar flex aspect-square size-9 items-center justify-center rounded-xl bg-gradient-to-br ring-1 ring-white/10">
                <AppLogoIcon className="size-6 text-[#EADBB4]" />
            </div>
            <div className="ms-1 grid flex-1 text-start text-sm leading-tight">
                <span className="truncate text-[15px] font-semibold tracking-tight">طوق</span>
                <span className="text-sidebar-foreground/55 truncate text-[11px]">{t.common.tagline}</span>
            </div>
        </>
    );
}
