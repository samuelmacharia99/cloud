<?php

namespace App\Support;

class Countries
{
    /** @var array<string, string>|null */
    private static ?array $byName = null;

    /**
     * @return array<string, string> ISO 3166-1 alpha-2 code => English name
     */
    public static function all(): array
    {
        return config('countries', []);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function name(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        return self::all()[strtoupper(trim($code))] ?? null;
    }

    public static function display(?string $code): string
    {
        return self::name($code) ?? (filled($code) ? (string) $code : '-');
    }

    public static function isValidCode(?string $code): bool
    {
        if (blank($code) || strlen(trim($code)) !== 2) {
            return false;
        }

        return isset(self::all()[strtoupper(trim($code))]);
    }

    /**
     * Convert legacy free-text country values (e.g. "Kenya") to ISO codes.
     */
    public static function normalize(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $trimmed = trim($value);

        if (strlen($trimmed) === 2 && self::isValidCode($trimmed)) {
            return strtoupper($trimmed);
        }

        $upper = strtoupper($trimmed);

        if (isset(self::all()[$upper])) {
            return $upper;
        }

        foreach (self::nameIndex() as $nameUpper => $code) {
            if ($nameUpper === $upper) {
                return $code;
            }
        }

        $aliases = [
            'COTE DIVOIRE' => 'CI',
            'CÔTE D\'IVOIRE' => 'CI',
            'IVORY COAST' => 'CI',
            'USA' => 'US',
            'UNITED STATES OF AMERICA' => 'US',
            'UK' => 'GB',
            'GREAT BRITAIN' => 'GB',
            'UAE' => 'AE',
            'SOUTH KOREA' => 'KR',
            'NORTH KOREA' => 'KP',
            'RUSSIA' => 'RU',
            'TURKEY' => 'TR',
            'TANZANIA, UNITED REPUBLIC OF' => 'TZ',
            'TANZANIA' => 'TZ',
            'UGANDA' => 'UG',
            'RWANDA' => 'RW',
            'BURUNDI' => 'BI',
            'SOUTH SUDAN' => 'SS',
            'SOMALIA' => 'SO',
            'DJIBOUTI' => 'DJ',
            'ERITREA' => 'ER',
            'DRC' => 'CD',
            'CONGO' => 'CD',
            'MADAGASCAR' => 'MG',
            'MALAWI' => 'MW',
            'MAURITIUS' => 'MU',
            'SEYCHELLES' => 'SC',
        ];

        if (isset($aliases[$upper])) {
            return $aliases[$upper];
        }

        return null;
    }

    /**
     * @param  list<string>  $priorityCodes  Shown first (e.g. KE for Kenya-based platform)
     * @return array<string, string>
     */
    public static function optionsForSelect(array $priorityCodes = ['KE']): array
    {
        $all = self::all();
        $options = [];

        foreach ($priorityCodes as $code) {
            $code = strtoupper($code);
            if (isset($all[$code])) {
                $options[$code] = $all[$code];
                unset($all[$code]);
            }
        }

        asort($all);

        return $options + $all;
    }

    /**
     * @return array<string, string> UPPER NAME => code
     */
    private static function nameIndex(): array
    {
        if (self::$byName !== null) {
            return self::$byName;
        }

        self::$byName = [];
        foreach (self::all() as $code => $name) {
            self::$byName[strtoupper($name)] = $code;
        }

        return self::$byName;
    }
}
