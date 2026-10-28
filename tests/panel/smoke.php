<?php

// Loads Simplify Subdomains inside a real Pelican panel, opens its pages and runs a subdomain through a fake
// Cloudflare. Run from the panel folder after `php artisan p:plugin:install simplify-subdomains`:
// php plugins/simplify-subdomains/tests/panel/smoke.php
use App\Models\Allocation;
use App\Models\Egg;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication;
use Illuminate\Foundation\Testing\Concerns\InteractsWithSession;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Simplify\Subdomains\Addon\SubdomainsAddon;
use Simplify\Subdomains\Filament\Admin\Pages\AutomaticSubdomains;
use Simplify\Subdomains\Filament\Server\Pages\ServerSubdomain;
use Simplify\Subdomains\Models\Subdomain;
use Simplify\Subdomains\Services\Settings;
use Simplify\Subdomains\Subdomains;

define('LARAVEL_START', microtime(true));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Laravel's console handler would print an uncaught error and still exit 0, which CI reads as a pass.
set_exception_handler(function (Throwable $e) {
    echo 'FAIL uncaught '.get_class($e).': '.substr($e->getMessage(), 0, 400).' ('.basename($e->getFile()).':'.$e->getLine().")\n";
    exit(1);
});

$failed = false;
function step(string $what, callable $run): void
{
    global $failed;
    try {
        $run();
        echo "PASS $what\n";
    } catch (Throwable $e) {
        $failed = true;
        echo "FAIL $what: ".get_class($e).': '.substr($e->getMessage(), 0, 400).' ('.basename($e->getFile()).':'.$e->getLine().")\n";
    }
}
function visit(User $user, string $url): TestResponse
{
    $client = new class(app())
    {
        use InteractsWithAuthentication, InteractsWithSession, MakesHttpRequests;

        public function __construct(public $app) {}
    };

    return $client->actingAs($user)->get($url);
}
function expectOk(TestResponse $response, string $url): void
{
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('HTTP '.$response->getStatusCode().' at '.$url.($response->exception ? ': '.$response->exception->getMessage() : ''));
    }
}
function must(bool $ok, string $why): void
{
    if (! $ok) {
        throw new RuntimeException($why);
    }
}

// Cloudflare in memory: every call is kept, every write succeeds.
$records = [];
$calls = [];
Subdomains::$transport = function (string $method, string $path, ?array $body) use (&$records, &$calls): array {
    $calls[] = $method.' '.strtok($path, '?');
    if (str_starts_with($path, '/zones?')) {
        return ['success' => true, 'result' => [['id' => str_repeat('a', 32)]]];
    }
    if ($method === 'GET') {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return ['success' => true, 'result' => array_values(array_filter($records, fn ($r) => ($r['comment'] ?? '') === ($query['comment_exact'] ?? $r['comment'] ?? '')
            && $r['name'] === ($query['name_exact'] ?? $r['name'])))];
    }
    $id = $method === 'POST' ? 'r'.count($calls) : basename($path);
    if ($method === 'DELETE') {
        unset($records[$id]);
    } else {
        $records[$id] = $body + ['id' => $id];
    }

    return ['success' => true];
};

// Pelican has no root_admin column: root admin is a role.
$admin = User::where('email', 'smoke-subdomains@example.com')->first()
    ?? User::factory()->create(['email' => 'smoke-subdomains@example.com']);
$admin->syncRoles(Role::getRootAdmin());

