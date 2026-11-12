export type User = {
    id: number;
    name: string;
    email: string;
    phone?: string | null;
    avatar?: string;
    role: 'admin' | 'store_owner';
    status: 'active' | 'disabled';
    locale: 'ar' | 'en';
    last_login_at?: string | null;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type CurrentStore = {
    id: number;
    name: string;
    slug: string;
    status: 'active' | 'suspended';
    platform: string;
    connection_status: 'connected' | 'disconnected' | 'error';
    /** Revenue figures are shown only when the platform admin allows it. */
    show_revenue: boolean;
    logo_url: string | null;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
