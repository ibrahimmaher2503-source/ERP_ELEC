<?php

namespace Database\Factories\Support;

/** Fictional Egyptian-market fixtures. No real contact, credential, certified specification or quoted market price. */
final class EgyptianMarket
{
    public const PREFIX = 'DEMO-EG-';

    public static function locations(): array
    {
        return [
            ['القاهرة', 'مدينة نصر', 'شارع مصطفى النحاس', '02', '11765'], ['القاهرة', 'العباسية', 'شارع رمسيس', '02', '11517'],
            ['الجيزة', 'الدقي', 'شارع التحرير', '02', '12611'], ['الجيزة', 'السادس من أكتوبر', 'المنطقة الصناعية الثانية', '02', '12566'],
            ['الإسكندرية', 'برج العرب', 'المنطقة الصناعية الثالثة', '03', '21934'], ['الإسكندرية', 'سموحة', 'شارع فوزي معاذ', '03', '21648'],
            ['الشرقية', 'العاشر من رمضان', 'المنطقة الصناعية الأولى', '055', '44629'], ['القليوبية', 'العبور', 'المنطقة الصناعية الأولى', '02', '11828'],
            ['الدقهلية', 'المنصورة', 'شارع الجيش', '050', '35511'], ['الغربية', 'طنطا', 'شارع البحر', '040', '31511'],
            ['الغربية', 'المحلة الكبرى', 'شارع شكري القوتلي', '040', '31951'], ['المنوفية', 'شبين الكوم', 'شارع الجلاء', '048', '32511'],
            ['الشرقية', 'الزقازيق', 'شارع طلبة عويضة', '055', '44511'], ['البحيرة', 'دمنهور', 'شارع عبدالسلام الشاذلي', '045', '22511'],
            ['دمياط', 'دمياط الجديدة', 'المنطقة الصناعية', '057', '34517'], ['بورسعيد', 'حي الشرق', 'شارع الجمهورية', '066', '42511'],
            ['الإسماعيلية', 'حي أول', 'شارع الثلاثيني', '064', '41511'], ['السويس', 'حي الأربعين', 'شارع الجيش', '062', '43511'],
            ['الفيوم', 'مدينة الفيوم', 'شارع جمال عبدالناصر', '084', '63511'], ['بني سويف', 'بني سويف الجديدة', 'المنطقة الصناعية', '082', '62511'],
            ['المنيا', 'المنيا الجديدة', 'المنطقة الصناعية', '086', '61511'], ['أسيوط', 'مدينة أسيوط', 'شارع الهلالي', '088', '71511'],
            ['سوهاج', 'مدينة سوهاج', 'شارع الجمهورية', '093', '82511'], ['قنا', 'مدينة قنا', 'شارع 23 يوليو', '096', '83511'],
            ['الأقصر', 'مدينة الأقصر', 'شارع التلفزيون', '095', '85951'], ['أسوان', 'مدينة أسوان', 'شارع السوق', '097', '81511'],
            ['البحر الأحمر', 'الغردقة', 'حي الدهار', '065', '84511'], ['كفر الشيخ', 'مدينة كفر الشيخ', 'شارع الخليفة المأمون', '047', '33511'],
            ['القاهرة', 'بدر', 'المنطقة الصناعية', '02', '11829'], ['القاهرة', 'التجمع الخامس', 'المنطقة الصناعية', '02', '11835'],
        ];
    }

    public static function location(int $i): array
    {
        [$governorate,$city,$street,$dial,$postal] = self::locations()[($i - 1) % count(self::locations())];

        return ['governorate' => $governorate, 'city' => $city, 'street' => $street, 'dial' => $dial, 'postal_code' => $postal, 'line' => (10 + $i % 190).' '.$street.'، '.$city.'، '.$governorate];
    }

    public static function person(int $i): string
    {
        $first = ['أحمد', 'محمد', 'محمود', 'مصطفى', 'عمر', 'خالد', 'يوسف', 'حسن', 'إبراهيم', 'عادل', 'فاطمة', 'منى', 'سلمى', 'ياسمين', 'نورهان', 'آية', 'مريم', 'هبة', 'إيمان', 'دينا'];
        $middle = ['محمود', 'عبدالله', 'حسن', 'إبراهيم', 'سمير', 'عادل', 'السيد', 'عبدالرحمن', 'محمد', 'يوسف'];
        $last = ['المصري', 'حسن', 'النجار', 'منصور', 'إبراهيم', 'سليمان', 'عبدالعزيز', 'الشريف', 'مرسي', 'صبري', 'الشافعي', 'دسوقي', 'سالم', 'فوزي', 'فتحي'];

        return $first[$i % 20].' '.$middle[intdiv($i, 20) % 10].' '.$last[intdiv($i, 200) % 15];
    }

