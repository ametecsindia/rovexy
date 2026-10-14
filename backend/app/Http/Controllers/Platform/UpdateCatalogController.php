<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Platform\ProductUpdate;
use App\Services\Licensing\LicenceSigner;
use App\Services\Platform\PlatformAudit;
use App\Services\Updates\UpdatePackage;
use Illuminate\Http\Request;

/**
 * Super Admin → Software updates: the catalogue on-premises servers are offered from. Super admins only.
 * A package is checked in a temporary place first (shape + version.json inside), then moved into storage/app/updates —
 * a rejected upload never lands next to (or over) a good package.
 */
final class UpdateCatalogController extends Controller
{
    private function dir(): string
    {
        $d = storage_path('app/updates');
        @mkdir($d, 0755, true);
        return $d;
    }

    public function index()
    {
        $this->superOnly();
        $known = ProductUpdate::query()->pluck('filename')->all();
        return response()->json([
            'updates' => ProductUpdate::query()->get()->sort(fn ($a, $b) => version_compare($b->version, $a->version))->values()
                ->map(fn (ProductUpdate $u) => $u->only(['id', 'version', 'min_version', 'channel', 'title', 'notes', 'filename', 'size_bytes', 'sha256', 'is_published', 'released_at', 'uploaded_by', 'downloads'])
                    + ['file_present' => is_file($u->path())]),
            'files_on_server' => collect(glob($this->dir() . '/*.zip') ?: [])->map(fn ($f) => basename($f))->reject(fn ($f) => in_array($f, $known, true))->values(),
            'can_sign' => LicenceSigner::canSign(),
            'upload_limit_mb' => (int) min($this->iniMb('upload_max_filesize'), $this->iniMb('post_max_size')),
        ]);
    }

    /** New entry from an uploaded ZIP (file) or a ZIP already in storage/app/updates (existing — e.g. dropped there by the build). */
    public function store(Request $r)
    {
        $this->superOnly();
        $d = $r->validate(['file' => 'nullable|file|max:2097152', 'existing' => 'nullable|string|max:190', 'title' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:8000', 'channel' => 'required|in:stable,beta', 'min_version' => ['nullable', 'regex:/^\d+(\.\d+){0,3}$/']]);
        if (!LicenceSigner::canSign()) {
            throw new DomainException('no_signing_key', 'The signing key is missing on this server (php artisan rovexy:licence-keys). Packages must be signed.');
        }
        if ($r->hasFile('file')) {
            $tmp = $r->file('file')->getRealPath();
            $info = UpdatePackage::inspect($tmp);   // before anything is kept
            $name = 'ROVEXY-Update-' . $info['version'] . '.zip';
            $dest = $this->dir() . '/' . $name;
            if (is_file($dest) && hash_file('sha256', $dest) !== hash_file('sha256', $tmp)) {
                throw new DomainException('file_exists', "{$name} already exists on the server with different contents. Remove it first.");
            }
            copy($tmp, $dest);
        } elseif (!empty($d['existing'])) {
            $name = basename($d['existing']);
            $dest = $this->dir() . '/' . $name;
            if (!is_file($dest)) {
                throw new DomainException('no_file', 'That file is not in storage/app/updates.');
            }
            $info = UpdatePackage::inspect($dest);
        } else {
            throw new DomainException('no_file', 'Upload the update ZIP or pick one already on the server.');
        }
        if (ProductUpdate::query()->where('version', $info['version'])->exists()) {
            throw new DomainException('version_exists', "Version {$info['version']} is already in the catalogue.");
        }
        $sha = hash_file('sha256', $dest);
        $u = ProductUpdate::query()->create(['version' => $info['version'], 'min_version' => $d['min_version'] ?? null, 'channel' => $d['channel'],
            'title' => $d['title'] ?? null, 'notes' => $d['notes'] ?? null, 'filename' => $name, 'size_bytes' => filesize($dest), 'sha256' => $sha,
            'signature' => UpdatePackage::signature($info['version'], $sha), 'uploaded_by' => auth('platform')->user()?->email]);
        PlatformAudit::record('update.saved', $u, ['version' => $u->version, 'files' => $info['files']]);
        return response()->json(['id' => $u->id, 'version' => $u->version], 201);
    }

    public function update(Request $r, int $id)
    {
        $this->superOnly();
        $u = ProductUpdate::query()->findOrFail($id);
        $d = $r->validate(['title' => 'nullable|string|max:150', 'notes' => 'nullable|string|max:8000', 'channel' => 'in:stable,beta',
            'min_version' => ['nullable', 'regex:/^\d+(\.\d+){0,3}$/']]);
        $u->fill($d)->save();
        PlatformAudit::record('update.saved', $u, ['version' => $u->version]);
        return response()->json(['ok' => true]);
    }

    public function publish(Request $r, int $id)
    {
        $this->superOnly();
        $u = ProductUpdate::query()->findOrFail($id);
        $on = (bool) $r->input('published', !$u->is_published);
        if ($on && (!is_file($u->path()) || hash_file('sha256', $u->path()) !== $u->sha256)) {
            throw new DomainException('file_changed', 'The package file is missing or changed since it was checked. Remove this entry and add the file again.');
        }
        $u->forceFill(['is_published' => $on, 'released_at' => $on ? ($u->released_at ?? now()) : $u->released_at])->save();
        PlatformAudit::record($on ? 'update.published' : 'update.unpublished', $u, ['version' => $u->version]);
        return response()->json(['ok' => true, 'is_published' => $on]);
    }

    public function destroy(int $id)
    {
        $this->superOnly();
        $u = ProductUpdate::query()->findOrFail($id);
        $u->delete();   // the ZIP stays on disk (it can be added again)
        PlatformAudit::record('update.removed', $u, ['version' => $u->version]);
        return response()->json(['ok' => true]);
    }

    private function superOnly(): void
    {
        if (!auth('platform')->user()?->isSuper()) {
            throw new DomainException('forbidden', 'Only a super admin can manage software updates.');
        }
    }

    private function iniMb(string $k): float
    {
        $v = trim((string) ini_get($k));
        $n = (float) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n * 1024, 'k' => $n / 1024, 'm' => $n, default => $n / 1048576 };
    }
}
