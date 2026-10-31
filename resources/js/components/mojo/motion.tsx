import { motion } from 'motion/react';
import type { HTMLMotionProps, Variants } from 'motion/react';
import type { ReactNode } from 'react';

const ease = [0.22, 1, 0.36, 1] as const;

/**
 * Fades and lifts content in on mount. Use on page sections.
 */
export function FadeIn({
    children,
    delay = 0,
    y = 10,
    className,
    ...props
}: HTMLMotionProps<'div'> & { delay?: number; y?: number; children?: ReactNode }) {
    return (
        <motion.div
            initial={{ opacity: 0, y }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.45, delay, ease }}
            className={className}
            {...props}
        >
            {children}
        </motion.div>
    );
}

const container: Variants = {
    hidden: {},
    show: { transition: { staggerChildren: 0.055, delayChildren: 0.04 } },
};

const item: Variants = {
    hidden: { opacity: 0, y: 12 },
    show: { opacity: 1, y: 0, transition: { duration: 0.4, ease } },
};

/**
 * Staggers its <StaggerItem> children in sequence — for card grids and lists.
 */
export function Stagger({ children, className, ...props }: HTMLMotionProps<'div'> & { children?: ReactNode }) {
    return (
        <motion.div variants={container} initial="hidden" animate="show" className={className} {...props}>
            {children}
        </motion.div>
    );
}

export function StaggerItem({ children, className, ...props }: HTMLMotionProps<'div'> & { children?: ReactNode }) {
    return (
        <motion.div variants={item} className={className} {...props}>
            {children}
        </motion.div>
    );
}

/**
 * Lifts slightly on hover; for clickable cards.
 */
export function HoverLift({ children, className, ...props }: HTMLMotionProps<'div'> & { children?: ReactNode }) {
    return (
        <motion.div
            whileHover={{ y: -3 }}
            transition={{ type: 'spring', stiffness: 300, damping: 22 }}
            className={className}
            {...props}
        >
            {children}
        </motion.div>
    );
}

export { ease };
