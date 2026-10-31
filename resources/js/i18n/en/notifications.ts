import type { notifications as ar } from '../ar/notifications';

export const notifications: typeof ar = {
    title: 'Notifications',
    description: 'Finished evaluations, new trends, balance alerts and data syncs.',
    all: 'All',
    unread: 'Unread',
    markRead: 'Mark as read',
    markAllRead: 'Mark all as read',
    open: 'Open',
    empty: 'No notifications',
    emptyUnread: 'No unread notifications',
    emptyHint: 'Alerts appear here when evaluations finish or new trends emerge.',
    kinds: {
        evaluation: 'Evaluation',
        trend: 'Trend',
        balance: 'Balance',
        sync: 'Sync',
        design: 'Design',
        system: 'System',
    },
};
