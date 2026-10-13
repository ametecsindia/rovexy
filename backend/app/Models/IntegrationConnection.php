<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A company's connection to SmartPRS2 or SmartEPT (base URL + API key issued by that system). */
class IntegrationConnection extends Model
{
    use BelongsToCompany;

    public const SYSTEMS = ['smartprs', 'smartept'];

    protected $table = 'integration_connections';

    protected $fillable = ['system', 'base_url', 'api_key', 'enabled', 'enforce_geofence', 'last_status', 'last_ok_at'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'enabled' => 'boolean', 'enforce_geofence' => 'boolean', 'last_ok_at' => 'datetime'];
    }

    public function url(string $path): string
    {
        // People paste the URL SmartEPT shows (".../api/v1"); paths below already include /api/v1.
        return preg_replace('#/api(/v\d+)?/*$#i', '', rtrim(trim($this->base_url), '/')) . $path;
    }
}
