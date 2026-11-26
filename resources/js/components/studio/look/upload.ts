import type { LookRole } from './types';

export type UploadedImage = { id: number; role: LookRole; url: string; width: number; height: number };

export class UploadError extends Error {}

function xsrfToken(): string {
    const match = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

const headers = () => ({ 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' });

/** Uploads one reference image over XHR, so the thumbnail can show progress. */
export function uploadLookImage(blob: Blob, role: LookRole, onProgress: (fraction: number) => void): { promise: Promise<UploadedImage>; abort: () => void } {
    const xhr = new XMLHttpRequest();

    const promise = new Promise<UploadedImage>((resolve, reject) => {
        const body = new FormData();
        body.append('file', blob, 'image.jpg');
        body.append('role', role);

        xhr.open('POST', '/store/studio/uploads');
        Object.entries(headers()).forEach(([name, value]) => xhr.setRequestHeader(name, value));
        xhr.responseType = 'json';
        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded / event.total);
            }
        };
        xhr.onload = () => {
            const data = xhr.response as (UploadedImage & { message?: string; errors?: Record<string, string[]> }) | null;

            if (xhr.status >= 200 && xhr.status < 300 && data && typeof data.id === 'number') {
                resolve(data);

                return;
            }

            reject(new UploadError(data?.errors?.file?.[0] ?? data?.errors?.role?.[0] ?? (xhr.status === 422 ? data?.message : '') ?? ''));
        };
        xhr.onerror = () => reject(new UploadError(''));
        xhr.onabort = () => reject(new UploadError('aborted'));
        xhr.send(body);
    });

    return { promise, abort: () => xhr.abort() };
}

/** Frees an image that was never attached to a request. A failure is ignored: the thumbnail is gone either way. */
export function deleteLookImage(id: number) {
    void fetch(`/store/studio/uploads/${id}`, { method: 'DELETE', headers: headers(), credentials: 'same-origin' }).catch(() => undefined);
}
