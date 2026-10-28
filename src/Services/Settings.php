<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a root admin fills in on the Automatic subdomains page. The Cloudflare token is stored encrypted and
 * never sent back to the browser.
 */
final class Settings
{
    public const TABLE = 'simplify_subdomain_settings';

    public const HOST = '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i';

    public const ZONE = '/^[a-f0-9]{32}$/';

    private const DEFAULTS = [
        'domain' => '',
        'target' => '',
        'port_min' => 25565,
        'port_max' => 25600,
        'zone_id' => '',
    ];

    /** @var array<string, string>|null */
    private static ?array $rows = null;

    /** @return array{domain: string, target: string, port_min: int, port_max: int, zone_id: string, has_token: bool} */
    public static function all(): array
    {
        $rows = self::rows();

        return [
            'domain' => $rows['domain'] ?? self::DEFAULTS['domain'],
            'target' => $rows['target'] ?? self::DEFAULTS['target'],
            'port_min' => (int) ($rows['port_min'] ?? self::DEFAULTS['port_min']),
            'port_max' => (int) ($rows['port_max'] ?? self::DEFAULTS['port_max']),
            'zone_id' => $rows['zone_id'] ?? self::DEFAULTS['zone_id'],
            'has_token' => ($rows['token'] ?? '') !== '',
        ];
    }

    public static function domain(): string
    {
        return self::all()['domain'];
    }

    /**
     * Saves the addresses. A typed token replaces the saved one, an empty token keeps it and $removeToken deletes it.
     *
     * @param  array{domain?: ?string, target?: ?string, port_min: int|string, port_max: int|string, zone_id?: ?string, token?: ?string}  $input  already validated
     */
    public static function save(array $input, bool $removeToken = false): void
    {
        $values = [
            'domain' => strtolower(trim((string) ($input['domain'] ?? ''))),
            'target' => strtolower(trim((string) ($input['target'] ?? ''))),
            'port_min' => (string) (int) $input['port_min'],
            'port_max' => (string) (int) $input['port_max'],
            'zone_id' => strtolower(trim((string) ($input['zone_id'] ?? ''))),
        ];
        $token = trim((string) ($input['token'] ?? ''));
        if ($removeToken) {
            $values['token'] = '';
        } elseif ($token !== '') {
            $values['token'] = encrypt($token);
        }

        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                DB::table(self::TABLE)->updateOrInsert(['key' => $key], ['value' => $value]);
            }
        });
        self::$rows = null;
    }

    /** @return array{0: string, 1: string} the token and zone ID, or empty strings when no token is saved */
    public static function credentials(): array
    {
        $rows = self::rows();
        if (($rows['token'] ?? '') === '') {
            return ['', ''];
        }

        try {
            return [(string) decrypt($rows['token']), $rows['zone_id'] ?? ''];
        } catch (Throwable $error) {
            // Saved with another APP_KEY: the admin has to paste the token again.
            report($error);

            return ['', ''];
        }
    }

    /** @return list<string> what is still missing before subdomains work: domain, target, token and/or zone */
    public static function missing(): array
    {
        $settings = self::all();
        [$token, $zone] = self::credentials();

        return array_keys(array_filter([
            'domain' => $settings['domain'] === '',
            'target' => $settings['target'] === '',
            'token' => $token === '',
            'zone' => $zone === '',
        ]));
    }

    public static function ready(): bool
    {
        return self::missing() === [];
    }

    public static function forwarded(int $port): bool
    {
        $settings = self::all();

        return $port >= $settings['port_min'] && $port <= $settings['port_max'];
    }

    /** Forgets what was read, for example after another process saved. */
    public static function flush(): void
    {
        self::$rows = null;
    }

    /** @return array<string, string> */
    private static function rows(): array
    {
        return self::$rows ??= DB::table(self::TABLE)->pluck('value', 'key')->map(fn ($value) => (string) $value)->all();
    }
}