    public static function mobile(int $i): string
    {
        return '+20'.['10', '11', '12', '15'][$i % 4].str_pad((string) (70000000 + $i % 9999999), 8, '0', STR_PAD_LEFT);
    }

    public static function phone(int $i): string
    {
        $l = self::location($i);
        $length = $l['dial'] === '02' ? 8 : 7;

        return '+20'.substr($l['dial'], 1).str_pad((string) (2100000 + $i % 799999), $length, '2', STR_PAD_LEFT);
    }

    public static function party(int $i, string $kind = 'customer'): array
    {
        $l = self::location($i);
        $supplier = $kind === 'supplier';
        $names = ['النور', 'الدلتا', 'المصرية', 'الصفوة', 'الرواد', 'المتحدة', 'الأفق', 'النيل', 'اليسر', 'الفتح', 'الشروق', 'البركة', 'المنار', 'الهدف', 'الأمان', 'العاصمة', 'النهضة', 'الإتقان', 'النخبة', 'المستقبل'];
        $areas = ['للمقاولات الكهربائية', 'للتوريدات الصناعية', 'للوحات والتحكم', 'للمشروعات الكهروميكانيكية', 'للتجهيزات الكهربائية', 'للمقاولات والتجارة', 'للإنارة الحديثة', 'للهندسة والخدمات'];
        $suffix = $supplier ? 'SUP' : 'CUS';
        $code = self::PREFIX.$suffix.'-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);
        $name = 'شركة '.$names[$i % 20].' '.$areas[intdiv($i, 20) % 8].' - '.$l['city'].' '.(1 + intdiv($i, 160));

        return ['code' => $code, 'name' => $name, 'name_en' => 'Egyptian '.($supplier ? 'Supply' : 'Projects').' Company '.$i, 'trading_name' => $names[$i % 20].' '.$l['city'], 'entity_type' => 'organization', 'status' => $i % 31 === 0 ? 'archived' : ($i % 11 === 0 ? 'inactive' : 'active'), 'registration_number' => 'DEMO-CR-'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'tax_number' => 'DEMO-TAX-'.str_pad((string) $i, 9, '0', STR_PAD_LEFT), 'country' => 'مصر', 'city' => $l['city'], 'phone' => self::phone($i), 'mobile' => self::mobile($i), 'email' => strtolower($suffix).'.'.$i.'@egypt-market.example.test', 'website' => 'https://'.strtolower($suffix).'-'.$i.'.example.test', 'version' => 1] + ($supplier ? ['supplier_type' => ['manufacturer', 'distributor', 'service_provider', 'other'][$i % 4]] : ['segment' => ['مقاولات كهربائية', 'تصنيع لوحات', 'مصانع ومنشآت صناعية', 'تجارة جملة', 'تجارة التجزئة'][$i % 5]]);
    }

    public static function contact(int $i, int $n = 0): array
    {
        return ['name' => self::person($i * 3 + $n), 'job_title' => ['مدير المشتريات', 'مهندس مشروعات', 'مسؤول الحسابات'][$n % 3], 'phone' => self::phone($i), 'mobile' => self::mobile($i * 3 + $n), 'email' => 'contact.'.$i.'.'.$n.'@egypt-market.example.test', 'active' => true, 'is_primary' => $n === 0];
    }

    public static function address(int $i, string $purpose): array
    {
        $l = self::location($i);

        return ['label' => ['billing' => 'المكتب الرئيسي والفواتير', 'shipping' => 'موقع استلام التوريدات', 'registered' => 'المقر المسجل', 'office' => 'مكتب المبيعات', 'pickup' => 'نقطة استلام البضاعة', 'other' => 'موقع إضافي'][$purpose], 'purpose' => $purpose, 'line' => $l['line'], 'country' => 'مصر', 'city' => $l['city'], 'region' => $l['governorate'], 'postal_code' => $l['postal_code'], 'is_primary' => true];
    }

