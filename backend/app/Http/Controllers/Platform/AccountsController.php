<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Accounts\Account;
use App\Models\Accounts\Category;
use App\Models\Accounts\Invoice;
use App\Models\Accounts\Transaction;
use App\Models\Platform\SalesOrder;
use App\Services\Accounts\Exporter;
use App\Services\Accounts\Ledger;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Accounts API (/api/super/accounts/*), behind `platform:accounts` (accountant = manage, super = manage, other staff none).
 * Ametecs' own books: dashboard, ledger (income & expense), invoices & bills, payments, reports, money accounts,
 * categories and the paid subscription invoices from the sales side. Every write is in the platform audit log.
 */
final class AccountsController extends Controller
{
    private function actor(): string
    {
        return 'staff:' . auth('platform')->user()->email;
    }

    /** @return array{0:string,1:string} [from, to] as Y-m-d (Indian dates); default = this month */
    private function range(Request $r, string $default = 'month'): array
    {
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $today = Carbon::parse(Ledger::today(), Ledger::TZ);
        $from = $r->query('from') ?: ($default === 'fy' ? Ledger::fyStart()->toDateString() : $today->copy()->startOfMonth()->toDateString());
        $to = $r->query('to') ?: $today->toDateString();
        return [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()];
    }

    private static function money(float|int|string|null $v): float
    {
        return round((float) $v, 2);
    }

    // ── Dashboard ──
    public function dashboard(Request $r)
    {
        [$from, $to] = $this->range($r);
        $sum = fn (string $kind, ?string $f = null, ?string $t = null) => self::money(Transaction::query()->where('kind', $kind)->where('status', 'completed')
            ->when($f, fn ($q) => $q->whereBetween('txn_date', [$f, $t]))->sum('amount'));
        $open = fn (string $type) => Invoice::query()->where('type', $type)->whereIn('status', ['issued', 'partial']);
        $overdue = fn (string $type) => $open($type)->whereNotNull('due_date')->where('due_date', '<', Ledger::today());
        $fy = Ledger::fyStart()->toDateString();

        // last 12 months, oldest first, with zero months filled in
        $months = collect(range(11, 0))->map(fn ($i) => Carbon::parse(Ledger::today())->startOfMonth()->subMonths($i));
        $flow = Transaction::query()->where('status', 'completed')->where('txn_date', '>=', $months->first()->toDateString())
            ->selectRaw("substr(txn_date, 1, 7) AS ym, kind, SUM(amount) AS total")->groupBy('ym', 'kind')->get()
            ->groupBy('ym')->map(fn ($g) => $g->pluck('total', 'kind'));
        $trend = $months->map(fn (Carbon $m) => ['month' => $m->format('Y-m'), 'label' => $m->format('M y'),
            'income' => self::money($flow[$m->format('Y-m')]['income'] ?? 0), 'expense' => self::money($flow[$m->format('Y-m')]['expense'] ?? 0)]);

        $income = $sum('income', $from, $to);
        $expense = $sum('expense', $from, $to);
        return response()->json([
            'from' => $from, 'to' => $to,
            'income' => $income, 'expense' => $expense, 'net' => self::money($income - $expense),
            'income_fy' => $sum('income', $fy, Ledger::today()), 'expense_fy' => $sum('expense', $fy, Ledger::today()),
            'receivable' => self::money($open('invoice')->get()->sum('balance')), 'payable' => self::money($open('bill')->get()->sum('balance')),
            'overdue_receivable' => self::money($overdue('invoice')->get()->sum('balance')), 'overdue_receivable_count' => $overdue('invoice')->count(),
            'overdue_payable' => self::money($overdue('bill')->get()->sum('balance')), 'overdue_payable_count' => $overdue('bill')->count(),
            'unpaid_orders' => self::money(SalesOrder::query()->where('status', 'created')->sum('total')), 'unpaid_orders_count' => SalesOrder::query()->where('status', 'created')->count(),
            'pending_entries' => Transaction::query()->where('status', 'pending')->count(),
            'cash_balance' => self::money(collect(Ledger::balances())->where('status', 'active')->sum('balance')),
            'trend' => $trend,
            'expense_by_category' => Transaction::query()->from('acc_transactions as t')->leftJoin('acc_categories as c', 'c.id', '=', 't.category_id')
                ->where('t.kind', 'expense')->where('t.status', 'completed')->whereBetween('t.txn_date', [$from, $to])
                ->groupBy('c.name')->selectRaw("COALESCE(c.name, 'Uncategorised') AS name, SUM(t.amount) AS total")->orderByDesc('total')->limit(8)->get()
                ->map(fn ($x) => ['name' => $x->name, 'total' => self::money($x->total)]),
            'due_soon' => Invoice::query()->whereIn('status', ['issued', 'partial'])->whereNotNull('due_date')
                ->whereBetween('due_date', [Ledger::today(), Carbon::parse(Ledger::today())->addDays(14)->toDateString()])->orderBy('due_date')->limit(8)->get(),
            'recent' => Transaction::query()->with('category:id,name', 'account:id,name')->latest('txn_date')->latest('id')->limit(8)->get(),
            'accounts' => Ledger::balances(),
        ]);
    }

    // ── Transactions (income & expense) ──
    private function txnQuery(Request $r): Builder
    {
        $r->validate(['kind' => 'nullable|in:income,expense', 'status' => 'nullable|in:completed,pending', 'source' => 'nullable|in:manual,order,invoice,bill',
            'category_id' => 'nullable|integer', 'account_id' => 'nullable|integer', 'method' => ['nullable', Rule::in(Transaction::METHODS)], 'q' => 'nullable|string|max:100']);
        [$from, $to] = $this->range($r, 'fy');
        $q = trim((string) $r->query('q'));
        return Transaction::query()->with('category:id,name', 'account:id,name')->whereBetween('txn_date', [$from, $to])
            ->when($r->query('kind'), fn ($w, $v) => $w->where('kind', $v))
            ->when($r->query('status'), fn ($w, $v) => $w->where('status', $v))
            ->when($r->query('source'), fn ($w, $v) => $w->where('source', $v))
            ->when($r->query('category_id'), fn ($w, $v) => $w->where('category_id', $v))
            ->when($r->query('account_id'), fn ($w, $v) => $w->where('account_id', $v))
            ->when($r->query('method'), fn ($w, $v) => $w->where('method', $v))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('party', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%")));
    }

    public function transactions(Request $r)
    {
        $base = $this->txnQuery($r);
        $totals = (clone $base)->getQuery();
        $sums = DB::query()->fromSub($totals->select('kind', 'amount', 'status'), 'x')->where('status', 'completed')
            ->selectRaw("SUM(CASE WHEN kind = 'income' THEN amount ELSE 0 END) AS income, SUM(CASE WHEN kind = 'expense' THEN amount ELSE 0 END) AS expense")->first();
        $page = $base->orderByDesc('txn_date')->orderByDesc('id')->paginate(min(100, max(10, (int) $r->query('per_page', 25))));
        return response()->json($page->toArray() + ['totals' => ['income' => self::money($sums->income ?? 0), 'expense' => self::money($sums->expense ?? 0),
            'net' => self::money(($sums->income ?? 0) - ($sums->expense ?? 0))]]);
    }

    private function txnRules(?Transaction $t = null): array
    {
        return ['kind' => 'required|in:income,expense', 'txn_date' => 'required|date|before_or_equal:' . Carbon::parse(Ledger::today())->addYear()->toDateString(),
            'amount' => 'required|numeric|min:0.01|max:9999999999', 'tax_amount' => 'nullable|numeric|min:0|lte:amount',
            'category_id' => ['nullable', 'integer', Rule::exists('acc_categories', 'id')->where('kind', request('kind'))],
            'account_id' => ['nullable', 'integer', Rule::exists('acc_accounts', 'id')], 'party' => 'nullable|string|max:150',
            'method' => ['nullable', Rule::in(Transaction::METHODS)], 'reference' => 'nullable|string|max:100', 'description' => 'nullable|string|max:500',
            'status' => 'required|in:completed,pending'];
    }

    public function createTransaction(Request $r)
    {
        $d = $r->validate($this->txnRules(), ['category_id.exists' => 'Pick a category of the same type (income or expense).']);
        $t = Transaction::query()->create($d + ['source' => 'manual', 'tax_amount' => $d['tax_amount'] ?? 0, 'created_by' => $this->actor()]);
        PlatformAudit::record('accounts.txn_created', $t, ['kind' => $t->kind, 'amount' => $t->amount]);
        return response()->json($t->load('category:id,name', 'account:id,name'), 201);
    }

    public function updateTransaction(Request $r, int $id)
    {
        $t = Transaction::query()->findOrFail($id);
        if ($t->source === 'order') {
            // A subscription payment is owned by its sales order (and its tax invoice); only the money account and note can change here.
            $d = $r->validate(['account_id' => ['nullable', 'integer', Rule::exists('acc_accounts', 'id')], 'description' => 'nullable|string|max:500']);
            $t->fill($d + ['updated_by' => $this->actor()])->save();
        } elseif (in_array($t->source, ['invoice', 'bill'], true)) {
            $d = $r->validate(['txn_date' => 'required|date', 'account_id' => ['nullable', 'integer', Rule::exists('acc_accounts', 'id')], 'method' => ['nullable', Rule::in(Transaction::METHODS)],
                'reference' => 'nullable|string|max:100', 'description' => 'nullable|string|max:500']);
            $t->fill($d + ['updated_by' => $this->actor()])->save();
        } else {
            $d = $r->validate($this->txnRules($t), ['category_id.exists' => 'Pick a category of the same type (income or expense).']);
            $t->fill($d + ['tax_amount' => $d['tax_amount'] ?? 0, 'updated_by' => $this->actor()])->save();
        }
        PlatformAudit::record('accounts.txn_updated', $t, ['amount' => $t->amount, 'source' => $t->source]);
        return response()->json($t->fresh()->load('category:id,name', 'account:id,name'));
    }

    public function deleteTransaction(int $id)
    {
        $t = Transaction::query()->findOrFail($id);
        if ($t->source === 'order') {
            throw new DomainException('order_payment', 'This is a paid subscription order with a tax invoice. It stays in the books; to reverse it, record a refund as an expense.');
        }
        DB::transaction(function () use ($t) {
            $t->delete();
            if (in_array($t->source, ['invoice', 'bill'], true) && ($inv = Invoice::query()->find($t->source_id))) {
                Ledger::recalc($inv);
            }
        });
        PlatformAudit::record('accounts.txn_deleted', null, ['id' => $t->id, 'kind' => $t->kind, 'amount' => $t->amount, 'source' => $t->source]);
        return response()->json(['ok' => true]);
    }

    public function exportTransactions(Request $r)
    {
        $format = $r->validate(['format' => 'required|in:xlsx,csv,pdf'])['format'];
        [$from, $to] = $this->range($r, 'fy');
        $rows = $this->txnQuery($r)->orderBy('txn_date')->orderBy('id')->get();
        $in = $rows->where('status', 'completed')->where('kind', 'income')->sum('amount');
        $out = $rows->where('status', 'completed')->where('kind', 'expense')->sum('amount');
        PlatformAudit::record('accounts.exported', null, ['what' => 'transactions', 'format' => $format, 'rows' => $rows->count()]);
        return Exporter::download($format, 'rovexy-transactions', 'Transactions', ['Date', 'Type', 'Category', 'Party', 'Description', 'Account', 'Method', 'Reference', 'Status', 'Income', 'Expense'],
            $rows->map(fn (Transaction $t) => [Carbon::parse($t->txn_date)->format('d-m-Y'), ucfirst($t->kind), $t->category?->name, $t->party, $t->description, $t->account?->name,
                $t->method, $t->reference, ucfirst($t->status), $t->kind === 'income' ? (float) $t->amount : null, $t->kind === 'expense' ? (float) $t->amount : null])->all(),
            ['Total (completed)', '', '', '', '', '', '', '', '', self::money($in), self::money($out)],
            ['Period' => Carbon::parse($from)->format('d M Y') . ' – ' . Carbon::parse($to)->format('d M Y'), 'Net' => '₹' . number_format($in - $out, 2)]);
    }

    // ── Categories ──
    public function categories()
    {
        $used = Transaction::query()->groupBy('category_id')->selectRaw('category_id, COUNT(*) n')->pluck('n', 'category_id');
        return response()->json(Category::query()->orderBy('kind')->orderBy('name')->get()->map(fn (Category $c) => $c->toArray() + ['used' => (int) ($used[$c->id] ?? 0)]));
    }

    public function saveCategory(Request $r, ?int $id = null)
    {
        $c = $id ? Category::query()->findOrFail($id) : new Category();
        $d = $r->validate(['kind' => [$id ? 'prohibited' : 'required', 'in:income,expense'], 'name' => ['required', 'string', 'max:80',
            Rule::unique('acc_categories')->where('kind', $id ? $c->kind : $r->input('kind'))->ignore($id)], 'active' => 'boolean'],
            ['kind.prohibited' => 'The type of a category cannot change once it is created.', 'name.unique' => 'There is already a category with this name.']);
        $c->fill($d)->save();
        PlatformAudit::record($id ? 'accounts.category_updated' : 'accounts.category_created', $c, ['name' => $c->name]);
        return response()->json($c, $id ? 200 : 201);
    }

    public function deleteCategory(int $id)
    {
        $c = Category::query()->findOrFail($id);
        if ($c->system || Transaction::query()->where('category_id', $id)->exists() || Invoice::query()->where('category_id', $id)->exists()) {
            throw new DomainException('category_in_use', 'This category is used in the books, so it is kept. Switch it off instead.');
        }
        $c->delete();
        PlatformAudit::record('accounts.category_deleted', null, ['id' => $id, 'name' => $c->name]);
        return response()->json(['ok' => true]);
    }

    // ── Money accounts ──
    public function accounts()
    {
        return response()->json(Ledger::balances());
    }

    public function saveAccount(Request $r, ?int $id = null)
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:120', Rule::unique('acc_accounts')->ignore($id)], 'type' => ['required', Rule::in(Account::TYPES)],
            'bank_name' => 'nullable|string|max:120', 'account_number' => 'nullable|string|max:40|regex:/^[A-Za-z0-9 -]+$/', 'ifsc' => ['nullable', 'string', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
            'opening_balance' => 'nullable|numeric|min:-9999999999|max:9999999999', 'opening_date' => 'nullable|date', 'is_default' => 'boolean',
            'status' => 'in:active,closed', 'notes' => 'nullable|string|max:1000'],
            ['ifsc.regex' => 'IFSC: 11 characters, like HDFC0001234.', 'name.unique' => 'There is already an account with this name.']);
        $a = $id ? Account::query()->findOrFail($id) : new Account();
        DB::transaction(function () use ($a, $d) {
            if (!empty($d['is_default'])) {
                Account::query()->where('is_default', true)->update(['is_default' => false]);
            }
            $a->fill($d + ['opening_balance' => $d['opening_balance'] ?? 0, 'ifsc' => isset($d['ifsc']) ? strtoupper($d['ifsc']) : null])->save();
        });
        PlatformAudit::record($id ? 'accounts.account_updated' : 'accounts.account_created', $a, ['name' => $a->name, 'type' => $a->type]);
        return response()->json(collect(Ledger::balances())->firstWhere('id', $a->id), $id ? 200 : 201);
    }

    public function deleteAccount(int $id)
    {
        $a = Account::query()->findOrFail($id);
        if (Transaction::query()->where('account_id', $id)->exists()) {
            throw new DomainException('account_in_use', 'This account has entries in the books, so it is kept. Mark it Closed instead.');
        }
        $a->delete();
        PlatformAudit::record('accounts.account_deleted', null, ['id' => $id, 'name' => $a->name]);
        return response()->json(['ok' => true]);
    }

    // ── Invoices (we bill) & bills (we owe) ──
    private function invoiceQuery(Request $r): Builder
    {
        $r->validate(['type' => 'nullable|in:invoice,bill', 'status' => 'nullable|in:draft,issued,partial,paid,cancelled,overdue,open', 'q' => 'nullable|string|max:100']);
        $q = trim((string) $r->query('q'));
        $today = Ledger::today();
        return Invoice::query()->with('category:id,name')
            ->when($r->query('type'), fn ($w, $v) => $w->where('type', $v))
            ->when($r->query('from'), fn ($w, $v) => $w->where('issue_date', '>=', $v))
            ->when($r->query('to'), fn ($w, $v) => $w->where('issue_date', '<=', $v))
            ->when($r->query('status'), fn ($w, $s) => match ($s) {
                'overdue' => $w->whereIn('status', ['issued', 'partial'])->whereNotNull('due_date')->where('due_date', '<', $today),
                'open' => $w->whereIn('status', ['issued', 'partial']),
                'issued', 'partial' => $w->where('status', $s)->where(fn ($x) => $x->whereNull('due_date')->orWhere('due_date', '>=', $today)),
                default => $w->where('status', $s),
            })
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'like', "%{$q}%")->orWhere('party_name', 'like', "%{$q}%")->orWhere('party_email', 'like', "%{$q}%")));
    }

    public function invoices(Request $r)
    {
        return response()->json($this->invoiceQuery($r)->orderByDesc('issue_date')->orderByDesc('id')->paginate(min(100, max(10, (int) $r->query('per_page', 25)))));
    }

    public function invoice(int $id)
    {
        $inv = Invoice::query()->with('category:id,name')->findOrFail($id);
        return response()->json($inv->toArray() + ['payments' => Transaction::query()->with('account:id,name')->where('source', $inv->type)->where('source_id', $inv->id)
            ->orderBy('txn_date')->orderBy('id')->get()]);
    }

    private function invoiceRules(Request $r, ?Invoice $inv = null): array
    {
        $type = $inv?->type ?? $r->input('type');
        return ['type' => [$inv ? 'prohibited' : 'required', 'in:invoice,bill'],
            'number' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\/_.-]+$/', Rule::unique('acc_invoices')->where('type', $type)->ignore($inv?->id)],
            'party_name' => 'required|string|max:150', 'party_email' => 'nullable|email|max:190', 'party_phone' => 'nullable|string|max:20',
            'party_gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/'], 'party_address' => 'nullable|string|max:300',
            'issue_date' => 'required|date', 'due_date' => 'nullable|date|after_or_equal:issue_date',
            'items' => 'required|array|min:1|max:50', 'items.*.description' => 'required|string|max:200', 'items.*.qty' => 'required|numeric|min:0.01|max:1000000',
            'items.*.rate' => 'required|numeric|min:0|max:9999999999', 'tax_rate' => 'required|numeric|min:0|max:40',
            'category_id' => ['nullable', 'integer', Rule::exists('acc_categories', 'id')->where('kind', $type === 'bill' ? 'expense' : 'income')],
            'notes' => 'nullable|string|max:2000', 'issue' => 'boolean'];
    }

    private const INVOICE_MESSAGES = ['party_gstin.regex' => 'GSTIN: 15 characters, like 36ABCDE1234F1Z5.', 'number.unique' => 'This number is already used.',
        'items.required' => 'Add at least one line.', 'items.*.description.required' => 'Each line needs a description.',
        'category_id.exists' => 'Pick an income category for an invoice, or an expense category for a bill.', 'number.regex' => 'Number: letters, digits and / - _ . only.'];

    public function createInvoice(Request $r)
    {
        $r->merge(['party_gstin' => $r->filled('party_gstin') ? strtoupper(trim((string) $r->input('party_gstin'))) : null]);
        $d = $r->validate($this->invoiceRules($r), self::INVOICE_MESSAGES);
        $inv = DB::transaction(function () use ($d) {
            $totals = Ledger::totals($d['items'], (float) $d['tax_rate']);
            if ($totals['total'] <= 0) {
                throw new DomainException('zero_total', 'The total must be more than ₹0.');
            }
            return Invoice::query()->create(array_merge($d, $totals, [
                'number' => ($d['number'] ?? '') !== '' ? $d['number'] : Ledger::nextInvoiceNumber($d['type'], $d['issue_date']),
                'party_gstin' => isset($d['party_gstin']) ? strtoupper($d['party_gstin']) : null,
                'status' => !empty($d['issue']) ? 'issued' : 'draft', 'amount_paid' => 0, 'created_by' => $this->actor(),
            ]));
        });
        PlatformAudit::record('accounts.' . $inv->type . '_created', $inv, ['number' => $inv->number, 'total' => $inv->total]);
        return response()->json($inv, 201);
    }

    public function updateInvoice(Request $r, int $id)
    {
        $inv = Invoice::query()->findOrFail($id);
        if ($inv->status === 'cancelled') {
            throw new DomainException('invoice_cancelled', 'A cancelled ' . $inv->type . ' cannot be changed.');
        }
        $r->merge(['party_gstin' => $r->filled('party_gstin') ? strtoupper(trim((string) $r->input('party_gstin'))) : null]);
        $d = $r->validate($this->invoiceRules($r, $inv), self::INVOICE_MESSAGES);
        DB::transaction(function () use ($inv, $d) {
            $totals = Ledger::totals($d['items'], (float) $d['tax_rate']);
            if ($totals['total'] < $inv->amount_paid - 0.001) {
                throw new DomainException('below_paid', 'The new total is less than the ₹' . number_format($inv->amount_paid, 2) . ' already paid. Delete a payment first.');
            }
            if ($totals['total'] <= 0) {
                throw new DomainException('zero_total', 'The total must be more than ₹0.');
            }
            $inv->fill(array_merge(\Illuminate\Support\Arr::except($d, ['type', 'issue']), $totals, [
                'number' => ($d['number'] ?? '') !== '' ? $d['number'] : $inv->number,
                'party_gstin' => isset($d['party_gstin']) ? strtoupper($d['party_gstin']) : null,
            ]));
            if (!empty($d['issue']) && $inv->status === 'draft') {
                $inv->status = 'issued';
            }
            $inv->save();
            Ledger::recalc($inv);
        });
        PlatformAudit::record('accounts.' . $inv->type . '_updated', $inv, ['number' => $inv->number, 'total' => $inv->total]);
        return $this->invoice($inv->id);
    }

    public function issueInvoice(int $id)
    {
        $inv = Invoice::query()->findOrFail($id);
        if ($inv->status !== 'draft') {
            throw new DomainException('not_draft', 'Only a draft can be issued.');
        }
        $inv->forceFill(['status' => 'issued'])->save();
        PlatformAudit::record('accounts.' . $inv->type . '_issued', $inv, ['number' => $inv->number]);
        return $this->invoice($inv->id);
    }

    public function cancelInvoice(Request $r, int $id)
    {
        $d = $r->validate(['reason' => 'required|string|max:255']);
        $inv = Invoice::query()->findOrFail($id);
        if ($inv->amount_paid > 0) {
            throw new DomainException('has_payments', 'This ' . $inv->type . ' has payments recorded. Delete them first, or leave it as paid.');
        }
        $inv->forceFill(['status' => 'cancelled', 'notes' => trim(($inv->notes ? $inv->notes . "\n" : '') . 'Cancelled: ' . $d['reason'])])->save();
        PlatformAudit::record('accounts.' . $inv->type . '_cancelled', $inv, ['number' => $inv->number, 'reason' => $d['reason']]);
        return $this->invoice($inv->id);
    }

    public function deleteInvoice(int $id)
    {
        $inv = Invoice::query()->findOrFail($id);
        if ($inv->status !== 'draft') {
            throw new DomainException('not_draft', 'Only a draft can be deleted. An issued ' . $inv->type . ' is kept in the books: cancel it instead.');
        }
        $inv->delete();
        PlatformAudit::record('accounts.' . $inv->type . '_deleted', null, ['id' => $id, 'number' => $inv->number]);
        return response()->json(['ok' => true]);
    }

    public function payInvoice(Request $r, int $id)
    {
        $d = $r->validate(['amount' => 'required|numeric|min:0.01|max:9999999999', 'date' => 'required|date', 'method' => ['required', Rule::in(Transaction::METHODS)],
            'account_id' => ['nullable', 'integer', Rule::exists('acc_accounts', 'id')], 'reference' => 'nullable|string|max:100']);
        $inv = Invoice::query()->findOrFail($id);
        $t = Ledger::pay($inv, $d, $this->actor());
        PlatformAudit::record('accounts.' . $inv->type . '_payment', $inv, ['number' => $inv->number, 'amount' => $t->amount]);
        return $this->invoice($inv->id);
    }

    /** A clean, printable tax invoice / bill (the browser's "Save as PDF" makes the PDF). */
    public function printInvoice(int $id)
    {
        $inv = Invoice::query()->findOrFail($id);
        $s = fn ($k) => PlatformSettings::get($k);
        return response()->view('accounts.invoice', ['inv' => $inv, 'seller' => ['name' => $s('seller_name'), 'address' => $s('seller_address'), 'gstin' => $s('seller_gstin'),
            'email' => $s('seller_email'), 'phone' => $s('seller_phone')], 'payments' => Transaction::query()->where('source', $inv->type)->where('source_id', $inv->id)->orderBy('txn_date')->get()]);
    }

    public function exportInvoices(Request $r)
    {
        $format = $r->validate(['format' => 'required|in:xlsx,csv,pdf'])['format'];
        $rows = $this->invoiceQuery($r)->orderBy('issue_date')->orderBy('id')->get();
        $type = $r->query('type');
        PlatformAudit::record('accounts.exported', null, ['what' => 'invoices', 'format' => $format, 'rows' => $rows->count()]);
        return Exporter::download($format, $type === 'bill' ? 'rovexy-bills' : ($type === 'invoice' ? 'rovexy-invoices' : 'rovexy-invoices-and-bills'),
            $type === 'bill' ? 'Bills' : ($type === 'invoice' ? 'Invoices' : 'Invoices & bills'),
            ['Number', 'Type', 'Party', 'GSTIN', 'Issued', 'Due', 'Status', 'Taxable', 'GST', 'Total', 'Paid', 'Balance'],
            $rows->map(fn (Invoice $i) => [$i->number, ucfirst($i->type), $i->party_name, $i->party_gstin, $i->issue_date?->format('d-m-Y'), $i->due_date?->format('d-m-Y'),
                ucfirst($i->display_status), $i->subtotal, $i->tax_amount, $i->total, $i->amount_paid, $i->balance])->all(),
            ['Total', '', '', '', '', '', '', self::money($rows->sum('subtotal')), self::money($rows->sum('tax_amount')), self::money($rows->sum('total')),
                self::money($rows->sum('amount_paid')), self::money($rows->sum('balance'))]);
    }

    // ── Payments tracking: what is still to come in / go out, what is late, what was settled ──
    public function payments(Request $r)
    {
        $view = $r->validate(['view' => 'required|in:pending,overdue,completed'])['view'];
        $today = Ledger::today();
        if ($view === 'completed') {
            [$from, $to] = $this->range($r);
            $rows = Transaction::query()->with('account:id,name')->where('status', 'completed')->whereIn('source', ['invoice', 'bill', 'order'])
                ->whereBetween('txn_date', [$from, $to])->orderByDesc('txn_date')->orderByDesc('id')->get()
                ->map(fn (Transaction $t) => ['key' => "t{$t->id}", 'direction' => $t->kind === 'income' ? 'in' : 'out', 'source' => $t->source, 'ref' => $t->reference ?: $t->description,
                    'party' => $t->party, 'date' => $t->txn_date?->toDateString(), 'amount' => $t->amount, 'balance' => 0.0, 'status' => 'completed', 'method' => $t->method, 'account' => $t->account?->name,
                    'invoice_id' => in_array($t->source, ['invoice', 'bill'], true) ? $t->source_id : null]);
            return response()->json(['rows' => $rows->values(), 'from' => $from, 'to' => $to,
                'total_in' => self::money($rows->where('direction', 'in')->sum('amount')), 'total_out' => self::money($rows->where('direction', 'out')->sum('amount'))]);
        }
        $inv = Invoice::query()->whereIn('status', ['issued', 'partial'])
            ->when($view === 'overdue', fn ($w) => $w->whereNotNull('due_date')->where('due_date', '<', $today))
            ->when($view === 'pending', fn ($w) => $w->where(fn ($x) => $x->whereNull('due_date')->orWhere('due_date', '>=', $today)))
            ->orderBy('due_date')->get()
            ->map(fn (Invoice $i) => ['key' => "i{$i->id}", 'direction' => $i->type === 'bill' ? 'out' : 'in', 'source' => $i->type, 'ref' => $i->number, 'party' => $i->party_name,
                'date' => $i->due_date?->toDateString(), 'amount' => $i->total, 'balance' => $i->balance, 'status' => $i->display_status,
                'days' => $i->due_date ? (int) Carbon::parse($today)->diffInDays($i->due_date, false) : null, 'invoice_id' => $i->id]);
        $rows = $inv;
        if ($view === 'pending') {
            // unpaid subscription orders (sales side) and ledger entries still marked pending
            $orders = SalesOrder::query()->where('status', 'created')->orderBy('created_at')->get()
                ->map(fn (SalesOrder $o) => ['key' => "o{$o->id}", 'direction' => 'in', 'source' => 'order', 'ref' => $o->number, 'party' => $o->buyer_company,
                    'date' => $o->created_at?->setTimezone(Ledger::TZ)->toDateString(), 'amount' => $o->total, 'balance' => $o->total, 'status' => 'unpaid order', 'pay_url' => $o->payUrl()]);
            $pending = Transaction::query()->where('status', 'pending')->orderBy('txn_date')->get()
                ->map(fn (Transaction $t) => ['key' => "t{$t->id}", 'direction' => $t->kind === 'income' ? 'in' : 'out', 'source' => 'entry', 'ref' => $t->reference ?: $t->description,
                    'party' => $t->party, 'date' => $t->txn_date?->toDateString(), 'amount' => $t->amount, 'balance' => $t->amount, 'status' => 'pending', 'transaction_id' => $t->id]);
            $rows = $inv->concat($orders)->concat($pending);
        }
        return response()->json(['rows' => $rows->values(), 'total_in' => self::money($rows->where('direction', 'in')->sum('balance')),
            'total_out' => self::money($rows->where('direction', 'out')->sum('balance'))]);
    }

    // ── Reports ──
    private function report(Request $r): array
    {
        $period = $r->validate(['period' => 'required|in:daily,monthly,yearly'])['period'];
        [$from, $to] = $this->range($r, $period === 'daily' ? 'month' : 'fy');
        if ($period === 'daily' && Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw new DomainException('range_too_long', 'A daily report can cover up to one year. Use the monthly report for longer.');
        }
        $len = $period === 'daily' ? 10 : 7;
        $rows = Transaction::query()->where('status', 'completed')->whereBetween('txn_date', [$from, $to])
            ->selectRaw("substr(txn_date, 1, {$len}) AS k, kind, SUM(amount) AS total, SUM(tax_amount) AS tax")->groupBy('k', 'kind')->get()->groupBy('k');
        $label = fn (string $k) => $period === 'daily' ? Carbon::parse($k)->format('d M Y') : Carbon::parse($k . '-01')->format('M Y');
        $line = fn (string $k, $g) => ['key' => $k, 'label' => $label($k), 'income' => self::money($g?->firstWhere('kind', 'income')?->total ?? 0),
            'expense' => self::money($g?->firstWhere('kind', 'expense')?->total ?? 0), 'gst_in' => self::money($g?->firstWhere('kind', 'income')?->tax ?? 0),
            'gst_out' => self::money($g?->firstWhere('kind', 'expense')?->tax ?? 0)];
        if ($period === 'yearly') {
            // Indian financial years (Apr–Mar): fold the months into FY buckets
            $fy = [];
            foreach ($rows as $k => $g) {
                $d = Carbon::parse($k . '-01');
                $y = $d->month >= 4 ? $d->year : $d->year - 1;
                $l = $line($k, $g);
                $key = "FY {$y}-" . substr((string) ($y + 1), 2);
                foreach (['income', 'expense', 'gst_in', 'gst_out'] as $f) {
                    $fy[$key][$f] = self::money(($fy[$key][$f] ?? 0) + $l[$f]);
                }
            }
            ksort($fy);
            $lines = collect($fy)->map(fn ($v, $k) => ['key' => $k, 'label' => $k] + $v)->values();
        } else {
            // every day / month in the range, including empty ones
            $keys = [];
            for ($c = Carbon::parse($from)->startOf($period === 'daily' ? 'day' : 'month'); $c->lte(Carbon::parse($to)); $period === 'daily' ? $c->addDay() : $c->addMonthNoOverflow()) {
                $keys[] = $c->format($period === 'daily' ? 'Y-m-d' : 'Y-m');
            }
            $lines = collect($keys)->map(fn ($k) => $line($k, $rows[$k] ?? null));
        }
        $lines = $lines->map(fn ($l) => $l + ['net' => self::money($l['income'] - $l['expense'])]);
        $byCat = Transaction::query()->from('acc_transactions as t')->leftJoin('acc_categories as c', 'c.id', '=', 't.category_id')->where('t.status', 'completed')
            ->whereBetween('t.txn_date', [$from, $to])->groupBy('t.kind', 'c.name')->selectRaw("t.kind, COALESCE(c.name, 'Uncategorised') AS name, SUM(t.amount) AS total, COUNT(*) AS n")
            ->orderBy('t.kind')->orderByDesc('total')->get()->map(fn ($x) => ['kind' => $x->kind, 'name' => $x->name, 'total' => self::money($x->total), 'count' => (int) $x->n]);
        $income = self::money($lines->sum('income'));
        $expense = self::money($lines->sum('expense'));
        return ['period' => $period, 'from' => $from, 'to' => $to, 'rows' => $lines->values(), 'by_category' => $byCat,
            'totals' => ['income' => $income, 'expense' => $expense, 'net' => self::money($income - $expense), 'gst_in' => self::money($lines->sum('gst_in')), 'gst_out' => self::money($lines->sum('gst_out'))],
            'outstanding' => ['receivable' => self::money(Invoice::query()->where('type', 'invoice')->whereIn('status', ['issued', 'partial'])->get()->sum('balance')),
                'payable' => self::money(Invoice::query()->where('type', 'bill')->whereIn('status', ['issued', 'partial'])->get()->sum('balance'))]];
    }

    public function reports(Request $r)
    {
        return response()->json($this->report($r));
    }

    public function exportReport(Request $r)
    {
        $format = $r->validate(['format' => 'required|in:xlsx,csv,pdf'])['format'];
        $rep = $this->report($r);
        $t = $rep['totals'];
        PlatformAudit::record('accounts.exported', null, ['what' => 'report_' . $rep['period'], 'format' => $format]);
        return Exporter::download($format, 'rovexy-' . $rep['period'] . '-report', ucfirst($rep['period']) . ' income & expense report',
            [$rep['period'] === 'daily' ? 'Day' : ($rep['period'] === 'monthly' ? 'Month' : 'Financial year'), 'Income', 'Expense', 'Net', 'GST collected', 'GST paid'],
            $rep['rows']->map(fn ($l) => [$l['label'], $l['income'], $l['expense'], $l['net'], $l['gst_in'], $l['gst_out']])->all(),
            ['Total', $t['income'], $t['expense'], $t['net'], $t['gst_in'], $t['gst_out']],
            ['Period' => Carbon::parse($rep['from'])->format('d M Y') . ' – ' . Carbon::parse($rep['to'])->format('d M Y'),
                'Receivable now' => '₹' . number_format($rep['outstanding']['receivable'], 2), 'Payable now' => '₹' . number_format($rep['outstanding']['payable'], 2)]);
    }

    // ── Subscription tax invoices (sales side, read-only here) ──
    public function salesInvoices(Request $r)
    {
        [$from, $to] = $this->range($r, 'fy');
        $q = trim((string) $r->query('q'));
        $base = SalesOrder::query()->with('plan:id,name')->where('status', 'paid')
            ->whereBetween('paid_at', [Carbon::parse($from, Ledger::TZ)->startOfDay()->utc(), Carbon::parse($to, Ledger::TZ)->endOfDay()->utc()])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('invoice_number', 'like', "%{$q}%")->orWhere('buyer_company', 'like', "%{$q}%")->orWhere('number', 'like', "%{$q}%")));
        $sum = (clone $base)->selectRaw('SUM(amount) a, SUM(cgst + sgst + igst) g, SUM(total) t')->first();
        return response()->json($base->orderByDesc('paid_at')->paginate(25)->through(fn (SalesOrder $o) => $o->only(['id', 'number', 'invoice_number', 'buyer_company', 'buyer_gstin',
            'state_code', 'amount', 'cgst', 'sgst', 'igst', 'total', 'payment_method', 'payment_ref', 'paid_at']) + ['plan' => $o->plan?->name,
            'invoice_url' => url("/pay/{$o->number}/{$o->token()}/invoice")])->toArray() + ['from' => $from, 'to' => $to,
            'totals' => ['taxable' => self::money($sum->a ?? 0), 'gst' => self::money($sum->g ?? 0), 'total' => self::money($sum->t ?? 0)]]);
    }

    /** Lists for the forms (categories, accounts, methods) in one call. */
    public function lookups()
    {
        return response()->json(['categories' => Category::query()->where('active', true)->orderBy('kind')->orderBy('name')->get(['id', 'kind', 'name']),
            'accounts' => Account::query()->where('status', 'active')->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'type', 'is_default']),
            'methods' => Transaction::METHODS, 'account_types' => Account::TYPES, 'gst_rate' => (float) PlatformSettings::get('gst_rate'), 'today' => Ledger::today(),
            'fy_start' => Ledger::fyStart()->toDateString()]);
    }
}
