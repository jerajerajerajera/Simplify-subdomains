<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Addon;

use App\Models\Server;
use Simplify\Core\Addons\Addon;
use Simplify\Subdomains\Filament\Admin\Pages\AutomaticSubdomains;
use Simplify\Subdomains\SimplifySubdomainsPlugin;
use Simplify\Subdomains\Subdomains;

/** How Subdomains shows up inside Simplify. Only loaded when Simplify is installed. */
final class SubdomainsAddon extends Addon
{
    public function id(): string
    {
        return SimplifySubdomainsPlugin::ID;
    }

    public function name(): string
    {
        return 'Subdomains';
    }

    public function description(): string
    {
        return __('simplify-subdomains::subdomains.addon.description');
    }

    public function icon(): string
    {
        return 'tabler-world';
    }

    public function settingsUrl(): ?string
    {
        return user()?->isRootAdmin() ? AutomaticSubdomains::getUrl(panel: 'admin') : null;
    }

    public function tileBadges(Server $server): array
    {
        $address = Subdomains::address($server);

        return $address === null ? [] : [$address];
    }
}
