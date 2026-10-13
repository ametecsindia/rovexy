<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Domain\DomainException;
use App\Models\Accounts\Account;
use App\Models\Accounts\Category;
use App\Models\Accounts\Invoice;
use App\Models\Accounts\Transaction;
use App\Models\Platform\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The books. One ledger (acc_transactions) holds every rupee in or out: manual entries, payments against invoices and
 * bills, and paid sales orders (added automatically by Sales::markPaid). Business dates are Indian (Asia/Kolkata).
 */
final class Ledger
{
    public const TZ = 'Asia/Kolkata';

    public static function today(): string
    {
        return now(self::TZ)->toDateString();
    }

    /** Start of the Indian financial year (1 April) that contains $date. */
    public static function fyStart(?string $date = null): Carbon
    {
        $d = Carbon::parse($date ?? self::today(), self::TZ);
        return Carbon::create($d->month >= 4 ? $d->year : $d->year - 1, 4, 1, 0, 0, 0, self::TZ);
    }

    /** A paid sales order → one income line (idempotent: one line per order). */
    public static function recordOrder(SalesOrder $o): void
    {
        if ($o->status !== 'paid' || Transaction::query()->where('source', 'order')->where('source_id', $o->id)->exists()) {
            return;
        }
        $cat = Category::query()->where('kind', 'income')->where('system', true)->value('id')
            ?? Category::query()->where('kind', 'income')->orderBy('id')->value('id');
        Transaction::query()->create(['kind' => 'income', 'txn_date' => ($o->paid_at ?? now())->copy()->setTimezone(self::TZ)->toDateString(), 'amount' => $o->total,
            'tax_amount' => round($o->cgst + $o->sgst + $o->igst, 2), 'category_id' => $cat, 'account_id' => Account::query()->where('is_default', true)->where('status', 'active')->value('id'),
            'party' => $o->buyer_company, 'method' => $o->payment_method, 'reference' => $o->invoice_number ?: $o->number, 'description' => "Order {$o->number}",
            'status' => 'completed', 'source' => 'order', 'source_id' => $o->id, 'created_by' => 'system']);
    }

    /** @param list<array{description:string,qty:float|int|string,rate:float|int|string}> $items */
    public static function totals(array $items, float $taxRate): array
    {
        $lines = array_map(fn ($i) => ['description' => trim((string) $i['description']), 'qty' => round((float) $i['qty'], 2), 'rate' => round((float) $i['rate'], 2),
            'amount' => round((float) $i['qty'] * (float) $i['rate'], 2)], $items);
        $sub = round(array_sum(array_column($lines, 'amount')), 2);
        $tax = round($sub * $taxRate / 100, 2);
        return ['items' => $lines, 'subtotal' => $sub, 'tax_rate' => $taxRate, 'tax_amount' => $tax, 'total' => round($sub + $tax, 2)];
    }

    /** INV/2026-27/0001 — consecutive within the Indian financial year (GST rule for our own tax invoices). */
    public static function nextInvoiceNumber(string $type, ?string $issueDate = null): string
    {
        $fy = self::fyStart($issueDate);
        $prefix = ($type === 'bill' ? 'BILL' : 'INV') . '/' . $fy->year . '-' . substr((string) ($fy->year + 1), 2) . '/';
        $max = Invoice::query()->where('type', $type)->where('number', 'like', $prefix . '%')->lockForUpdate()->pluck('number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix)))->max() ?? 0;
        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Money received against an invoice, or paid against a bill. Can't exceed what is still due. */
    public static function pay(Invoice $inv, array $d, string $actor): Transaction
    {
        return DB::transaction(function () use ($inv, $d, $actor) {
            $inv = Invoice::query()->whereKey($inv->id)->lockForUpdate()->firstOrFail();
            if (in_array($inv->status, ['draft', 'cancelled'], true)) {
                throw new DomainException('invoice_not_open', $inv->status === 'draft' ? 'Issue the ' . $inv->type . ' before recording a payment.' : 'This ' . $inv->type . ' is cancelled.');
            }
            $amount = round((float) $d['amount'], 2);
            if ($amount > $inv->balance + 0.001) {
                throw new DomainException('overpayment', 'That is more than the ₹' . number_format($inv->balance, 2) . ' still due.');
            }
            $taxShare = $inv->total > 0 ? round($inv->tax_amount * $amount / $inv->total, 2) : 0;
            $t = Transaction::query()->create(['kind' => $inv->type === 'bill' ? 'expense' : 'income', 'txn_date' => $d['date'], 'amount' => $amount, 'tax_amount' => $taxShare,
                'category_id' => $inv->category_id, 'account_id' => $d['account_id'] ?? null, 'party' => $inv->party_name, 'method' => $d['method'],
                'reference' => $d['reference'] ?? null, 'description' => ($inv->type === 'bill' ? 'Bill ' : 'Invoice ') . $inv->number, 'status' => 'completed',
                'source' => $inv->type, 'source_id' => $inv->id, 'created_by' => $actor]);
            self::recalc($inv);
            return $t;
        });
    }

    /** amount_paid and status from the ledger (after a payment is added, edited or deleted). */
    public static function recalc(Invoice $inv): void
    {
        $paid = round((float) Transaction::query()->where('source', $inv->type)->where('source_id', $inv->id)->where('status', 'completed')->sum('amount'), 2);
        $status = $inv->status;
        if (!in_array($status, ['draft', 'cancelled'], true)) {
            $status = $paid >= $inv->total - 0.001 && $inv->total > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'issued');
        }
        $inv->forceFill(['amount_paid' => $paid, 'status' => $status])->save();
    }

    /** Current balance of each account: opening + completed income − completed expense. */
    public static function balances(): array
    {
        $flows = Transaction::query()->where('status', 'completed')->whereNotNull('account_id')->groupBy('account_id')
            ->selectRaw("account_id, SUM(CASE WHEN kind = 'income' THEN amount ELSE -amount END) AS net")->pluck('net', 'account_id');
        return Account::query()->orderByDesc('is_default')->orderBy('name')->get()->map(fn (Account $a) => $a->toArray() + [
            'balance' => round($a->opening_balance + (float) ($flows[$a->id] ?? 0), 2),
            'account_number_masked' => $a->account_number ? '•••• ' . substr($a->account_number, -4) : null,
        ])->all();
    }
}
