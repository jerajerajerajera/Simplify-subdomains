<?php

// The parts that need no panel: Cloudflare records with a fake API, subdomain names and Minecraft detection.
declare(strict_types=1);

namespace Illuminate\Support {
    final class Str
    {
        public static function ascii(string $value): string
        {
            return (string) iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        }
    }
}

namespace {
    require __DIR__.'/../src/Services/Settings.php';
    require __DIR__.'/../src/Services/CloudflareDns.php';
    require __DIR__.'/../src/Services/Names.php';
    require __DIR__.'/../src/Services/Games.php';

    use Simplify\Subdomains\Services\CloudflareDns;
    use Simplify\Subdomains\Services\Games;
    use Simplify\Subdomains\Services\Names;

    /** Returns the translation key with its placeholders, so tests can see which message was chosen. */
    function __(string $key, array $replace = []): string
    {
        return $key.($replace ? ' '.json_encode($replace) : '');
    }

    function report(Throwable $error): void {}

    $failed = false;
    function check(bool $ok, string $what): void
    {
        global $failed;
        echo ($ok ? 'PASS ' : 'FAIL ').$what."\n";
        $failed = $failed || ! $ok;
    }

    /** Cloudflare's DNS API in memory, with the filters the client uses. */
    final class FakeCloudflare
    {
        /** @var array<string, array<string, mixed>> */
        public array $records = [];

        /** @var list<string> */
        public array $calls = [];

        public bool $down = false;

        private int $next = 0;

        public function __invoke(string $method, string $path, ?array $body): array
        {
            $this->calls[] = $method;
            if ($this->down) {
                return ['success' => false, 'errors' => [['message' => 'unavailable']]];
            }
            if ($method === 'GET') {
                parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
                if (str_starts_with($path, '/zones?')) {
                    return ['success' => true, 'result' => $query['name'] === 'example.com' ? [['id' => str_repeat('a', 32)]] : []];
                }

                return ['success' => true, 'result' => array_values(array_filter($this->records, fn ($record) => (! isset($query['comment_exact']) || ($record['comment'] ?? '') === $query['comment_exact'])
                    && (! isset($query['name_exact']) || $record['name'] === $query['name_exact'])))];
            }
            $id = basename($path);
            match ($method) {
                'POST' => $this->records[$id = 'r'.(++$this->next)] = $body + ['id' => $id],
                'PUT' => $this->records[$id] = $body + ['id' => $id],
                'DELETE' => $this->records = array_diff_key($this->records, [$id => true]),
            };

            return ['success' => true];
        }

        /** @return list<string> */
        public function names(): array
        {
            $names = array_map(fn ($record) => $record['type'].' '.$record['name'], $this->records);
            sort($names);

            return array_values($names);
        }
    }

    $cloudflare = new FakeCloudflare;
    $send = Closure::fromCallable($cloudflare);
    $dns = new CloudflareDns($send, str_repeat('a', 32), 'example.com', 'play.example.com');
    $oak = 'aaaaaaaa-0000-0000-0000-000000000001';

    check($dns->sync($oak, 'oak', 25566, true) === null, 'a Minecraft server gets its records');
    check($cloudflare->names() === ['CNAME oak.example.com', 'SRV _minecraft._tcp.oak.example.com'], 'a CNAME and an SRV record');
    $srv = array_values(array_filter($cloudflare->records, fn ($record) => $record['type'] === 'SRV'))[0];
    check($srv['data']['port'] === 25566 && $srv['comment'] === 'pelican:'.$oak, 'the SRV record has the port and the server tag');
    check(array_values(array_filter($cloudflare->records, fn ($r) => $r['type'] === 'CNAME'))[0]['proxied'] === false, 'records are DNS only');

    $cloudflare->calls = [];
    $dns->sync($oak, 'oak', 25566, true);
    check(! array_intersect(['POST', 'PUT', 'DELETE'], $cloudflare->calls), 'nothing changes when the records are right');

    $dns->sync($oak, 'oak', 25570, true);
    $srv = array_values(array_filter($cloudflare->records, fn ($record) => $record['type'] === 'SRV'))[0];
    check($srv['data']['port'] === 25570 && count($cloudflare->records) === 2, 'a new port updates the SRV record');

    $dns->sync($oak, 'birch', 25570, false);
    check($cloudflare->names() === ['CNAME birch.example.com'], 'a new name and another game replace the records');

    $cloudflare->records['manual'] = ['id' => 'manual', 'type' => 'CNAME', 'name' => 'elm.example.com', 'content' => 'elsewhere.example.com', 'comment' => ''];
    $message = (string) $dns->sync('bbbbbbbb-0000-0000-0000-000000000002', 'elm', 25571, false);
    check(str_contains($message, 'errors.record_exists') && isset($cloudflare->records['manual']), 'a record made by hand is never taken over');

    check($dns->remove($oak) === null && $cloudflare->names() === ['CNAME elm.example.com'], 'removing leaves only the record made by hand');

    $cloudflare->down = true;
    check(str_contains((string) $dns->sync($oak, 'oak', 25566, true), 'errors.unreachable'), 'Cloudflare being down gives a message');
    check(! CloudflareDns::findZone($send, 'example.com')['ok'], 'a refused token is reported');
    $cloudflare->down = false;

    $zone = CloudflareDns::findZone($send, 'play.example.com');
    check($zone['ok'] && $zone['zone_id'] === str_repeat('a', 32), 'the zone is found from a deeper domain');
    check(! CloudflareDns::findZone($send, 'other.org')['ok'], 'a domain the token cannot see is reported');

    $changes = CloudflareDns::diff([], [['type' => 'CNAME', 'name' => 'x', 'id' => '1'], ['type' => 'CNAME', 'name' => 'x', 'id' => '2']]);
    check(count($changes) === 2 && $changes[0][0] === 'delete', 'duplicate tagged records are cleaned up');

    $used = ['oak' => 'cccccccc-0000-0000-0000-000000000003'];
    $names = new Names('example.com', 'play.example.com', fn (string $name, ?string $uuid) => isset($used[$name]) && $used[$name] !== $uuid);
    check($names->check('birch') === null && $names->check('') === null, 'a valid or empty name passes');
    check($names->check('Oak') !== null && $names->check('-oak') !== null && $names->check(str_repeat('a', 64)) !== null, 'badly formed names are refused');
    check(str_contains((string) $names->check('www'), 'reserved') && str_contains((string) $names->check('play'), 'reserved'), 'reserved names and the game address are refused');
    check(str_contains((string) $names->check('oak'), 'taken') && $names->check('oak', $used['oak']) === null, 'a name of another server is taken, its own is not');
    check($names->suggest('Oak') === 'oak-2' && $names->suggest('Oak', $used['oak']) === 'oak', 'a suggestion skips taken names');
    check($names->suggest('Café Ünïcode!') === 'cafe-unicode' && $names->suggest('!!!') === '', 'a suggestion is a clean name');

    check(Games::minecraftJava('Paper') && Games::minecraftJava('Some pack', ['eula']), 'Minecraft Java by name or EULA feature');
    check(! Games::minecraftJava('Minecraft Bedrock') && ! Games::minecraftJava('Valheim'), 'Bedrock and other games get no SRV record');

    exit($failed ? 1 : 0);
}
