<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Services;

use Closure;
use Illuminate\Support\Str;

/**
 * Which subdomain names a server may use. Kept free of the database so it can be tested on its own:
 * $taken(name, ?uuid) tells whether another server already uses a name.
 */
final class Names
{
    public const RESERVED = [
        'www', 'mail', 'smtp', 'imap', 'pop', 'ftp', 'ns1', 'ns2', 'mx', 'panel', 'node', 'api', 'admin', 'status',
        'autodiscover',
    ];

    private const FORMAT = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /** @param  Closure(string, ?string): bool  $taken */
    public function __construct(
        private readonly string $domain,
        private readonly string $target,
        private readonly Closure $taken,
    ) {}

    /** null when $name may be used by the server with $uuid, otherwise the reason. An empty name removes it. */
    public function check(string $name, ?string $uuid = null): ?string
    {
        if ($name === '') {
            return null;
        }
        if (strlen($name) > 63 || ! preg_match(self::FORMAT, $name)) {
            return __('simplify-subdomains::subdomains.errors.name_format');
        }
        if (in_array($name, self::RESERVED, true) || $name === $this->gameName()) {
            return __('simplify-subdomains::subdomains.errors.reserved');
        }
        if (($this->taken)($name, $uuid)) {
            return __('simplify-subdomains::subdomains.errors.taken');
        }

        return null;
    }

    /** A free name from a server name: "Oak Survival" becomes "oak-survival", or "oak-survival-2" when taken. */
    public function suggest(string $serverName, ?string $uuid = null): string
    {
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower(Str::ascii($serverName)));
        $base = trim(substr(trim($slug, '-'), 0, 57), '-');
        if ($base === '') {
            return '';
        }
        for ($i = 1; $i < 20; $i++) {
            $name = $i === 1 ? $base : $base.'-'.$i;
            if ($this->check($name, $uuid) === null) {
                return $name;
            }
        }

        return '';
    }

    /** "play" when the game address is play.<domain>: that name is already in use. */
    private function gameName(): string
    {
        $suffix = '.'.$this->domain;

        return str_ends_with($this->target, $suffix) ? substr($this->target, 0, -strlen($suffix)) : '';
    }
}
