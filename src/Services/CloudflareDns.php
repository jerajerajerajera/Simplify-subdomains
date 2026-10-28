<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Services;

use Closure;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Keeps one server's Cloudflare records in line with its subdomain: a CNAME to the game address and, for Minecraft
 * Java, an SRV record with the port. Records are tagged "pelican:<server uuid>". Records without that tag, made by
 * hand or by another tool, are never changed or removed.
 *
 * Every call goes through $send(method, path, ?body): array, the decoded Cloudflare response, so tests use a fake.
 */
final class CloudflareDns
{
    public const API = 'https://api.cloudflare.com/client/v4';

    /** @param  Closure(string, string, ?array<string, mixed>): array<string, mixed>  $send */
    public function __construct(
        private readonly Closure $send,
        private readonly string $zoneId,
        private readonly string $domain,
        private readonly string $target,
    ) {}

    /** @return Closure(string, string, ?array<string, mixed>): array<string, mixed> */
    public static function http(string $token): Closure
    {
        return static function (string $method, string $path, ?array $body) use ($token): array {
            $request = Http::withToken($token)->acceptJson()->timeout(10)->baseUrl(self::API);
            $response = $method === 'GET'
                ? $request->get($path)
                : $request->send($method, $path, $body === null ? [] : ['json' => $body]);

            return (array) ($response->json() ?? [
                'success' => false,
                'errors' => [['message' => 'HTTP '.$response->status()]],
            ]);
        };
    }

    public static function comment(string $uuid): string
    {
        return 'pelican:'.$uuid;
    }

    /**
     * Finds the zone of a domain with a token: example.com also for play.example.com.
     *
     * @param  Closure(string, string, ?array<string, mixed>): array<string, mixed>  $send
     * @return array{ok: bool, message: string, zone_id: ?string}
     */
    public static function findZone(Closure $send, string $domain): array
    {
        $labels = explode('.', $domain);
        try {
            for ($i = 0; $i < count($labels) - 1; $i++) {
                $name = implode('.', array_slice($labels, $i));
                $result = $send('GET', '/zones?name='.rawurlencode($name), null);
                if (! ($result['success'] ?? false)) {
                    return self::result(false, __('simplify-subdomains::subdomains.test.refused', ['error' => self::error($result)]));
                }
                $zone = $result['result'][0]['id'] ?? null;
                if (is_string($zone) && preg_match(Settings::ZONE, $zone)) {
                    return self::result(true, __('simplify-subdomains::subdomains.test.works', ['zone' => $name]), $zone);
                }
            }
        } catch (Throwable $error) {
            report($error);

            return self::result(false, __('simplify-subdomains::subdomains.test.unreachable'));
        }

        return self::result(false, __('simplify-subdomains::subdomains.test.cannot_see', ['domain' => $domain]));
    }

    /**
     * The records a server should have; none without a name.
     *
     * @return list<array<string, mixed>>
     */
    public function wanted(string $uuid, string $name, int $port, bool $minecraft): array
    {
        if ($name === '') {
            return [];
        }
        $host = $name.'.'.$this->domain;
        $records = [[
            'type' => 'CNAME',
            'name' => $host,
            'content' => $this->target,
            'proxied' => false,
            'ttl' => 1,
            'comment' => self::comment($uuid),
        ]];
        if ($minecraft) {
            // Java clients look up this record, so players can leave the port out.
            $records[] = [
                'type' => 'SRV',
                'name' => '_minecraft._tcp.'.$host,
                'data' => ['priority' => 0, 'weight' => 5, 'port' => $port, 'target' => $this->target],
                'ttl' => 1,
                'comment' => self::comment($uuid),
            ];
        }

        return $records;
    }

    /**
     * The changes that turn $owned (the records with this server's tag) into $wanted.
     *
     * @param  list<array<string, mixed>>  $wanted
     * @param  list<array<string, mixed>>  $owned
     * @return list<array{0: 'create'|'update'|'delete', 1: array<string, mixed>}>
     */
    public static function diff(array $wanted, array $owned): array
    {
        $changes = [];
        $byKey = [];
        foreach ($owned as $record) {
            $byKey[$record['type'].' '.$record['name']][] = $record;
        }
        foreach ($wanted as $record) {
            $key = $record['type'].' '.$record['name'];
            $have = isset($byKey[$key]) ? array_shift($byKey[$key]) : null;
            if ($have === null) {
                $changes[] = ['create', $record];
            } elseif (! self::same($record, $have)) {
                $changes[] = ['update', $record + ['id' => $have['id']]];
            }
        }
        foreach ($byKey as $left) {
            foreach ($left as $record) {
                $changes[] = ['delete', $record];
            }
        }

        return $changes;
    }

    /** Returns null when the records are right, or a message for the server owner. */
    public function sync(string $uuid, string $name, int $port, bool $minecraft): ?string
    {
        try {
            $wanted = $this->wanted($uuid, $name, $port, $minecraft);
            foreach ($wanted as $record) {
                foreach ($this->find(['name.exact' => $record['name']]) as $other) {
                    if (($other['comment'] ?? '') !== self::comment($uuid)) {
                        return __('simplify-subdomains::subdomains.errors.record_exists', ['name' => $record['name']]);
                    }
                }
            }
            $base = '/zones/'.rawurlencode($this->zoneId).'/dns_records';
            foreach (self::diff($wanted, $this->find(['comment.exact' => self::comment($uuid)])) as [$change, $record]) {
                $body = array_diff_key($record, ['id' => true]);
                $result = match ($change) {
                    'create' => ($this->send)('POST', $base, $body),
                    'update' => ($this->send)('PUT', $base.'/'.rawurlencode((string) $record['id']), $body),
                    'delete' => ($this->send)('DELETE', $base.'/'.rawurlencode((string) $record['id']), null),
                };
                if (! ($result['success'] ?? false)) {
                    return __('simplify-subdomains::subdomains.errors.refused', ['name' => $record['name'], 'error' => self::error($result)]);
                }
            }

            return null;
        } catch (Throwable $error) {
            return __('simplify-subdomains::subdomains.errors.unreachable', ['error' => $error->getMessage()]);
        }
    }

    /** Removes every record with this server's tag. Returns null when done. */
    public function remove(string $uuid): ?string
    {
        return $this->sync($uuid, '', 0, false);
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function find(array $query): array
    {
        $path = '/zones/'.rawurlencode($this->zoneId).'/dns_records?'.http_build_query($query + ['per_page' => 100]);
        $result = ($this->send)('GET', $path, null);
        if (! ($result['success'] ?? false)) {
            throw new RuntimeException(self::error($result));
        }

        return array_values(array_filter((array) ($result['result'] ?? []), 'is_array'));
    }

    /** @param  array<string, mixed>  $result */
    private static function error(array $result): string
    {
        return (string) ($result['errors'][0]['message'] ?? 'unknown error');
    }

    /**
     * @param  array<string, mixed>  $wanted
     * @param  array<string, mixed>  $have
     */
    private static function same(array $wanted, array $have): bool
    {
        if ($wanted['type'] === 'SRV') {
            return array_intersect_key((array) ($have['data'] ?? []), $wanted['data']) == $wanted['data'];
        }

        return strcasecmp((string) ($have['content'] ?? ''), $wanted['content']) === 0
            && ($have['proxied'] ?? false) === false;
    }

    /** @return array{ok: bool, message: string, zone_id: ?string} */
    private static function result(bool $ok, string $message, ?string $zone = null): array
    {
        return ['ok' => $ok, 'message' => $message, 'zone_id' => $zone];
    }
}
