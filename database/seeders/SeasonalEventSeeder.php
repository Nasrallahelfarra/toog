<?php

namespace Database\Seeders;

use App\Models\SeasonalEvent;
use Illuminate\Database\Seeder;

/**
 * Platform-wide Saudi seasonal calendar. Hijri-based dates are estimates
 * for the coming Gregorian year; the admin can adjust them.
 */
class SeasonalEventSeeder extends Seeder
{
    public function run(): void
    {
        $events = [
            ['white_friday', 'الجمعة البيضاء', 'White Friday', '2026-11-20', '2026-11-30', 4, ['black', 'white', 'gold']],
            ['winter', 'موسم الشتاء', 'Winter season', '2026-12-01', '2027-02-15', 6, ['brown', 'burgundy', 'olive']],
            ['founding_day', 'يوم التأسيس', 'Founding Day', '2027-02-22', '2027-02-22', 5, ['brown', 'sand', 'emerald']],
            ['ramadan', 'رمضان', 'Ramadan', '2027-02-08', '2027-03-09', 8, ['ivory', 'gold', 'emerald']],
            ['eid_fitr', 'عيد الفطر', 'Eid al-Fitr', '2027-03-10', '2027-03-13', 6, ['ivory', 'dusty_pink', 'gold']],
            ['eid_adha', 'عيد الأضحى', 'Eid al-Adha', '2027-05-16', '2027-05-19', 6, ['white', 'sage', 'gold']],
            ['wedding', 'موسم الأعراس', 'Wedding season', '2027-05-20', '2027-08-31', 6, ['ivory', 'gold', 'dusty_pink']],
            ['summer', 'الصيف', 'Summer', '2027-06-01', '2027-08-31', 5, ['white', 'sky_blue', 'sand']],
            ['back_to_school', 'العودة إلى المدارس', 'Back to school', '2027-08-15', '2027-09-05', 5, ['navy', 'grey']],
            ['national_day', 'اليوم الوطني', 'Saudi National Day', '2027-09-23', '2027-09-23', 6, ['emerald', 'white']],
        ];

        foreach ($events as [$key, $ar, $en, $start, $end, $prep, $palette]) {
            SeasonalEvent::query()->firstOrCreate(
                ['store_id' => null, 'season_key' => $key],
                ['name_ar' => $ar, 'name_en' => $en, 'starts_on' => $start, 'ends_on' => $end,
                    'prep_weeks' => $prep, 'palette' => $palette, 'is_active' => true],
            );
        }
    }
}
