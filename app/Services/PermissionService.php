<?php

namespace App\Services;

/**
 * Central permission model — a faithful port of utils/permissions.ts.
 *
 * Same catalog, same implication rules, same role defaults so the server-side
 * enforcement matches the client-side matrix rendering one-to-one.
 *
 * Rules:
 *  - `<resource>.manage` implies every action on that resource.
 *  - Any action on a resource implies `<resource>.view`.
 *  - Admins always receive the full set and cannot be locked out.
 */
class PermissionService
{
    public const ACTIONS = ['view', 'create', 'edit', 'delete', 'permissions', 'manage'];

    public const ACTION_LABELS = [
        'view' => 'مشاهده',
        'create' => 'ایجاد',
        'edit' => 'ویرایش',
        'delete' => 'حذف',
        'permissions' => 'مجوزها',
        'manage' => 'مدیریت کامل',
    ];

    public const ROLE_LABELS = [
        'admin' => 'مدیر سیستم',
        'operator' => 'اپراتور',
        'customer' => 'مشتری',
    ];

    /** resource key → [title, category, description, [action, label, description]…] */
    public const RESOURCES = [
        'dashboard' => [
            'title' => 'داشبورد و گزارش‌ها', 'category' => 'عمومی',
            'description' => 'آمار فروش، درآمد و پرفروش‌ترین خدمات',
            'actions' => [
                ['view', 'مشاهده داشبورد', 'دسترسی به آمار فروش، درآمد و پرفروش‌ترین خدمات'],
            ],
        ],
        'orders' => [
            'title' => 'سفارش‌ها و صف کار', 'category' => 'عملیات',
            'description' => 'چرخه کامل سفارش‌ها از ثبت تا تحویل',
            'actions' => [
                ['view', 'مشاهده سفارش‌ها', 'مشاهده لیست و جزئیات سفارش‌ها و صف کار'],
                ['create', 'ایجاد سفارش', 'ثبت سفارش جدید به‌جای مشتری'],
                ['edit', 'ویرایش سفارش', 'تغییر وضعیت، ارجاع به اپراتور و بارگذاری فایل خروجی'],
                ['delete', 'حذف سفارش', 'حذف سفارش‌های ثبت‌شده'],
                ['manage', 'مدیریت کامل سفارش‌ها', 'دسترسی کامل به تمام عملیات سفارش‌ها'],
            ],
        ],
        'services' => [
            'title' => 'خدمات و فرم‌ساز', 'category' => 'محتوا',
            'description' => 'خدمات، دسته‌بندی‌ها و فیلدهای پویا',
            'actions' => [
                ['view', 'مشاهده خدمات', 'مشاهده لیست خدمات، دسته‌بندی‌ها و فرم‌ساز'],
                ['create', 'ایجاد خدمت', 'افزودن خدمت جدید همراه با فیلدهای پویا'],
                ['edit', 'ویرایش خدمت', 'ویرایش خدمات، دسته‌بندی‌ها و فیلدهای پویا'],
                ['delete', 'حذف خدمت', 'حذف خدمت یا دسته‌بندی'],
                ['manage', 'مدیریت کامل خدمات', 'دسترسی کامل به تمام عملیات خدمات'],
            ],
        ],
        'slider' => [
            'title' => 'اسلایدر صفحه اصلی', 'category' => 'محتوا',
            'description' => 'بنرهای صفحه ورود',
            'actions' => [
                ['view', 'مشاهده اسلایدر', 'مشاهده بنرهای صفحه ورود'],
                ['create', 'ایجاد اسلاید', 'بارگذاری بنر جدید صفحه ورود'],
                ['edit', 'ویرایش اسلاید', 'ویرایش، ترتیب‌بندی و فعال/غیرفعال‌سازی بنرها'],
                ['delete', 'حذف اسلاید', 'حذف بنر صفحه ورود'],
                ['manage', 'مدیریت کامل اسلایدر', 'دسترسی کامل به تمام عملیات اسلایدر'],
            ],
        ],
        'coupons' => [
            'title' => 'کدهای تخفیف', 'category' => 'مالی',
            'description' => 'ساخت و مدیریت کدهای تخفیف',
            'actions' => [
                ['view', 'مشاهده کدهای تخفیف', 'مشاهده لیست کدهای تخفیف'],
                ['create', 'ایجاد کد تخفیف', 'ساخت کد تخفیف جدید'],
                ['edit', 'ویرایش کد تخفیف', 'ویرایش و فعال/غیرفعال‌سازی کدها'],
                ['delete', 'حذف کد تخفیف', 'حذف کدهای تخفیف'],
                ['manage', 'مدیریت کامل کدهای تخفیف', 'دسترسی کامل به تمام عملیات کدهای تخفیف'],
            ],
        ],
        'users' => [
            'title' => 'کاربران و مجوزها', 'category' => 'مدیریت',
            'description' => 'کاربران، نقش‌ها و سطوح دسترسی',
            'actions' => [
                ['view', 'مشاهده کاربران', 'مشاهده لیست کاربران و نقش‌ها'],
                ['create', 'ایجاد کاربر', 'افزودن کاربر جدید'],
                ['edit', 'ویرایش کاربر', 'تغییر نقش، مسدودسازی و تنظیم کیف پول'],
                ['permissions', 'مدیریت مجوزها', 'تعیین و تغییر سطح دسترسی کاربران'],
                ['delete', 'حذف کاربر', 'حذف حساب کاربری'],
                ['manage', 'مدیریت کامل کاربران', 'دسترسی کامل به تمام عملیات کاربران'],
            ],
        ],
        'settings' => [
            'title' => 'تنظیمات سیستم', 'category' => 'مدیریت',
            'description' => 'مشخصات کسب‌وکار و تنظیمات پایه',
            'actions' => [
                ['view', 'مشاهده تنظیمات', 'مشاهده مشخصات کسب‌وکار و تنظیمات سیستم'],
                ['edit', 'ویرایش تنظیمات', 'تغییر مشخصات کسب‌وکار، مهلت نگهداری و حالت آزمایشی پیامک'],
            ],
        ],
        'sms' => [
            'title' => 'لاگ پیامک‌ها', 'category' => 'پشتیبانی',
            'description' => 'آرشیو پیامک‌های ارسالی',
            'actions' => [
                ['view', 'مشاهده لاگ پیامک‌ها', 'بررسی آرشیو پیامک‌های ارسالی و وضعیت تحویل'],
                ['delete', 'حذف لاگ پیامک', 'حذف تک‌تک لاگ‌های پیامک یا پاک‌کردن کل آرشیو'],
            ],
        ],
        'wallet' => [
            'title' => 'کیف پول و تراکنش‌ها', 'category' => 'مالی',
            'description' => 'گردش کیف پول، عودت وجه و تنظیم دستی موجودی',
            'actions' => [
                ['view', 'مشاهده تراکنش‌ها', 'مشاهده گردش کیف پول همه کاربران'],
                ['users', 'مشاهده کاربران تراکنش', 'مشاهده فهرست کاربران دارای تراکنش کیف پول'],
                ['refund', 'عودت وجه', 'واریز عودت وجه به کیف پول کاربر'],
                ['adjust', 'شارژ/کسر دستی کیف پول', 'افزودن یا کسر موجودی کیف پول توسط مدیریت'],
                ['manage', 'مدیریت کامل کیف پول', 'دسترسی کامل به تراکنش‌ها، عودت وجه و تنظیم موجودی'],
            ],
        ],
    ];

