<?php

declare(strict_types=1);

namespace Simplify\Subdomains;

use App\Models\Allocation;
use App\Models\Server;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Simplify\Subdomains\Models\Subdomain;
use Simplify\Subdomains\Services\CloudflareDns;
use Simplify\Subdomains\Services\Games;
use Simplify\Subdomains\Services\Names;
use Simplify\Subdomains\Services\Settings;
use Throwable;

/**
 * Automatic subdomains: a server named "oak" gets oak.<domain>, a CNAME to the game address (plus an SRV record for
 * Minecraft Java). Cloudflare is called after the response is sent; the sync command retries every five minutes.
 *
 * This class is also what other plugins use, for example Server Maker to suggest a name for a new server:
 *
 *     if (class_exists(\Simplify\Subdomains\Subdomains::class) && \Simplify\Subdomains\Subdomains::ready()) { ... }
 *
 * Public methods here only change with a new major version.
 */
final class Subdomains
{
    /** @var (Closure(string, string, ?array<string, mixed>): array<string, mixed>)|null used by tests instead of Cloudflare */
    public static ?Closure $transport = null;

    /** True when a root admin has filled in everything, so servers can get a subdomain. */
    public static function ready(): bool
    {
        return Settings::ready();
    }

    /** The domain the subdomains are made in, such as example.com. */
    public static function domain(): string
    {
        return Settings::domain();
    }

    /** null when $name may be used (by the server with $uuid), otherwise the reason. */
    public static function check(string $name, ?string $uuid = null): ?string
    {
        return self::names()->check(strtolower(trim($name)), $uuid);
    }

    /** A free name from a server name, such as "oak-survival" for "Oak Survival". Empty when none is free. */
    public static function suggest(string $serverName, ?string $uuid = null): string
    {
        return self::names()->suggest($serverName, $uuid);
    }

    /** The first free allocation on a node whose port is forwarded, so a new server can get its subdomain. */
    public static function allocationFor(int $nodeId): ?int
    {
        $settings = Settings::all();

        return Allocation::query()
            ->where('node_id', $nodeId)
            ->whereNull('server_id')
            ->whereBetween('port', [$settings['port_min'], $settings['port_max']])
            ->orderBy('port')
            ->value('id');
    }

    /** The join address when the subdomain works: oak.example.com for Minecraft Java, oak.example.com:2456 otherwise. */
    public static function address(Server $server): ?string
    {
        if (! self::ready()) {
            return null;
        }
        $row = Subdomain::of($server->uuid);

        return $row?->status === Subdomain::ACTIVE ? self::join($server, $row->name) : null;
    }

    /** The join address for a server's active subdomain name, null without a name. */
    public static function join(Server $server, ?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        $host = $name.'.'.Settings::domain();

        return self::minecraft($server) ? $host : $host.':'.(int) $server->allocation?->port;
    }

    /** @return array{name: string, status: string, message: string} what the server page shows */
    public static function state(Server $server): array
    {
        $row = Subdomain::of($server->uuid);

        return [
            'name' => (string) $row?->name,
            'status' => (string) $row?->status,
            'message' => (string) $row?->message,
        ];
    }