    public static function product(int $i): array
    {
        $family = ($i - 1) % 16;
        $variant = intdiv($i - 1, 16);
        $v = $variant % 12;
        $series = 1 + intdiv($variant, 12);
        $amps = ['6', '10', '16', '20', '25', '32', '40', '50', '63', '80', '100', '125'];
        $sections = ['1.5', '2.5', '4', '6', '10', '16', '25', '35', '50', '70', '95', '120'];
        $base = 'PIECE';
        $trade = 'BOX10';
        $kind = 'bulk';
        $tracking = 'quantity';
        $category = 'PROTECTION';
        $price = '125';
        $attributes = [];
        switch ($family) {
            case 0:$a = $amps[$v];
                $name = "قاطع مصغر MCB أحادي {$a} أمبير";
                $model = "MCB-1P-{$a}A-C";
                $attributes = ['التيار المقنن' => $a.' A', 'عدد الأقطاب' => '1P', 'منحنى الفصل' => 'C'];
                $price = (string) (90 + $v * 12);
                break;
            case 1:$a = $amps[$v];
                $name = "قاطع مصغر MCB ثلاثي {$a} أمبير";
                $model = "MCB-3P-{$a}A-C";
                $attributes = ['التيار المقنن' => $a.' A', 'عدد الأقطاب' => '3P', 'منحنى الفصل' => 'C'];
                $price = (string) (280 + $v * 35);
                break;
            case 2:$a = ['100', '125', '160', '200', '250', '320', '400', '500', '630', '800', '1000', '1250'][$v];
                $name = "قاطع مصبوب MCCB ثلاثي {$a} أمبير";
                $model = "MCCB-3P-{$a}A";
                $attributes = ['التيار المقنن' => $a.' A', 'عدد الأقطاب' => '3P'];
                $trade = 'PIECE';
                $kind = 'serialized_equipment';
                $tracking = 'serial';
                $price = (string) (1600 + $v * 700);
                break;
            case 3:$a = $amps[$v];
                $name = "قاطع تسريب أرضي ثنائي {$a} أمبير";
                $model = "RCCB-2P-{$a}A";
                $attributes = ['التيار المقنن' => $a.' A', 'عدد الأقطاب' => '2P', 'النوع' => 'حماية تسريب أرضي'];
                $price = (string) (450 + $v * 40);
                break;
            case 4:$a = $sections[$v];
                $color = ['أحمر', 'أزرق', 'بني', 'أسود'][$variant % 4];
                $name = "سلك نحاس مفرد {$a} مم² {$color}";
                $model = "CU-PVC-1X{$a}-".($variant % 4);
                $category = 'CABLES';
                $base = 'METER';
                $trade = 'ROLL100';
                $attributes = ['مقطع الموصل' => $a.' mm²', 'الموصل' => 'نحاس', 'لون العازل' => $color];
                $price = (string) (8 + $v * 17);
                break;
            case 5:$a = $sections[$v];
                $name = "كابل نحاس 4 × {$a} مم²";
                $model = "CU-PVC-4X{$a}";
                $category = 'CABLES';
                $base = 'METER';
                $trade = 'ROLL100';
                $attributes = ['عدد القلوب' => '4', 'مقطع الموصل' => $a.' mm²', 'الموصل' => 'نحاس'];
                $price = (string) (42 + $v * 65);
                break;
            case 6:$a = ['9', '12', '18', '25', '32', '40', '50', '65', '80', '95', '115', '150'][$v];
                $name = "كونتاكتور {$a} أمبير ملف 230 فولت";
                $model = "CONT-{$a}A-230VAC";
                $category = 'CONTROL';
                $attributes = ['التيار المقنن' => $a.' A', 'جهد الملف' => '230 VAC'];
                $price = (string) (300 + $v * 120);
                break;
            case 7:$a = ['1-1.6', '1.6-2.5', '2.5-4', '4-6', '6-10', '9-13', '12-18', '17-25', '23-32', '30-40', '37-50', '48-65'][$v];
                $name = "مرحل حماية حراري نطاق {$a} أمبير";
                $model = 'THERMAL-'.str_replace('.', 'D', $a);
                $category = 'CONTROL';
                $attributes = ['نطاق التيار' => $a.' A', 'الاستخدام' => 'حماية المحركات'];
                $price = (string) (240 + $v * 55);
                break;
            case 8:$a = (string) (20 + $v * 10);
                $name = "كشاف LED خارجي {$a} وات";
                $model = "LED-FLOOD-{$a}W";
                $category = 'LIGHTING';
                $attributes = ['القدرة' => $a.' W', 'لون الإضاءة' => 'أبيض نهاري', 'الاستخدام' => 'إنارة خارجية'];
                $price = (string) (180 + $v * 95);
                break;
            case 9:$a = ['4', '6', '8', '10', '12', '16', '18', '20', '24', '30', '36', '48'][$v];
                $name = "لوحة توزيع كهرباء {$a} خط";
                $model = "DB-{$a}-SURFACE";
                $category = 'PANELS';
                $trade = 'PIECE';
                $attributes = ['عدد الخطوط' => $a, 'التركيب' => 'ظاهري'];
                $price = (string) (190 + $v * 75);
                break;
            case 10:$a = (string) (16 + $v * 2);
                $name = "مفتاح فصل دوار {$a} أمبير";
                $model = "ROTARY-{$a}A";
                $category = 'CONTROL';
                $attributes = ['التيار المقنن' => $a.' A', 'طريقة التشغيل' => 'يدوي'];
                $price = (string) (90 + $v * 25);
                break;
            case 11:$a = ['16', '20', '25', '32', '40', '50', '63', '75', '90', '110', '125', '160'][$v];
                $name = "ماسورة PVC كهرباء قطر {$a} مم طول 3 م";
                $model = "PVC-CONDUIT-{$a}MM-3M";
                $category = 'INSTALLATION';
                $attributes = ['القطر' => $a.' mm', 'طول القطعة' => '3 m', 'الخامة' => 'PVC'];
                $price = (string) (18 + $v * 14);
                break;
            case 12:$a = (string) (50 + $v * 50);
                $name = "حامل كابلات عرض {$a} مم طول 2 م";
                $model = "TRAY-{$a}MM-2M";
                $category = 'INSTALLATION';
                $trade = 'PIECE';
                $attributes = ['العرض' => $a.' mm', 'طول القطعة' => '2 m', 'الخامة' => 'صاج مجلفن'];
                $price = (string) (110 + $v * 70);
                break;
            case 13:$a = ['12', '16', '20', '25', '32', '40', '50', '63', '75', '90', '100', '110'][$v];
                $name = "جلاند كابل مقاس M{$a}";
                $model = "GLAND-M{$a}";
                $category = 'ACCESSORIES';
                $attributes = ['مقاس القلاوظ' => 'M'.$a, 'الاستخدام' => 'تثبيت الكابلات'];
                $price = (string) (8 + $v * 7);
                break;
            case 14:$a = $sections[$v];
                $name = "بلوك طرفي نحاس {$a} مم²";
                $model = "TERMINAL-{$a}MM2";
                $category = 'ACCESSORIES';
                $attributes = ['مقطع التوصيل' => $a.' mm²', 'التركيب' => 'سكة DIN'];
                $price = (string) (12 + $v * 9);
                break;
            default:$a = ['0.37', '0.55', '0.75', '1.1', '1.5', '2.2', '3', '4', '5.5', '7.5', '11', '15'][$v];
                $name = "محرك كهربائي ثلاثي {$a} كيلووات";
                $model = "MOTOR-3PH-{$a}KW";
                $category = 'EQUIPMENT';
                $trade = 'PIECE';
                $kind = 'serialized_equipment';
                $tracking = 'serial';
                $attributes = ['القدرة' => $a.' kW', 'التغذية' => 'ثلاثي الأوجه'];
                $price = (string) (1900 + $v * 1400);
                break;
        }
        $brand = 1 + $variant % 12;

        return ['sku' => self::PREFIX.'PROD-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'name' => $name.' - سلسلة '.$series.' / فئة '.$brand, 'name_en' => $model.' Series '.$series.' Class '.$brand, 'short_description' => $name.' للاستخدام في مشروعات الكهرباء والتوريدات داخل مصر.', 'description' => $name.'. سجل تجريبي لتجربة دورة التوريد والمبيعات. المواصفات إيضاحية وليست شهادة اعتماد من مصنع.', 'kind' => $kind, 'tracking' => $tracking, 'model' => $model.'-S'.$series.'-B'.$brand, 'barcode' => 'EGDEMO'.str_pad((string) $i, 10, '0', STR_PAD_LEFT), 'active' => $i % 47 !== 0, 'version' => 1, 'internal_notes' => 'بيانات تجريبية فقط. لا يوجد سعر بيع منشور أو رصيد مخزني ناتج عن هذا السجل.', 'attributes' => array_map(fn ($k, $v) => ['name' => $k, 'value' => $v, 'unit_id' => null], array_keys($attributes), array_values($attributes)), 'fixture_category' => $category, 'fixture_base' => $base, 'fixture_trade' => $trade, 'fixture_brand' => $brand, 'fixture_price' => $price, 'fixture_family' => $family];
    }
}
