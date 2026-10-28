<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Addon;

use App\Models\Server;
use Simplify\Core\Addons\Addon;
use Simplify\Subdomains\Filament\Admin\Pages\AutomaticSubdomains;
use Simplify\Subdomains\Models\Subdomain;
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

    /** @var array<string, ?string>|null active subdomain names by server uuid: one query for all tiles of a page */
    private ?array $active = null;

    public function tileBadges(Server $server): array
    {
        if (! Subdomains::ready()) {
            return [];
        }
        $this->active ??= Subdomain::query()->where('status', Subdomain::ACTIVE)->pluck('name', 'server_uuid')->all();
        $address = Subdomains::join($server, $this->active[$server->uuid] ?? null);

        return $address === null ? [] : [$address];
    }
}
