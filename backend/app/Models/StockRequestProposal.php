<?php

namespace App\Models;

use App\Models\Scopes\BelongsToAgentScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockRequestProposal extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToAgentScope);
    }

    protected $fillable = [
        'agent_id', 'stock_request_id', 'status',
        'requested_by', 'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
        'received_by', 'received_at', 'receipt_note',
    ];

    protected function casts(): array
    {
        return ['agent_id' => 'integer', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockRequestProposalItem::class);
    }

    /** The Gudang → Koordinator handover evidence (Media, collection `fulfillment_handover_proof`). */
    public function handoverProof(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Media::class, 'mediable_id')
            ->where('mediable_type', self::class)->where('collection', 'fulfillment_handover_proof')->latestOfMany();
    }

    /**
     * What Gudang recorded as NOT handed over in this proposal (audit-trail rows written by
     * StockRequestProposalService::recordShortages), one entry per demand line.
     *
     * @return list<array{stock_request_item_id:int, reason:?string, handed_over_qty:int, short_qty:int}>
     */
    public function recordedShortages(): array
    {
        return ActivityLog::query()
            ->where('subject_type', StockRequestItem::class)->where('event', 'stock_request.fulfillment_shortage')
            ->where('properties->proposal_id', $this->id)->orderBy('id')->get()
            ->map(fn (ActivityLog $log) => [
                'stock_request_item_id' => (int) $log->subject_id,
                'reason' => $log->description,
                'handed_over_qty' => (int) ($log->properties['handed_over_qty'] ?? 0),
                'short_qty' => (int) ($log->properties['short_qty'] ?? 0),
            ])->all();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
