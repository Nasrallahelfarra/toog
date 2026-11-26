/**
 * Shrinks a photo in the browser before it is uploaded: the long edge is
 * capped, the camera's EXIF rotation is applied and the result is a JPEG.
 * A phone photo of 6–12 MB becomes a few hundred KB, so the upload is quick
 * on a mobile connection and the server never has to decode HEIC.
 */

export class UnsupportedImageError extends Error {
    constructor() {
        super('unsupported-image');
        this.name = 'UnsupportedImageError';
    }
}

export type CompressedImage = {
    blob: Blob;
    width: number;
    height: number;
    /** Mean luminance under 60/255: colours in the photo may be off. */
    dark: boolean;
    /** Mostly plain light paper with a few lines: looks like a hand sketch, not a fabric. */
    sketch: boolean;
};

type Source = { image: CanvasImageSource; width: number; height: number; close: () => void };

/** Decode with the EXIF orientation applied; falls back to an <img> where createImageBitmap refuses. */
async function decode(file: Blob): Promise<Source> {
    if (typeof createImageBitmap === 'function') {
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });

            return { image: bitmap, width: bitmap.width, height: bitmap.height, close: () => bitmap.close() };
        } catch {
            // Older Safari rejects the options object; the <img> path below still applies EXIF.
        }
    }

    const url = URL.createObjectURL(file);

    try {
        const img = new Image();
        img.decoding = 'async';
        img.src = url;
        await img.decode();

        if (!img.naturalWidth || !img.naturalHeight) {
            throw new UnsupportedImageError();
        }

        return { image: img, width: img.naturalWidth, height: img.naturalHeight, close: () => URL.revokeObjectURL(url) };
    } catch {
        URL.revokeObjectURL(url);
        throw new UnsupportedImageError();
    }
}

/**
 * Mean luminance, and the share of pixels that are light and nearly
 * colourless (paper): a sketch is mostly paper with a few marker lines, a
 * fabric photo is textured and coloured all over.
 */
function analyse(source: CanvasImageSource): { luminance: number; paper: number } | null {
    const size = 32;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    if (!ctx) {
        return null;
    }

    ctx.drawImage(source, 0, 0, size, size);

    try {
        const { data } = ctx.getImageData(0, 0, size, size);
        let total = 0;
        let paper = 0;

        for (let i = 0; i < data.length; i += 4) {
            const luminance = 0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2];
            total += luminance;
            if (luminance > 140 && Math.max(data[i], data[i + 1], data[i + 2]) - Math.min(data[i], data[i + 1], data[i + 2]) < 40) {
                paper++;
            }
        }

        const pixels = data.length / 4;

        return { luminance: total / pixels, paper: paper / pixels };
    } catch {
        return null;
    }
}

export async function compressImage(file: Blob, { maxEdge = 2048, quality = 0.85 }: { maxEdge?: number; quality?: number } = {}): Promise<CompressedImage> {
    const source = await decode(file);

    try {
        const scale = Math.min(1, maxEdge / Math.max(source.width, source.height));
        const width = Math.max(1, Math.round(source.width * scale));
        const height = Math.max(1, Math.round(source.height * scale));
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            throw new UnsupportedImageError();
        }

        // JPEG has no transparency: a transparent PNG would otherwise turn black.
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, width, height);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(source.image, 0, 0, width, height);

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));

        if (!blob) {
            throw new UnsupportedImageError();
        }

        const look = analyse(canvas);

        return { blob, width, height, dark: look !== null && look.luminance < 60, sketch: look !== null && look.paper > 0.7 };
    } finally {
        source.close();
    }
}
