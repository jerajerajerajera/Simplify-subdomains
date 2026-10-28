<?php

// Loads Simplify Subdomains inside a real Pelican panel, opens its pages and runs a subdomain through a fake
// Cloudflare. Run from the panel folder after `php artisan p:plugin:install simplify-subdomains`:
// php plugins/simplify-subdomains/tests/panel/smoke.php
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
use Simplify\Subdomains\Filament\Admin\Pages\AutomaticSubdomains;
use Simplify\Subdomains\Filament\Server\Pages\ServerSubdomain;
use Simplify\Subdomains\Models\Subdomain;
use Simplify\Subdomains\Services\Settings;
use Simplify\Subdomains\Subdomains;

define('LARAVEL_START', microtime(true));
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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
function expect(bool $ok, string $why): void
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

$admin = User::where('email', 'smoke-subdomains@example.com')->first()
    ?? User::factory()->create(['email' => 'smoke-subdomains@example.com', 'root_admin' => true]);

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
    expect(Subdomains::ready(), 'not ready after saving: missing '.implode(', ', Settings::missing()));
    expect(Settings::domain() === 'example.com', 'the domain is not saved in lowercase');
    $stored = (string) DB::table(Settings::TABLE)->where('key', 'token')->value('value');
    expect($stored !== '' && ! str_contains($stored, 'smoke-token'), 'the token is stored in plain text');
});

$server = Server::query()->with(['allocation', 'egg'])->first() ?? Server::factory()->create(['owner_id' => $admin->id]);
step('the server page renders', function () use ($admin, $server) {
    $url = ServerSubdomain::getUrl(panel: 'server', tenant: $server);
    expectOk(visit($admin, $url), $url);
});
step('a subdomain reaches Cloudflare and shows as connected', function () use ($admin, $server, &$calls) {
    Filament::setCurrentPanel(Filament::getPanel('server'));
    Filament::setTenant($server);
    $calls = [];
    Livewire::actingAs($admin)->test(ServerSubdomain::class)->set('data.name', 'smoke-oak')->call('save')->assertHasNoErrors();
    expect(in_array('POST /zones/'.str_repeat('a', 32).'/dns_records', $calls, true), 'no record created: '.json_encode($calls));
    expect(Subdomains::state($server)['status'] === Subdomain::ACTIVE, 'not active: '.json_encode(Subdomains::state($server)));
    expect(str_starts_with((string) Subdomains::address($server->fresh(['allocation', 'egg'])), 'smoke-oak.example.com'), 'no address');
    expect(Subdomains::check('smoke-oak') !== null, 'the name is not taken for other servers');
});
step('the sync command runs', function () {
    expect(Artisan::call('simplify-subdomains:sync') === 0, Artisan::output());
});
step('deleting a server removes its records', function () use ($server, &$records) {
    Subdomains::forget($server->uuid);
    expect($records === [] && Subdomain::of($server->uuid) === null, 'records left: '.json_encode($records));
});
step('settings and subdomains from PelicanSimplify are taken over', function () {
    DB::table('simplify_subdomains')->delete();
    DB::table(Settings::TABLE)->delete();
    $disk = Storage::disk('local');
    $disk->put('simplify/subdomains.json', json_encode(['domain' => 'old.example', 'target' => 'play.old.example', 'port_min' => 25565, 'port_max' => 25600, 'zone_id' => str_repeat('b', 32), 'token' => encrypt('old-token')]));
    $disk->put('simplify/subdomains/dddddddd-0000-0000-0000-000000000004.json', json_encode(['name' => 'oak', 'status' => 'active', 'port' => 25566]));
    (require dirname(__DIR__, 2).'/database/migrations/2026_10_28_000002_import_pelicansimplify_subdomains.php')->up();
    Settings::flush();
    expect(Settings::domain() === 'old.example' && Settings::credentials()[0] === 'old-token', 'settings not taken over');
    expect(Subdomain::of('dddddddd-0000-0000-0000-000000000004')?->name === 'oak', 'subdomain not taken over');
    $disk->deleteDirectory('simplify');
});
exit($failed ? 1 : 0);
