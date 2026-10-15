<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catalog seeder (one-shot): default categories + services incl. the
 * special print service (srv-print-doc) the pricing engine knows about.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('categories')->exists()) {
            return;
        }

        $categories = [
            ['id' => 'cat-print', 'title' => 'پرینت و چاپ', 'icon_name' => 'printer', 'icon_color' => '#1559de', 'sort_order' => 1, 'description' => 'چاپ اسناد، جزوه و پروژه'],
            ['id' => 'cat-student', 'title' => 'خدمات دانشجویی', 'icon_name' => 'student', 'icon_color' => '#475d93', 'sort_order' => 2, 'description' => 'ثبت‌نام، پروژه و امور دانشگاه'],
            ['id' => 'cat-gov', 'title' => 'خدمات دولتی و اداری', 'icon_name' => 'government', 'icon_color' => '#a82cc7', 'sort_order' => 3, 'description' => 'پیگیری امور اداری و دولتی'],
            ['id' => 'cat-tax', 'title' => 'امور مالیاتی و وام', 'icon_name' => 'tax', 'icon_color' => '#124be0', 'sort_order' => 4, 'description' => 'مالیات، وام و امور بانکی'],
        ];
        foreach ($categories as $c) {
            DB::table('categories')->insert($c);
        }

        $services = [
            [
                'id' => 'srv-print-doc', 'category_id' => 'cat-print', 'title' => 'پرینت و تکثیر',
                'description' => 'چاپ سیاه‌وسفید و رنگی در سایز A4 و A3 با گزینه صحافی',
                'base_price' => 0, 'estimated_time' => '۱ الی ۳ ساعت', 'icon' => 'printer',
                'icon_color' => '#1559de', 'sort_order' => 1,
                'is_featured' => true, 'featured_sort' => 1,
                'fields_json' => json_encode([
                    ['id' => 'pageCount', 'label' => 'تعداد صفحات', 'type' => 'number', 'placeholder' => 'مثلاً ۱۰', 'required' => true],
                    ['id' => 'copies', 'label' => 'تعداد نسخه', 'type' => 'number', 'placeholder' => 'مثلاً ۱', 'required' => true],
                    ['id' => 'printColor', 'label' => 'نوع چاپ', 'type' => 'select', 'required' => true, 'options' => [
                        ['label' => 'سیاه‌وسفید (۱۵۰۰ تومان/صفحه)', 'value' => 'bw'],
                        ['label' => 'رنگی (۵۵۰۰ تومان/صفحه)', 'value' => 'color'],
                    ]],
                    ['id' => 'paperSize', 'label' => 'سایز کاغذ', 'type' => 'select', 'required' => true, 'options' => [
                        ['label' => 'A4', 'value' => 'A4'],
                        ['label' => 'A3 (دو برابر A4)', 'value' => 'A3'],
                    ]],
                    ['id' => 'binding', 'label' => 'صحافی', 'type' => 'select', 'required' => false, 'options' => [
                        ['label' => 'بدون صحافی', 'value' => 'none'],
                        ['label' => 'فن پلاستیکی (۲۵٬۰۰۰)', 'value' => 'plastic_coil', 'extraPrice' => 25000],
                        ['label' => 'فن فلزی (۴۰٬۰۰۰)', 'value' => 'metal_coil', 'extraPrice' => 40000],
                    ]],
                ], JSON_UNESCAPED_UNICODE),
                'pricing_formula' => 'تعداد صفحات × قیمت صفحه × ضریب سایز × تعداد نسخه + هزینه صحافی',
            ],
            [
                'id' => 'srv-student-register', 'category_id' => 'cat-student', 'title' => 'ثبت‌نام دانشگاه',
                'description' => 'ثبت‌نام ترمی، انتخاب واحد و رفع اشکال سامانه دانشگاه',
                'base_price' => 80000, 'estimated_time' => '۲ الی ۴ ساعت', 'icon' => 'student',
                'icon_color' => '#475d93', 'sort_order' => 1,
                'is_featured' => true, 'featured_sort' => 2,
                'fields_json' => json_encode([
                    ['id' => 'university', 'label' => 'نام دانشگاه', 'type' => 'text', 'placeholder' => 'نام دانشگاه خود را بنویسید', 'required' => true],
                    ['id' => 'serviceLevel', 'label' => 'سطح خدمات', 'type' => 'select', 'required' => true, 'options' => [
                        ['label' => 'فقط ثبت‌نام', 'value' => 'basic'],
                        ['label' => 'ثبت‌نام + انتخاب واحد (۲۰٬۰۰۰ اضافه)', 'value' => 'full', 'extraPrice' => 20000],
                    ]],
                ], JSON_UNESCAPED_UNICODE),
                'pricing_formula' => 'قیمت پایه + گزینه‌های انتخابی',
            ],
            [
                'id' => 'srv-gov-followup', 'category_id' => 'cat-gov', 'title' => 'پیگیری امور اداری',
                'description' => 'پیگیری نامه‌ها، مجوزها و امور دولتی',
                'base_price' => 120000, 'estimated_time' => '۱ الی ۲ روز کاری', 'icon' => 'office',
                'icon_color' => '#a82cc7', 'sort_order' => 1,
                'fields_json' => json_encode([
                    ['id' => 'subject', 'label' => 'موضوع پیگیری', 'type' => 'text', 'placeholder' => 'موضوع را شرح دهید', 'required' => true],
                ], JSON_UNESCAPED_UNICODE),
                'pricing_formula' => 'قیمت پایه',
            ],
            [
                'id' => 'srv-tax-loan', 'category_id' => 'cat-tax', 'title' => 'مشاوره مالیات و وام',
                'description' => 'تنظیم اظهارنامه، تسویه مالیات و مشاوره وام',
                'base_price' => 150000, 'estimated_time' => '۱ الی ۳ روز کاری', 'icon' => 'government',
                'icon_color' => '#124be0', 'sort_order' => 1,
                'fields_json' => json_encode([
                    ['id' => 'taxType', 'label' => 'نوع خدمت', 'type' => 'radio', 'required' => true, 'options' => [
                        ['label' => 'اظهارنامه مالیاتی (۵۰٬۰۰۰ اضافه)', 'value' => 'declaration', 'extraPrice' => 50000],
                        ['label' => 'مشاوره وام', 'value' => 'loan'],
                    ]],
                ], JSON_UNESCAPED_UNICODE),
                'pricing_formula' => 'قیمت پایه + گزینه‌های انتخابی',
            ],
        ];
        foreach ($services as $s) {
            DB::table('services')->insert($s);
        }
    }
}
