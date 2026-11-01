import { account as arAccount } from './ar/account';
import { activity as arActivity } from './ar/activity';
import { admin as arAdmin } from './ar/admin';
import { auth as arAuth } from './ar/auth';
import { balance as arBalance } from './ar/balance';
import { calendar as arCalendar } from './ar/calendar';
import { common as arCommon } from './ar/common';
import { dashboard as arDashboard } from './ar/dashboard';
import { evaluation as arEvaluation } from './ar/evaluation';
import { market as arMarket } from './ar/market';
import { nav as arNav } from './ar/nav';
import { notifications as arNotifications } from './ar/notifications';
import { performance as arPerformance } from './ar/performance';
import { reports as arReports } from './ar/reports';
import { settings as arSettings } from './ar/settings';
import { studio as arStudio } from './ar/studio';
import { productReview as arProductReview } from './ar/product-review';
import { account as enAccount } from './en/account';
import { activity as enActivity } from './en/activity';
import { admin as enAdmin } from './en/admin';
import { auth as enAuth } from './en/auth';
import { balance as enBalance } from './en/balance';
import { calendar as enCalendar } from './en/calendar';
import { common as enCommon } from './en/common';
import { dashboard as enDashboard } from './en/dashboard';
import { evaluation as enEvaluation } from './en/evaluation';
import { market as enMarket } from './en/market';
import { nav as enNav } from './en/nav';
import { notifications as enNotifications } from './en/notifications';
import { performance as enPerformance } from './en/performance';
import { reports as enReports } from './en/reports';
import { settings as enSettings } from './en/settings';
import { studio as enStudio } from './en/studio';
import { productReview as enProductReview } from './en/product-review';

/**
 * One namespace per module, so modules can be translated independently.
 * Arabic is the source of truth; every English namespace is typed against
 * its Arabic counterpart, so a missing key is a type error.
 */
export const ar = {
    common: arCommon,
    nav: arNav,
    auth: arAuth,
    admin: arAdmin,
    dashboard: arDashboard,
    performance: arPerformance,
    settings: arSettings,
    evaluation: arEvaluation,
    studio: arStudio,
    productReview: arProductReview,
    market: arMarket,
    balance: arBalance,
    calendar: arCalendar,
    reports: arReports,
    notifications: arNotifications,
    activity: arActivity,
    account: arAccount,
};

export type Dictionary = typeof ar;

export const en: Dictionary = {
    common: enCommon,
    nav: enNav,
    auth: enAuth,
    admin: enAdmin,
    dashboard: enDashboard,
    performance: enPerformance,
    settings: enSettings,
    evaluation: enEvaluation,
    studio: enStudio,
    productReview: enProductReview,
    market: enMarket,
    balance: enBalance,
    calendar: enCalendar,
    reports: enReports,
    notifications: enNotifications,
    activity: enActivity,
    account: enAccount,
};

export type Locale = 'ar' | 'en';

export const dictionaries: Record<Locale, Dictionary> = { ar, en };
