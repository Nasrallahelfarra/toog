import { AlertTriangle, Camera, ChevronDown, ImagePlus, Images, Loader2, RotateCw, UploadCloud, X } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useI18n } from '@/hooks/use-i18n';
import { useIsMobile } from '@/hooks/use-mobile';
import { compressImage, UnsupportedImageError } from '@/lib/compress-image';
import { cn } from '@/lib/utils';
import { ROLE_ORDER } from './types';
import type { LookLimits, LookRole, TrayItem } from './types';
import { deleteLookImage, uploadLookImage } from './upload';
import type { UploadError } from './upload';

let sequence = 0;
const nextKey = () => `img-${Date.now()}-${++sequence}`;

function countRoles(items: TrayItem[], except?: string) {
    const counts: Record<LookRole, number> = { sketch_front: 0, sketch_back: 0, fabric: 0, detail: 0 };
    items.forEach((item) => item.key !== except && counts[item.role]++);

    return counts;
}

/** First image → front sketch; then fabric until full, then details; any free sketch slot last. */
function suggestRole(items: TrayItem[], limits: LookLimits): LookRole | null {
    const counts = countRoles(items);
    const free = (role: LookRole) => counts[role] < limits.roles[role];

    if (items.length === 0 && free('sketch_front')) {
        return 'sketch_front';
    }

    return (['fabric', 'detail', 'sketch_front', 'sketch_back'] as LookRole[]).find(free) ?? null;
}

/**
 * Once the picture is read: a sketch-like picture takes a free sketch slot
 * (the front, then the back, else a detail), anything else a fabric slot.
 * Only the role suggested on adding is replaced, never one the user chose.
 */
function refineRole(items: TrayItem[], key: string, sketch: boolean, limits: LookLimits): LookRole | null {
    const counts = countRoles(items, key);
    const free = (role: LookRole) => counts[role] < limits.roles[role];
    const order: LookRole[] = sketch ? ['sketch_front', 'sketch_back', 'detail', 'fabric'] : ['fabric', 'detail', 'sketch_front', 'sketch_back'];

    return order.find(free) ?? null;
}

/** Thumbnails in role order, then in the order they were added. */
export function sortTray(items: TrayItem[]) {
    return items.map((item, i) => ({ item, i })).sort((a, b) => ROLE_ORDER.indexOf(a.item.role) - ROLE_ORDER.indexOf(b.item.role) || a.i - b.i).map(({ item }) => item);
}

export function isImageFile(file: File) {
    return file.type.startsWith('image/') || /\.(heic|heif|jpe?g|png|webp)$/i.test(file.name);
}

/**
 * The tray's state: each picked file is compressed, given a role and
 * uploaded straight away, so submitting only sends ids.
 */
