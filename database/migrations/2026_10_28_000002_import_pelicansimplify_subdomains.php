<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Takes over the subdomains built into PelicanSimplify 0.6 and 0.7, so a panel keeps its setup and its addresses.
 * Those versions kept them in storage/app/simplify/subdomains.json and storage/app/simplify/subdomains/<uuid>.json.
 * The old files are only read, never changed. The token was encrypted with the same APP_KEY, so it is kept as is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $disk = Storage::disk('local');
        $settings = $this->json($disk->get('simplify/subdomains.json'));
        if ($settings !== [] && DB::table('simplify_subdomain_settings')->doesntExist()) {
            foreach (['domain', 'target', 'port_min', 'port_max', 'zone_id', 'token'] as $key) {
                if (is_scalar($settings[$key] ?? null) && (string) $settings[$key] !== '') {
                    DB::table('simplify_subdomain_settings')->insert(['key' => $key, 'value' => (string) $settings[$key]]);
                }
            }
        }

        if (DB::table('simplify_subdomains')->exists()) {
            return;
        }
        $names = [];
        foreach ($disk->files('simplify/subdomains') as $path) {
            $uuid = pathinfo($path, PATHINFO_FILENAME);
            $state = $this->json($disk->get($path));
            $name = strtolower((string) ($state['name'] ?? ''));
            if (! preg_match('/^[a-f0-9-]{36}$/i', $uuid) || ($name !== '' && isset($names[$name]))) {
                continue;
            }
            $names[$name] = true;
            // Pending: the first sync checks every record again, with the tag PelicanSimplify also used.
            DB::table('simplify_subdomains')->insert([
                'server_uuid' => $uuid,
                'name' => $name === '' ? null : $name,
                'status' => $name === '' ? 'removing' : 'pending',
                'message' => '',
                'port' => isset($state['port']) ? (int) $state['port'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}

    /** @return array<string, mixed> */
    private function json(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
};
