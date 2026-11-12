import type { GarmentKey } from './types';

/**
 * Single-stroke garment silhouettes for the brief's garment tiles. Drawn in
 * currentColor so they follow the tile's state and the theme.
 */
const paths: Record<GarmentKey, string[]> = {
    abaya: [
        'M24 6c-2 0-4 1-5 3l-7 6 3 4 4-3-3 36h16l-3-36 4 3 3-4-7-6c-1-2-3-3-5-3z',
        'M24 9v40',
    ],
    dress_daily: ['M20 6h8l1 6-2 4 7 32H14l7-32-2-4z', 'M17 16h14'],
    dress_evening: [
        'M20 6h8l1 7-2 5c4 10 9 22 13 30H8c4-8 9-20 13-30l-2-5z',
        'M19 18h10',
    ],
    jalabiya: [
        'M18 6h12l3 4 7 8-3 3-5-4 1 31H15l1-31-5 4-3-3 7-8z',
        'M21 6l3 6 3-6',
    ],
    kaftan: ['M17 6h14l13 12-3 4-8-5 2 31H13l2-31-8 5-3-4z', 'M24 6v12'],
    kimono: [
        'M18 6h12l12 10-3 4-7-4v30H16V16l-7 4-3-4z',
        'M18 6l6 14 6-14',
        'M16 28h16',
    ],
    coord_set: [
        'M18 6h12l6 6-3 3-3-2v11H18V13l-3 2-3-3z',
        'M18 27h12l3 21h-6l-3-15-3 15h-6z',
    ],
    prayer_set: [
        'M24 4c-6 0-10 5-10 10v6l-6 28h32l-6-28v-6c0-5-4-10-10-10z',
        'M19 14c1-3 3-4 5-4s4 1 5 4',
    ],
};

export function GarmentIcon({
    garment,
    className,
}: {
    garment: GarmentKey;
    className?: string;
}) {
    return (
        <svg
            viewBox="0 0 48 52"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.5}
            strokeLinejoin="round"
            strokeLinecap="round"
            className={className}
            aria-hidden
        >
            {paths[garment].map((d) => (
                <path key={d} d={d} />
            ))}
        </svg>
    );
}
