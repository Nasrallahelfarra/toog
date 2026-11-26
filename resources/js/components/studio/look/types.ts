import type { DesignItem } from '@/components/evaluation/types';

export type LookRole = 'sketch_front' | 'sketch_back' | 'fabric' | 'detail';

/** The order thumbnails are shown and sent in. */
export const ROLE_ORDER: LookRole[] = ['sketch_front', 'sketch_back', 'fabric', 'detail'];

export const DEFAULT_LIMITS: LookLimits = { total: 6, roles: { sketch_front: 1, sketch_back: 1, fabric: 3, detail: 2 } };

export type LookLimits = { total: number; roles: Record<LookRole, number> };

export type LookProps = { limits: LookLimits; budgetExceeded: boolean };

export type PresentationMode = 'model' | 'mannequin' | 'flat';
export type FaceMode = 'cropped' | 'shown' | 'hijab';
export type Background = 'studio' | 'white' | 'majlis' | 'outdoor';
export type View = 'front' | 'back';

export type LookDesign = DesignItem & { variant?: number | null; view?: View | null };

export type LookRequestImage = { id: number; role: LookRole; url: string };

export type LookFailure = { variant: number; view: View; error: string };

export type LookPresentation = { mode: PresentationMode; face: FaceMode; background: Background; back_view: boolean };

export type LookRequestRow = {
    id: number;
    type: string;
    title: string | null;
    prompt: string;
    aspect_ratio: string;
    variants_count: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    error: string | null;
    created_at: string;
    designs: LookDesign[];
    images?: LookRequestImage[];
    presentation?: LookPresentation | null;
    failures?: LookFailure[] | null;
    slots?: number;
};

/** One thumbnail in the tray, from the moment it is picked until it is uploaded. */
export type TrayItem = {
    key: string;
    role: LookRole;
    /** Object URL of the compressed image, for the thumbnail. */
    preview: string;
    status: 'compressing' | 'uploading' | 'done' | 'failed';
    progress: number;
    id: number | null;
    dark: boolean;
    error?: string;
    /** Kept for a retry after a failed upload. */
    blob?: Blob;
    abort?: () => void;
    /** Already attached to a submitted request: the next submit uploads it again. */
    sent?: boolean;
};
