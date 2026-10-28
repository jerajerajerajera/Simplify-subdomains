<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A server's subdomain and how far it is connected in Cloudflare.
 *
 * @property int $id
 * @property string $server_uuid
 * @property string|null $name null once the server is deleted and only its records are left to remove
 * @property string $status pending, active, error or removing
 * @property string $message why the subdomain is not connected
 * @property int|null $port the port the records were made for
 */
final class Subdomain extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const ERROR = 'error';

    public const REMOVING = 'removing';

    protected $table = 'simplify_subdomains';

    protected $fillable = ['server_uuid', 'name', 'status', 'message', 'port'];

    protected $casts = ['port' => 'integer'];

    public static function of(string $uuid): ?self
    {
        return self::query()->where('server_uuid', $uuid)->first();
    }
}
