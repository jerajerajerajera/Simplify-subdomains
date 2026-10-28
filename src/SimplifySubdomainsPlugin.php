<?php

declare(strict_types=1);

namespace Simplify\Subdomains;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Simplify\Subdomains\Filament\Admin\Pages\AutomaticSubdomains;
use Simplify\Subdomains\Filament\Server\Pages\ServerSubdomain;

final class SimplifySubdomainsPlugin implements Plugin
{
    public const ID = 'simplify-subdomains';

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        match ($panel->getId()) {
            'admin' => $panel->pages([AutomaticSubdomains::class]),
            'server' => $panel->pages([ServerSubdomain::class]),
            default => null,
        };
    }

    public function boot(Panel $panel): void {}
}
