import type { SVGAttributes } from 'react';

/**
 * Mojo AI mark: an "M" drawn as one stroke with a gold spark (the AI).
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" {...props}>
            <path d="M15 45V23l17 14 17-14v22" stroke="currentColor" strokeWidth="5.5" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M49 7.5l2.4 5.1 5.1 2.4-5.1 2.4L49 22.5l-2.4-5.1-5.1-2.4 5.1-2.4z" fill="var(--gold)" />
        </svg>
    );
}