export function useReferenceTray(limits: LookLimits) {
    const { t, tr } = useI18n();
    const l = t.studio.look.tray;
    const [items, setItems] = useState<TrayItem[]>([]);
    const itemsRef = useRef(items);
    itemsRef.current = items;
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        if (!notice) return;
        const timer = window.setTimeout(() => setNotice(null), 6000);

        return () => window.clearTimeout(timer);
    }, [notice]);

    // Object URLs are freed when the composer goes away.
    useEffect(() => () => itemsRef.current.forEach((item) => item.preview && URL.revokeObjectURL(item.preview)), []);

    const patch = useCallback((key: string, changes: Partial<TrayItem>) => {
        setItems((list) => {
            const next = list.map((item) => (item.key === key ? { ...item, ...changes } : item));
            itemsRef.current = next;

            return next;
        });
    }, []);

    /** Uploads one item; resolves with its new id, or null when it failed or was removed. */
    const upload = useCallback(
        (key: string, blob: Blob, role: LookRole) => {
            const { promise, abort } = uploadLookImage(blob, role, (fraction) => patch(key, { progress: fraction }));
            patch(key, { status: 'uploading', progress: 0, error: undefined, abort, id: null, sent: false });

            return promise.then(
                (image) => {
                    if (!itemsRef.current.some((item) => item.key === key)) {
                        deleteLookImage(image.id);

                        return null;
                    }
                    patch(key, { status: 'done', progress: 1, id: image.id, abort: undefined });

                    return image.id;
                },
                (error: UploadError) => {
                    if (error.message !== 'aborted') {
                        patch(key, { status: 'failed', error: error.message || undefined, abort: undefined });
                    }

                    return null;
                },
            );
        },
        [patch],
    );

    const add = useCallback(
        (files: File[]) => {
            const images = files.filter(isImageFile);
            if (images.length === 0) return;

            const reserved: TrayItem[] = [];
            let pool = [...itemsRef.current];
            let full = false;
            for (let i = 0; i < images.length; i++) {
                const role = pool.length < limits.total ? suggestRole(pool, limits) : null;
                if (!role) {
                    full = true;
                    break;
                }
                const item: TrayItem = { key: nextKey(), role, preview: '', status: 'compressing', progress: 0, id: null, dark: false };
                reserved.push(item);
                pool = [...pool, item];
            }
            if (full) setNotice(tr(l.full, { n: limits.total }));
            if (reserved.length === 0) return;

            itemsRef.current = pool;
            setItems(pool);

            reserved.forEach((item, i) => {
                compressImage(images[i]).then(
                    (compressed) => {
                        if (!itemsRef.current.some((x) => x.key === item.key)) return;
                        const before = itemsRef.current.find((x) => x.key === item.key);
                        const better = before && before.role === item.role ? refineRole(itemsRef.current, item.key, compressed.sketch, limits) : null;
                        patch(item.key, { preview: URL.createObjectURL(compressed.blob), dark: compressed.dark, blob: compressed.blob, ...(better ? { role: better } : {}) });
                        const current = itemsRef.current.find((x) => x.key === item.key);
                        void upload(item.key, compressed.blob, better ?? current?.role ?? item.role);
                    },
                    (error: unknown) => {
                        setItems((list) => (itemsRef.current = list.filter((x) => x.key !== item.key)));
                        setNotice(error instanceof UnsupportedImageError ? l.unsupported : l.failed);
                    },
                );
            });
        },
        [limits, l, tr, patch, upload],
    );

    const remove = useCallback((key: string) => {
        const item = itemsRef.current.find((x) => x.key === key);
        if (!item) return;
        item.abort?.();
        if (item.id && !item.sent) deleteLookImage(item.id);
        if (item.preview) URL.revokeObjectURL(item.preview);
        setItems((list) => (itemsRef.current = list.filter((x) => x.key !== key)));
    }, []);

    const retry = useCallback(
        (key: string) => {
            const item = itemsRef.current.find((x) => x.key === key);
            if (item?.blob) void upload(key, item.blob, item.role);
        },
        [upload],
    );

    const setRole = useCallback((key: string, role: LookRole) => patch(key, { role }), [patch]);

    /** After a submit the server owns those images: a later submit uploads the same pictures again. */
    const markSent = useCallback(() => {
        setItems((list) => (itemsRef.current = list.map((item) => (item.status === 'done' ? { ...item, sent: true } : item))));
    }, []);

    /** Ids in the order shown, re-uploading pictures already used by an earlier request. */
    const ensureUploaded = useCallback(async () => {
        const sorted = sortTray(itemsRef.current).filter((item) => item.status === 'done');
        const ids = await Promise.all(sorted.map((item) => (item.sent && item.blob ? upload(item.key, item.blob, item.role) : Promise.resolve(item.id))));

        return sorted.flatMap((item, i) => (ids[i] ? [{ id: ids[i] as number, role: itemsRef.current.find((x) => x.key === item.key)?.role ?? item.role }] : []));
    }, [upload]);

    const busy = items.some((item) => item.status === 'compressing' || item.status === 'uploading');

    return { items, sorted: sortTray(items), add, remove, retry, setRole, markSent, ensureUploaded, busy, notice, limits };
}

export type ReferenceTrayState = ReturnType<typeof useReferenceTray>;

function ProgressRing({ value }: { value: number }) {
    const r = 15;
    const c = 2 * Math.PI * r;

    return (
        <svg viewBox="0 0 36 36" className="size-10 -rotate-90 drop-shadow" aria-hidden>
            <circle cx="18" cy="18" r={r} fill="none" stroke="currentColor" strokeOpacity={0.3} strokeWidth={3} />
            <circle cx="18" cy="18" r={r} fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c * (1 - Math.max(0.04, value))} className="transition-[stroke-dashoffset] duration-200" />
        </svg>
    );
}