$server = null;
step('a test server is made', function () use ($admin, &$server) {
    $node = Node::factory()->create();
    $egg = Egg::query()->first() ?? Egg::factory()->create();
    $allocation = Allocation::factory()->create(['node_id' => $node->id, 'port' => 25570]);
    $server = Server::factory()->create([
        'owner_id' => $admin->id,
        'node_id' => $node->id,
        'egg_id' => $egg->id,
        'allocation_id' => $allocation->id,
    ]);
    $allocation->update(['server_id' => $server->id]);
    $server = $server->fresh(['allocation', 'egg']);
});
if ($server === null) {
    exit(1);
}
// Each Filament panel adds its render hooks for the whole process when it boots, so a page of another panel can
// then fail on a plugin it does not have. Open the server page before anything boots the admin panel.
step('the server page renders', function () use ($admin, $server) {
    Settings::save(['domain' => 'example.com', 'target' => 'play.example.com', 'port_min' => 1, 'port_max' => 65535, 'zone_id' => str_repeat('a', 32), 'token' => 'first-token']);
    $url = ServerSubdomain::getUrl(panel: 'server', tenant: $server);
    expectOk(visit($admin, $url), $url);
});
step('the settings page renders', function () use ($admin) {
    $url = AutomaticSubdomains::getUrl(panel: 'admin');
    expectOk(visit($admin, $url), $url);
});
step('settings save, Test finds the zone and the token is stored encrypted', function () use ($admin) {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($admin)->test(AutomaticSubdomains::class)
        ->set('data.domain', 'Example.com')
        ->set('data.target', 'play.example.com')
        ->set('data.port_min', 1)
        ->set('data.port_max', 65535)
        ->set('data.token', 'smoke-token')
        ->call('test')
        ->assertSet('data.zone_id', str_repeat('a', 32))
        ->call('save')
        ->assertHasNoErrors();
    Settings::flush();
    must(Subdomains::ready(), 'not ready after saving: missing '.implode(', ', Settings::missing()));
    must(Settings::domain() === 'example.com', 'the domain is not saved in lowercase');
    $stored = (string) DB::table(Settings::TABLE)->where('key', 'token')->value('value');
    must($stored !== '' && ! str_contains($stored, 'smoke-token'), 'the token is stored in plain text');
});

step('a subdomain reaches Cloudflare and shows as connected', function () use ($admin, $server, &$calls) {
    Filament::setCurrentPanel(Filament::getPanel('server'));
    Filament::setTenant($server);
    $calls = [];
    Livewire::actingAs($admin)->test(ServerSubdomain::class)->set('data.name', 'smoke-oak')->call('save')->assertHasNoErrors();
    must(in_array('POST /zones/'.str_repeat('a', 32).'/dns_records', $calls, true), 'no record created: '.json_encode($calls));
    must(Subdomains::state($server)['status'] === Subdomain::ACTIVE, 'not active: '.json_encode(Subdomains::state($server)));
    must(str_starts_with((string) Subdomains::address($server->fresh(['allocation', 'egg'])), 'smoke-oak.example.com'), 'no address');
    must(Subdomains::check('smoke-oak') !== null, 'the name is not taken for other servers');
});
step('Simplify tiles show the address, with one query for all tiles', function () use ($server) {
    if (! class_exists('Simplify\\Core\\Addons\\Addon')) {
        require __DIR__.'/stubs/Addon.php';
    }
    $addon = new SubdomainsAddon;
    $loaded = $server->fresh(['allocation', 'egg']);
    must(str_starts_with((string) ($addon->tileBadges($loaded)[0] ?? ''), 'smoke-oak.example.com'), 'no address on the tile');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $addon->tileBadges($loaded);
    $addon->tileBadges($loaded);
    must(DB::getQueryLog() === [], 'the next tiles query again: '.json_encode(DB::getQueryLog()));
    DB::disableQueryLog();
});
step('the sync command runs', function () {
    must(Artisan::call('simplify-subdomains:sync') === 0, Artisan::output());
});
step('deleting a server removes its records', function () use ($server, &$records) {
    Subdomains::forget($server->uuid);
    must($records === [] && Subdomain::of($server->uuid) === null, 'records left: '.json_encode($records));
});
step('settings and subdomains from PelicanSimplify are taken over', function () {
    DB::table('simplify_subdomains')->delete();
    DB::table(Settings::TABLE)->delete();
    $disk = Storage::disk('local');
    $disk->put('simplify/subdomains.json', json_encode(['domain' => 'old.example', 'target' => 'play.old.example', 'port_min' => 25565, 'port_max' => 25600, 'zone_id' => str_repeat('b', 32), 'token' => encrypt('old-token')]));
    $disk->put('simplify/subdomains/dddddddd-0000-0000-0000-000000000004.json', json_encode(['name' => 'oak', 'status' => 'active', 'port' => 25566]));
    (require dirname(__DIR__, 2).'/database/migrations/2026_10_28_000002_import_pelicansimplify_subdomains.php')->up();
    Settings::flush();
    must(Settings::domain() === 'old.example' && Settings::credentials()[0] === 'old-token', 'settings not taken over');
    must(Subdomain::of('dddddddd-0000-0000-0000-000000000004')?->name === 'oak', 'subdomain not taken over');
    $disk->deleteDirectory('simplify');
});
echo $failed ? "Some steps failed\n" : "All steps passed\n";
exit($failed ? 1 : 0);
