<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\CompanyBranding;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Storage;

/** Public, unauthenticated: only approved public branding and server identity. No company data. */
final class BootstrapController extends Controller
{
    public function show(CurrentCompany $current)
    {
        $c = $current->get();
        $b = CompanyBranding::query()->first();
        return response()->json([
            'api_version' => 1,
            'product_name' => config('ammp.product_name'),
            'company' => ['slug' => $c->slug, 'name' => $b?->display_name ?? $c->name],
            'branding' => [
                'version' => $b?->version ?? 0,
                'display_name' => $b?->display_name ?? $c->name,
                'logo_url' => $b?->logoUrl(), // null => neutral placeholder
                'logo_sha256' => $b?->logo_sha256,
                'primary_color' => $b?->primary_color ?? '#1F3A5F',
                'accent_color' => $b?->accent_color ?? '#B8893B',
            ],
            'server_time' => now()->toAtomString(),
        ]);
    }

    /** Logo by workspace in the path (no X-Workspace needed): used by the console <img> and the app login screen. */
    public function logoFor(string $workspace, CurrentCompany $ctx)
    {
        $c = \App\Models\Company::query()->where('slug', strtolower($workspace))->where('status', 'active')->firstOrFail();
        return $ctx->run($c, fn () => $this->logo());
    }

    public function logo()
    {
        $b = CompanyBranding::query()->whereNotNull('logo_key')->firstOrFail();
        return Storage::disk('company_files')->response($b->logo_key, 'logo', [
            'Content-Type' => $b->logo_mime, 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
