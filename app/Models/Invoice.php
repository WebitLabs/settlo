<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Invoice extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Money columns, status, sent/paid timestamps, invoice_number and the QR /
     * creditor snapshot are all set by server-side services (never mass
     * assigned) so a client cannot forge amounts, redirect the creditor IBAN,
     * or jump the status.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_id', 'issue_date', 'due_date', 'language',
        'reference', 'notes', 'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'issue_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'creditor_vat_registered' => 'boolean',
        ];
    }

    /** @return BelongsTo<BusinessEntity, $this> */
    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(BusinessEntity::class);
    }

    /**
     * Includes trashed clients, so an invoice keeps showing its client's name
     * after the client is moved to the trash.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** @return HasMany<InvoiceLineItem, $this> */
    public function lineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<InvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /** @param Builder<Invoice> $query */
    public function scopeCountsAsRevenue(Builder $query): void
    {
        $query->whereIn('status', [
            InvoiceStatus::Sent->value,
            InvoiceStatus::Paid->value,
            InvoiceStatus::Overdue->value,
        ]);
    }

    /**
     * Issued, unpaid and past its due date. "Past" means strictly before
     * today: an invoice that is due today is not overdue yet. This is the one
     * definition of overdue — the query scope, the dashboards and the nightly
     * `markOverdue` sweep all use the same rule.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereIn('status', array_column(InvoiceStatus::unpaidIssued(), 'value'))
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', Carbon::today()->toDateString());
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, InvoiceStatus::unpaidIssued(), true)
            && $this->due_date !== null
            && $this->due_date->startOfDay()->lt(Carbon::today());
    }
}
