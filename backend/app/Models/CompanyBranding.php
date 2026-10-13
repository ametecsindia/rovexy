<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CompanyBranding extends Model
{
    use BelongsToCompany;

    protected $table = 'company_branding';

    protected $fillable = ['display_name','logo_key','logo_sha256','logo_mime','primary_color','accent_color','version','updated_by'];

    /** Public logo link. Carries the workspace (an <img> can't send X-Workspace) so each company gets — and caches — its own logo. */
    public function logoUrl(): ?string
    {
        $slug = Company::query()->whereKey($this->company_id)->value('slug');
        return $this->logo_key && $slug ? url("/api/mobile/v1/branding/{$slug}/logo") . '?v=' . $this->version : null;
    }

    protected function casts(): array
    {
        return [];
    }
}
