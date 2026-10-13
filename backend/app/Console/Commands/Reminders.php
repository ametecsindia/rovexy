<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Platform\Licence;
use App\Models\Platform\SalesOrder;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Sales;
use App\Services\Platform\Workspaces;
use App\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly automatic e-mails (Super Admin → Settings → "Automatic e-mails"):
 *  - subscription (cloud or on-prem): 30, 15, 7, 3, 1 and 0 days before expiry, and once when it has expired (grace running)
 *  - perpetual on-prem: AMC (updates & support) 30, 7 and 0 days before it ends
 *  - trial: 3, 1 and 0 days before it ends
 *  - unpaid website order: one reminder with the pay link 3 hours after it was placed
 * Each one is sent once (platform_mail_log has a unique kind + ref), so running hourly never double-sends.
 */
final class Reminders extends Command
{
    protected $signature = 'ammp:reminders {--dry-run : List what would be sent}';
    protected $description = 'Send ROVEXY renewal, trial-ending and unpaid-order reminder e-mails.';

    private const PACKAGE_DAYS = [30, 15, 7, 3, 1, 0];
    private const TRIAL_DAYS = [3, 1, 0];

    public function handle(CurrentCompany $ctx): int
    {
        if (PlatformSettings::get('reminders_enabled') !== '1') {
            $this->info('Automatic e-mails are switched off (Super Admin → Settings).');
            return self::SUCCESS;
        }
        $sent = 0;
        $licences = Licence::query()->where('status', 'active')->whereIn('kind', ['subscription', 'trial'])->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->addDays(31))->where('expires_at', '>', now()->subDays(60))->with('plan:id,name', 'company')->get();
        foreach ($licences as $l) {
            $left = (int) floor(now()->startOfDay()->diffInDays($l->expires_at->copy()->startOfDay(), false));
            $days = $l->kind === 'trial' ? self::TRIAL_DAYS : self::PACKAGE_DAYS;
            $kind = match (true) {
                in_array($left, $days, true) => ($l->kind === 'trial' ? 'trial' : 'package') . "_{$left}d",   // mail-log names kept from before 13 Oct so nothing re-sends
                $left < 0 && $l->kind === 'subscription' => 'package_expired',
                default => null,
            };
            if ($kind === null || (!$l->company && !$l->holder_email)) {
                continue;
            }
            $to = $l->company ? $this->recipient($l, $ctx) : $l->holder_email;
            $url = $l->company ? Workspaces::consoleUrl($l->company) . '/licence' : 'mailto:' . PlatformSettings::get('seller_email');
            $where = $l->company ? $l->company->slug : 'licence ' . $l->key;
            $until = $l->expires_at->format('d M Y');
            [$subject, $html] = match (true) {
                $l->kind === 'trial' => [$left === 0 ? 'Your ROVEXY trial ends today' : "Your ROVEXY trial ends in {$left} day" . ($left > 1 ? 's' : ''),
                    "<p>Your trial of workspace <b>{$where}</b> ends on {$until}. Choose a package to keep your team, cases and recordings: <a href=\"{$url}\">Plan &amp; licence</a>.</p>"],
                $kind === 'package_expired' => ['Your ROVEXY package has expired',
                    "<p>Your package for <b>{$where}</b> ended on {$until}. Phones stop working after " . $l->expires_at->copy()->addDays($l->grace_days)->format('d M Y')
                    . ". Renew here: <a href=\"{$url}\">Plan &amp; licence</a>.</p>"],
                default => [$left === 0 ? 'Your ROVEXY package ends today' : "Your ROVEXY package ends in {$left} days",
                    "<p>Your " . e($l->plan?->name ?? '') . " package for <b>{$where}</b> ends on {$until}. Renewing early loses no days: <a href=\"{$url}\">Renew now</a>.</p>"],
            };
            $sent += $this->send($kind, "licence:{$l->id}:" . $l->expires_at->toDateString(), $to, $subject, $html);
        }
        // perpetual on-prem: AMC ending (the licence keeps working; updates & support stop)
        foreach (Licence::query()->where('status', 'active')->where('kind', 'perpetual')->whereNotNull('amc_expires_at')->whereNotNull('holder_email')
            ->whereBetween('amc_expires_at', [now()->subDay(), now()->addDays(31)])->get() as $l) {
            $left = (int) floor(now()->startOfDay()->diffInDays($l->amc_expires_at->copy()->startOfDay(), false));
            if (in_array($left, [30, 7, 0], true)) {
                $sent += $this->send("amc_{$left}d", "licence:{$l->id}:" . $l->amc_expires_at->toDateString(), $l->holder_email,
                    $left === 0 ? 'Your ROVEXY updates & support end today' : "Your ROVEXY updates & support end in {$left} days",
                    '<p>Your ROVEXY licence <b>' . e($l->key) . '</b> keeps working, but updates and support end on ' . $l->amc_expires_at->format('d M Y')
                    . '. Reply to this e-mail or write to ' . e((string) PlatformSettings::get('seller_email')) . ' to renew the annual maintenance (AMC).</p><p>— Ametecs India</p>');
            }
        }
        $orders = SalesOrder::query()->where('status', 'created')->where('kind', 'new')->whereBetween('created_at', [now()->subDays(2), now()->subHours(3)])->get();
        foreach ($orders as $o) {
            $sent += $this->send('order_unpaid', "order:{$o->id}", $o->buyer_email, "Your ROVEXY order {$o->number} is waiting for payment",
                '<p>Dear ' . e($o->buyer_name) . ',</p><p>Your order for ROVEXY ' . e($o->plan->name) . ' (₹' . number_format($o->total, 2) . ') is not paid yet. '
                . 'Pay here to get your workspace straight away: <a href="' . e($o->payUrl()) . '">' . e($o->payUrl()) . '</a></p><p>— Ametecs India</p>');
        }
        $this->info(($this->option('dry-run') ? 'Would send ' : 'Sent ') . "{$sent} e-mail(s).");
        return self::SUCCESS;
    }

    /** The workspace's company admin, else the e-mail on the licence. */
    private function recipient(Licence $l, CurrentCompany $ctx): ?string
    {
        $admin = $ctx->run($l->company, fn () => User::query()->where('status', 'active')->whereNotNull('email')
            ->whereHas('roles', fn ($q) => $q->where('key', 'company_admin'))->orderBy('id')->value('email'));
        return $admin ?? $l->holder_email;
    }

    private function send(string $kind, string $ref, ?string $to, string $subject, string $html): int
    {
        if (!$to || DB::table('platform_mail_log')->where('kind', $kind)->where('ref', $ref)->exists()) {
            return 0;
        }
        if ($this->option('dry-run')) {
            $this->line("{$kind} → {$to}: {$subject}");
            return 1;
        }
        DB::table('platform_mail_log')->insert(['kind' => $kind, 'ref' => $ref, 'email' => $to, 'created_at' => now()]);
        Sales::send($to, $subject, $html . '<p style="color:#777;font-size:12px">Ametecs India Private Limited · ROVEXY</p>');
        return 1;
    }
}
