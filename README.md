# Simplify Subdomains

A [Pelican](https://pelican.dev) plugin that gives each server its own address, such as `oak.example.com`. The DNS
records are made in Cloudflare and removed with the server.

- A CNAME record to your game address, and for Minecraft Java an SRV record with the port, so players can leave the
  port out.
- Records are DNS only (not proxied), because Cloudflare's proxy does not carry game traffic.
- Records are tagged with the server. Records you made by hand are never changed or removed.
- When Cloudflare cannot be reached, the plugin tries again every five minutes. It also follows port changes.

It works on its own. With [Simplify](https://github.com/jerajerajerajera/Simplify) installed, the address also shows
on the server's tile and the add-on appears in Simplify's add-on list.

## Set up

1. Install the plugin from the [Pelican Hub](https://hub.pelican.dev). Updates come from the Hub only.
2. In Cloudflare, create an API token with only **Zone → DNS → Edit** for your domain.
3. In the admin area, open **Simplify → Automatic subdomains**. Fill in the domain, the game address (an existing
   DNS-only record that points to your public IP) and the forwarded port range. Paste the token and press **Test**,
   then save.
4. Server owners now find **Subdomain** in their server's menu. Only servers with a port in the forwarded range get
   one.

Pelican's scheduler must run (`php artisan schedule:run` every minute), as in Pelican's installation guide.

## What it sends where

Only the Cloudflare API (`api.cloudflare.com`), with your token: to find the zone of your domain and to read, create,
change and delete the records of your servers. Nothing else leaves your panel. The token is stored encrypted and is
never shown again.

## Coming from PelicanSimplify

The settings and server addresses of the subdomains built into PelicanSimplify 0.6 and 0.7 are taken over when the
plugin is installed, so nothing has to be set up again. The old files are left as they are. A token that was only in
`.env` (`CLOUDFLARE_API_TOKEN`) has to be pasted once on the settings page.

Turn off the subdomains in PelicanSimplify (or remove PelicanSimplify) once this plugin is set up, so only one
plugin looks after the records.

## For other plugins

`Simplify\Subdomains\Subdomains` has a few public static methods, for example for a plugin that creates servers:

| Method | What it does |
| --- | --- |
| `ready(): bool` | everything is filled in, so servers can get a subdomain |
| `domain(): string` | the domain, such as `example.com` |
| `suggest(string $serverName, ?string $uuid = null): string` | a free name, such as `oak-survival` |
| `check(string $name, ?string $uuid = null): ?string` | `null` when the name may be used, otherwise the reason |
| `allocationFor(int $nodeId): ?int` | a free allocation with a forwarded port |
| `request(Server $server, string $name): void` | saves the name and connects it after the response |
| `address(Server $server): ?string` | the join address when the subdomain works |

Check `class_exists(\Simplify\Subdomains\Subdomains::class)` first, because the plugin may not be installed.

## Development

- `bash tests/run.sh` runs the checks that need no panel, including `tests/hub-check.py`.
- `pint` formats the code (Laravel preset).
- `python3 scripts/build-package.py` builds the zip for the Hub.

## License

Source available, see [LICENSE.md](LICENSE.md). Copyright (c) 2026 Jeroen (jerajerajerajera).
