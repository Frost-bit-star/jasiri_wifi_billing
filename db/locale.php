<?php
// db/locale.php - Shared currency & timezone configuration for the whole app

require_once __DIR__ . '/../config.php';

$defaultTimezone = 'Africa/Dar_es_Salaam';

$currencyOptions = [
    'TZS' => 'TZS - Tanzanian Shilling (TSh)',
    'KES' => 'KES - Kenyan Shilling (KES)',
    'UGX' => 'UGX - Ugandan Shilling (UGX)',
    'RWF' => 'RWF - Rwandan Franc (RWF)',
    'NGN' => 'NGN - Nigerian Naira (NGN)',
    'USD' => 'USD - US Dollar ($)',
    'ZMW' => 'ZMW - Zambian Kwacha (K)',
    'GHS' => 'GHS - Ghanaian Cedi (GH₵)',
    'XOF' => 'XOF - West African Franc (FCFA)',
    'MWK' => 'MWK - Malawian Kwacha (MK)',
];

$appCurrencyMap = [
    'TZS' => 'TSh',
    'KES' => 'KES',
    'USD' => '$',
    'UGX' => 'UGX',
    'RWF' => 'RWF',
    'NGN' => 'NGN',
    'ZMW' => 'K',
    'GHS' => 'GH₵',
    'XOF' => 'FCFA',
    'MWK' => 'MK',
];

$phoneCodeOptions = [
    '+255' => '🇹🇿 +255 — Tanzania',
    '+254' => '🇰🇪 +254 — Kenya',
    '+256' => '🇺🇬 +256 — Uganda',
    '+250' => '🇷🇼 +250 — Rwanda',
    '+234' => '🇳🇬 +234 — Nigeria',
    '+260' => '🇿🇲 +260 — Zambia',
    '+233' => '🇬🇭 +233 — Ghana',
    '+265' => '🇲🇼 +265 — Malawi',
    '+1'   => '🇺🇸 +1 — USA/Canada',
    '+44'  => '🇬🇧 +44 — UK',
    '+91'  => '🇮🇳 +91 — India',
];

// Timezones relevant to each currency's region.
$currencyTimezones = [
    'TZS' => ['Africa/Dar_es_Salaam'],
    'KES' => ['Africa/Nairobi'],
    'UGX' => ['Africa/Kampala'],
    'RWF' => ['Africa/Kigali'],
    'NGN' => ['Africa/Lagos'],
    'USD' => [
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'America/Anchorage',
        'Pacific/Honolulu',
        'Etc/UTC',
    ],
    'ZMW' => ['Africa/Lusaka'],
    'GHS' => ['Africa/Accra'],
    'XOF' => [
        'Africa/Abidjan',
        'Africa/Accra',
        'Africa/Bamako',
        'Africa/Conakry',
        'Africa/Dakar',
        'Africa/Lome',
        'Africa/Monrovia',
        'Africa/Nouakchott',
        'Africa/Ouagadougou',
        'Africa/Porto-Novo',
        'Africa/Sao_Tome',
    ],
    'MWK' => ['Africa/Blantyre'],
];

// Timezones offered when no currency filter applies.
$allTimezones = [
    'Africa/Abidjan',
    'Africa/Accra',
    'Africa/Addis_Ababa',
    'Africa/Blantyre',
    'Africa/Cairo',
    'Africa/Casablanca',
    'Africa/Dakar',
    'Africa/Dar_es_Salaam',
    'Africa/Djibouti',
    'Africa/Douala',
    'Africa/Harare',
    'Africa/Johannesburg',
    'Africa/Kampala',
    'Africa/Kigali',
    'Africa/Kinshasa',
    'Africa/Lagos',
    'Africa/Lome',
    'Africa/Luanda',
    'Africa/Lusaka',
    'Africa/Maputo',
    'Africa/Monrovia',
    'Africa/Nairobi',
    'Africa/Ndjamena',
    'Africa/Niamey',
    'Africa/Nouakchott',
    'Africa/Ouagadougou',
    'Africa/Porto-Novo',
    'Africa/Sao_Tome',
    'Africa/Tripoli',
    'America/Anchorage',
    'America/Bogota',
    'America/Chicago',
    'America/Denver',
    'America/Lima',
    'America/Los_Angeles',
    'America/Mexico_City',
    'America/New_York',
    'America/Toronto',
    'Asia/Baghdad',
    'Asia/Beirut',
    'Asia/Dubai',
    'Asia/Karachi',
    'Asia/Kolkata',
    'Asia/Riyadh',
    'Asia/Shanghai',
    'Asia/Singapore',
    'Asia/Tokyo',
    'Australia/Sydney',
    'Europe/Berlin',
    'Europe/London',
    'Europe/Madrid',
    'Europe/Moscow',
    'Europe/Paris',
    'Europe/Rome',
    'Pacific/Honolulu',
    'Etc/UTC',
];

function appTimezonesForCurrency(?string $currency): array {
    global $currencyTimezones;
    $currency = $currency ?: 'TZS';
    if (isset($currencyTimezones[$currency])) {
        return $currencyTimezones[$currency];
    }
    return $currencyTimezones['TZS'];
}

function appValidTimezone(?string $tz): string {
    global $defaultTimezone;
    $tz = $tz ?: $defaultTimezone;
    try {
        new DateTimeZone($tz);
        return $tz;
    } catch (Exception $e) {
        return $defaultTimezone;
    }
}

function appSetTimezone(?string $tz): void {
    date_default_timezone_set(appValidTimezone($tz));
}

function appTimezoneLabel(string $tz): string {
    try {
        $dtz = new DateTimeZone($tz);
        $now = new DateTimeImmutable('now', $dtz);
        $off = $dtz->getOffset($now);
        $sign = $off >= 0 ? '+' : '-';
        $off = abs($off);
        $h = str_pad((string)floor($off / 3600), 2, '0', STR_PAD_LEFT);
        $m = str_pad((string)floor(($off % 3600) / 60), 2, '0', STR_PAD_LEFT);
        $name = $dtz->getName();
        return "GMT{$sign}{$h}:{$m} &middot; {$name}";
    } catch (Exception $e) {
        return htmlspecialchars($tz);
    }
}