    /**
     * Saves a server's name and connects it after the response. An empty name removes the subdomain.
     *
     * @throws ValidationException with the reason under "name" when the name may not be used
     */
    public static function request(Server $server, string $name): void
    {
        $name = strtolower(trim($name));
        $row = Subdomain::of($server->uuid);
        if ($name === '' && $row === null) {
            return;
        }
        if ($error = self::check($name, $server->uuid)) {
            throw ValidationException::withMessages(['name' => $error]);
        }
        try {
            // The unique index on name settles two servers asking for the same name at once.
            Subdomain::query()->updateOrCreate(
                ['server_uuid' => $server->uuid],
                ['name' => $name === '' ? null : $name, 'status' => $name === '' ? Subdomain::REMOVING : Subdomain::PENDING, 'message' => ''],
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('simplify-subdomains::subdomains.errors.taken')]);
        }
        $uuid = $server->uuid;
        self::afterResponse(function () use ($uuid) {
            $server = Server::query()->with(['allocation', 'egg'])->where('uuid', $uuid)->first();
            $server ? self::sync($server) : self::forget($uuid);
        });
    }

    /** Brings Cloudflare in line with the saved name and port. Returns the new status, or "removed". */
    public static function sync(Server $server): string
    {
        $row = Subdomain::of($server->uuid);
        if ($row === null) {
            return 'removed';
        }
        if (! self::ready()) {
            return self::mark($row, Subdomain::ERROR, __('simplify-subdomains::subdomains.errors.not_set_up'));
        }
        $name = (string) $row->name;
        $port = (int) $server->allocation?->port;
        $dns = self::dns();
        if ($name !== '' && ! Settings::forwarded($port)) {
            $dns->remove($server->uuid);
            $settings = Settings::all();

            return self::mark($row, Subdomain::ERROR, __('simplify-subdomains::subdomains.errors.not_forwarded', [
                'port' => $port,
                'min' => $settings['port_min'],
                'max' => $settings['port_max'],
            ]));
        }
        $error = $dns->sync($server->uuid, $name, $port, self::minecraft($server));
        if ($name === '' && $error === null) {
            $row->delete();

            return 'removed';
        }

        return self::mark($row, $error === null ? Subdomain::ACTIVE : Subdomain::ERROR, (string) $error, $port);
    }

    /** Removes a deleted server's records. Kept as "removing" for the sync command when Cloudflare fails. */
    public static function forget(string $uuid): void
    {
        $row = Subdomain::of($uuid);
        if ($row === null) {
            return;
        }
        if (self::ready() && self::dns()->remove($uuid) === null) {
            $row->delete();

            return;
        }
        $row->update(['name' => null, 'status' => Subdomain::REMOVING, 'message' => '']);
    }

    /** Retries pending and failed subdomains, follows port changes and removes records of deleted servers. */
    public static function tick(): void
    {
        if (! self::ready()) {
            return;
        }
        $rows = Subdomain::query()->get()->keyBy('server_uuid');
        if ($rows->isEmpty()) {
            return;
        }
        $servers = Server::query()->with(['allocation', 'egg'])->whereIn('uuid', $rows->keys())->get()->keyBy('uuid');
        foreach ($rows as $uuid => $row) {
            $server = $servers->get($uuid);
            if ($server === null) {
                self::forget((string) $uuid);
            } elseif ($row->status !== Subdomain::ACTIVE || $row->port !== (int) $server->allocation?->port) {
                self::sync($server);
            }
        }
    }

    public static function minecraft(Server $server): bool
    {
        return Games::minecraftJava((string) $server->egg?->name, (array) ($server->egg?->features ?? []));
    }

    private static function names(): Names
    {
        $settings = Settings::all();

        return new Names($settings['domain'], $settings['target'], fn (string $name, ?string $uuid) => Subdomain::query()
            ->where('name', $name)
            ->when($uuid !== null, fn ($query) => $query->where('server_uuid', '!=', $uuid))
            ->exists());
    }

    private static function dns(): CloudflareDns
    {
        [$token, $zone] = Settings::credentials();
        $settings = Settings::all();

        return new CloudflareDns(self::$transport ?? CloudflareDns::http($token), $zone, $settings['domain'], $settings['target']);
    }

    private static function mark(Subdomain $row, string $status, string $message, ?int $port = null): string
    {
        $row->update(['status' => $status, 'message' => mb_substr($message, 0, 500), 'port' => $port ?? $row->port]);

        return $status;
    }

    private static function afterResponse(Closure $work): void
    {
        $run = static function () use ($work): void {
            try {
                $work();
            } catch (Throwable $error) {
                report($error);
            }
        };
        app()->runningInConsole() ? $run() : app()->terminating($run);
    }
}