function RolePicker({ tray, item }: { tray: ReferenceTrayState; item: TrayItem }) {
    const { t } = useI18n();
    const look = t.studio.look;
    const mobile = useIsMobile();
    const [open, setOpen] = useState(false);
    const counts = countRoles(tray.items, item.key);
    const full = (role: LookRole) => counts[role] >= tray.limits.roles[role];

    const trigger = (
        <button
            type="button"
            aria-label={`${look.tray.role}: ${look.roles[item.role]}`}
            onClick={mobile ? () => setOpen(true) : undefined}
            className="bg-muted/70 hover:bg-muted data-[state=open]:bg-muted flex h-9 w-full min-w-0 cursor-pointer items-center justify-between gap-1 rounded-lg px-2 text-[11px] font-medium transition-colors pointer-coarse:h-11"
        >
            <span className="truncate">{look.roles[item.role]}</span>
            <ChevronDown className="text-muted-foreground size-3.5 shrink-0" />
        </button>
    );

    if (mobile) {
        return (
            <>
                {trigger}
                <Sheet open={open} onOpenChange={setOpen}>
                    <SheetContent side="bottom" className="rounded-t-2xl pb-[calc(1rem+env(safe-area-inset-bottom))]">
                        <SheetHeader className="text-start">
                            <SheetTitle>{look.tray.role}</SheetTitle>
                        </SheetHeader>
                        <div className="grid gap-1 px-4">
                            {ROLE_ORDER.map((role) => (
                                <button
                                    key={role}
                                    type="button"
                                    disabled={full(role)}
                                    aria-pressed={item.role === role}
                                    onClick={() => {
                                        tray.setRole(item.key, role);
                                        setOpen(false);
                                    }}
                                    className={cn(
                                        'flex min-h-12 cursor-pointer items-center justify-between rounded-xl px-4 text-start text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-45',
                                        item.role === role ? 'bg-primary/10 text-primary font-semibold' : 'hover:bg-muted',
                                    )}
                                >
                                    {look.roles[role]}
                                    {full(role) && <span className="text-muted-foreground text-xs">{look.tray.roleFull}</span>}
                                </button>
                            ))}
                        </div>
                    </SheetContent>
                </Sheet>
            </>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="min-w-48">
                <DropdownMenuLabel className="text-muted-foreground text-xs">{look.tray.role}</DropdownMenuLabel>
                {ROLE_ORDER.map((role) => (
                    <DropdownMenuItem key={role} disabled={full(role)} onSelect={() => tray.setRole(item.key, role)} className={cn('min-h-10 justify-between', item.role === role && 'text-primary font-medium')}>
                        {look.roles[role]}
                        {full(role) && <span className="text-muted-foreground text-[11px]">{look.tray.roleFull}</span>}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function Thumb({ tray, item }: { tray: ReferenceTrayState; item: TrayItem }) {
    const { t } = useI18n();
    const l = t.studio.look.tray;
    const reduced = useReducedMotion();

    return (
        <motion.li layout={!reduced} initial={reduced ? false : { opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.9 }} className="min-w-0 space-y-1.5">
            <div className="bg-muted relative aspect-square overflow-hidden rounded-xl border">
                {item.preview ? <img src={item.preview} alt={t.studio.look.roles[item.role]} className="size-full object-cover" draggable={false} /> : <div className="shimmer size-full" />}

                {(item.status === 'compressing' || item.status === 'uploading') && (
                    <div className="absolute inset-0 flex items-center justify-center bg-black/35 text-white" role="status" aria-label={item.status === 'compressing' ? l.compressing : `${l.uploading} ${Math.round(item.progress * 100)}%`}>
                        {item.status === 'compressing' ? <Loader2 className="size-6 animate-spin" /> : <ProgressRing value={item.progress} />}
                    </div>
                )}

                {item.status === 'failed' && (
                    <button type="button" onClick={() => tray.retry(item.key)} className="bg-danger/85 absolute inset-0 flex cursor-pointer flex-col items-center justify-center gap-1 p-1.5 text-center text-[11px] leading-tight font-medium text-white">
                        <RotateCw className="size-5" />
                        {item.error || l.failed}
                    </button>
                )}

                {item.dark && item.status !== 'failed' && (
                    <span className="bg-warning text-white absolute start-1.5 bottom-1.5 flex size-6 items-center justify-center rounded-full shadow" title={l.dark}>
                        <AlertTriangle className="size-3.5" />
                    </span>
                )}

                <button
                    type="button"
                    onClick={() => tray.remove(item.key)}
                    aria-label={l.remove}
                    className="absolute end-1 top-1 flex size-8 cursor-pointer items-center justify-center rounded-full bg-black/55 text-white backdrop-blur transition-transform hover:scale-105 pointer-coarse:size-10"
                >
                    <X className="size-4" />
                </button>
            </div>
            <RolePicker tray={tray} item={item} />
        </motion.li>
    );
}

/**
 * Sketches, fabric photos and details for a look. Phones take a photo or
 * pick from the gallery; desktops drop or paste images too.
 */
export function ReferenceTray({ tray, dragging, error }: { tray: ReferenceTrayState; dragging: boolean; error?: string }) {
    const { t, tr } = useI18n();
    const l = t.studio.look.tray;
    const cameraRef = useRef<HTMLInputElement>(null);
    const galleryRef = useRef<HTMLInputElement>(null);
    const full = tray.items.length >= tray.limits.total;
    const anyDark = tray.items.some((item) => item.dark);

    const onPick = (event: React.ChangeEvent<HTMLInputElement>) => {
        tray.add(Array.from(event.target.files ?? []));
        event.target.value = '';
    };

    return (
        <section className="space-y-3" aria-labelledby="look-tray-heading">
            <div className="flex items-baseline justify-between gap-2">
                <h3 id="look-tray-heading" className="text-sm font-semibold">
                    {tr(l.heading, { n: tray.limits.total })}
                </h3>
                <span className="text-muted-foreground num text-xs" dir="ltr">
                    {tray.items.length}/{tray.limits.total}
                </span>
            </div>

            {tray.items.length > 0 && (
                <ul className="grid grid-cols-3 gap-2">
                    <AnimatePresence initial={false}>
                        {tray.sorted.map((item) => (
                            <Thumb key={item.key} tray={tray} item={item} />
                        ))}
                    </AnimatePresence>
                </ul>
            )}

            <input ref={cameraRef} type="file" accept="image/*" capture="environment" className="sr-only" tabIndex={-1} onChange={onPick} />
            <input ref={galleryRef} type="file" accept="image/*" multiple className="sr-only" tabIndex={-1} onChange={onPick} />

            {!full && (
                <>
                    {/* Phones: the camera and the gallery are two buttons; on Android `capture` hides the gallery. */}
                    <div className="grid grid-cols-2 gap-2 md:hidden">
                        <button type="button" onClick={() => cameraRef.current?.click()} className="bg-primary/8 text-primary hover:bg-primary/12 dark:bg-primary/15 flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-xl text-sm font-medium transition-colors">
                            <Camera className="size-5" />
                            {l.camera}
                        </button>
                        <button type="button" onClick={() => galleryRef.current?.click()} className="hover:bg-muted flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-xl border text-sm font-medium transition-colors">
                            <Images className="size-5" />
                            {l.gallery}
                        </button>
                    </div>
                    <button
                        type="button"
                        onClick={() => galleryRef.current?.click()}
                        className={cn(
                            'hidden w-full cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed px-4 text-center transition-colors md:flex',
                            tray.items.length ? 'py-4' : 'py-8',
                            dragging ? 'border-primary bg-primary/5 text-primary' : 'text-muted-foreground hover:border-primary/40 hover:bg-muted/40',
                        )}
                    >
                        {dragging ? <UploadCloud className="size-6" /> : <ImagePlus className="size-6" />}
                        <span className="text-foreground text-sm font-medium">{dragging ? l.dropActive : l.drop}</span>
                        <span className="text-xs">{l.browse}</span>
                    </button>
                </>
            )}

            {tray.items.length === 0 && <p className="text-muted-foreground text-xs leading-relaxed">{l.hint}</p>}

            <div aria-live="polite" className="space-y-2 empty:hidden">
                {anyDark && (
                    <p className="bg-warning-soft text-warning flex items-start gap-2 rounded-lg px-3 py-2 text-xs leading-relaxed">
                        <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                        {l.dark}
                    </p>
                )}
                {tray.notice && <p className="bg-danger-soft text-danger rounded-lg px-3 py-2 text-xs leading-relaxed">{tray.notice}</p>}
                {error && <p className="text-danger text-xs">{error}</p>}
            </div>
        </section>
    );
}