    /** Flat catalog of every permission with metadata (mirrors the client). */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::RESOURCES as $key => $res) {
            foreach ($res['actions'] as [$action, $label, $description]) {
                $out[] = [
                    'key' => $key.'.'.$action,
                    'label' => $label,
                    'description' => $description,
                    'group' => $res['title'],
                    'resource' => $key,
                    'action' => $action,
                ];
            }
        }

        return $out;
    }

    /** Grouped structure ready to render as the permission matrix. */
    public static function groups(): array
    {
        $catalog = self::catalog();
        $out = [];
        foreach (self::RESOURCES as $key => $res) {
            $out[] = [
                'key' => $key,
                'title' => $res['title'],
                'category' => $res['category'],
                'description' => $res['description'],
                'items' => array_values(array_filter(
                    $catalog,
                    fn ($p) => $p['resource'] === $key
                )),
            ];
        }

        return $out;
    }

    public static function allKeys(): array
    {
        return array_map(fn ($p) => $p['key'], self::catalog());
    }

    public static function roleDefaults(string $role): array
    {
        return match ($role) {
            'admin' => self::allKeys(),
            'operator' => ['dashboard.view', 'orders.manage', 'sms.view', 'wallet.view', 'wallet.users'],
            default => [],
        };
    }

    /**
     * Accepts either a JSON string (as stored) or an array; returns a clean
     * list or null when role defaults should apply.
     */
    public static function normalize(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $list = $raw;
        if (is_string($raw)) {
            $decoded = json_decode(trim($raw), true);
            if (! is_array($decoded)) {
                return null;
            }
            $list = $decoded;
        }
        if (! is_array($list)) {
            return null;
        }
        $valid = array_flip(self::allKeys());
        $out = [];
        foreach ($list as $item) {
            $key = (string) $item;
            if (isset($valid[$key])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** Effective permissions for a user (admin never lockable). */
    public static function resolve(string $role, mixed $rawOverride): array
    {
        if ($role === 'admin') {
            return self::allKeys();
        }
        $custom = self::normalize($rawOverride);
        if ($custom === null) {
            return self::roleDefaults($role);
        }

        return array_values(array_unique($custom));
    }

    /**
     * Whether a granted set satisfies a required permission, honouring the
     * action hierarchy (exact → manage umbrella → implicit view).
     */
    public static function satisfies(?array $granted, string $required): bool
    {
        if (! $granted || count($granted) === 0) {
            return false;
        }
        $set = array_flip($granted);
        if (isset($set[$required])) {
            return true;
        }
        [$resource, $action] = explode('.', $required, 2);
        if (isset($set[$resource.'.manage'])) {
            return true;
        }
        if ($action === 'view' && isset(self::RESOURCES[$resource])) {
            foreach (self::RESOURCES[$resource]['actions'] as [$candidateAction]) {
                if ($candidateAction !== 'view' && isset($set[$resource.'.'.$candidateAction])) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function has(?array $granted, string $permission): bool
    {
        return self::satisfies($granted, $permission);
    }

    public static function hasAny(?array $granted, array $required): bool
    {
        foreach ($required as $permission) {
            if (self::satisfies($granted, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** Expand a granted set into the concrete list it unlocks. */
    public static function expand(?array $granted): array
    {
        return array_values(array_filter(
            array_map(fn ($p) => $p['key'], self::catalog()),
            fn ($key) => self::satisfies($granted, $key)
        ));
    }
}
