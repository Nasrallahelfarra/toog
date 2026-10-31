import { Link, router } from '@inertiajs/react';
import { Download, Heart, Trash2 } from 'lucide-react';
import type { DesignItem } from '@/components/evaluation/types';
import { ColorChips } from '@/components/mojo/color-chips';
import { ConfirmDialog } from '@/components/mojo/confirm-dialog';
import { scoreTone, toneClasses } from '@/components/mojo/tone';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import { updateDesign, useDesignLabels } from './design-card';

const STATUSES = ['draft', 'in_review', 'approved', 'rejected'] as const;

export function DesignDialog({ design, open, onOpenChange }: { design: DesignItem; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t, dateTime, usd } = useI18n();
    const d = t.studio.design;
    const { tagLabel } = useDesignLabels();
    const tone = toneClasses[scoreTone(design.score)];
    const outputs = t.studio.form.outputs as Record<string, string>;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{design.title}</DialogTitle>
                    <DialogDescription>{dateTime(design.created_at)}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-6 md:grid-cols-[3fr_2fr]">
                    <div className="bg-muted flex items-center justify-center overflow-hidden rounded-2xl">
                        <img src={design.image_url} alt={design.title} className="max-h-[70vh] w-full object-contain" />
                    </div>
                    <div className="space-y-4 text-sm">
                        <div className="flex items-center gap-3">
                            {design.score !== null && (
                                <div className={cn('rounded-xl px-3 py-2', tone.soft)}>
                                    <div className={cn('num text-2xl font-semibold', tone.text)}>{design.score}</div>
                                    <div className="text-muted-foreground text-[11px]">{d.score}</div>
                                </div>
                            )}
                            <div className="flex-1 space-y-1.5">
                                <Select value={design.status} onValueChange={(status) => updateDesign(design.id, { status })}>
                                    <SelectTrigger className="h-9 w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {STATUSES.map((s) => (
                                            <SelectItem key={s} value={s}>
                                                {d.status[s]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {design.evaluation_id && (
                                    <Link href={`/store/evaluations/${design.evaluation_id}`} className="text-primary block text-xs font-medium hover:underline">
                                        {d.viewEvaluation}
                                    </Link>
                                )}
                            </div>
                        </div>

                        {design.palette?.length ? (
                            <Row label={d.palette}>
                                <ColorChips colors={design.palette} showLabels={false} size="md" />
                            </Row>
                        ) : null}
                        <Row label={d.output}>{outputs[design.output_type] ?? design.output_type}</Row>
                        <Row label={d.ratio}>
                            <span dir="ltr">{design.aspect_ratio}</span>
                        </Row>
                        {design.tags?.length ? (
                            <Row label={d.tags}>
                                <div className="flex flex-wrap gap-1">
                                    {design.tags.map((tag) => (
                                        <Badge key={tag} variant="neutral">
                                            {tagLabel(tag)}
                                        </Badge>
                                    ))}
                                </div>
                            </Row>
                        ) : null}
                        <Row label={d.model}>
                            <span dir="ltr" className="text-xs">
                                {design.provider} · {design.model}
                            </span>
                        </Row>
                        <Row label={d.cost}>{usd(design.cost, 4)}</Row>
                        {design.prompt && (
                            <details className="group">
                                <summary className="text-muted-foreground cursor-pointer text-xs">{d.prompt}</summary>
                                <pre dir="ltr" className="bg-muted mt-2 max-h-48 overflow-auto rounded-lg p-3 text-[11px] leading-relaxed whitespace-pre-wrap">
                                    {design.prompt}
                                </pre>
                            </details>
                        )}

                        <div className="flex flex-wrap gap-2 pt-2">
                            <Button asChild size="sm">
                                <a href={`/store/designs/${design.id}/download`}>
                                    <Download className="size-4" />
                                    {d.download}
                                </a>
                            </Button>
                            <Button size="sm" variant="outline" onClick={() => updateDesign(design.id, { is_favorite: !design.is_favorite })}>
                                <Heart className={cn('size-4', design.is_favorite && 'fill-rose-400 text-rose-400')} />
                                {design.is_favorite ? d.unfavorite : d.favorite}
                            </Button>
                            <ConfirmDialog
                                destructive
                                description={d.deleteConfirm}
                                confirmLabel={t.common.delete}
                                onConfirm={() => router.delete(`/store/designs/${design.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) })}
                                trigger={
                                    <Button size="sm" variant="ghost" className="text-danger">
                                        <Trash2 className="size-4" />
                                        {d.delete}
                                    </Button>
                                }
                            />
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-[100px_1fr] items-center gap-3">
            <span className="text-muted-foreground text-xs">{label}</span>
            <div>{children}</div>
        </div>
    );
}
