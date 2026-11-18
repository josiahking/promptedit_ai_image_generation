<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Generation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'estimated_customer_usd' => 'decimal:12',
            'minimum_customer_usd' => 'decimal:12',
            'maximum_customer_usd' => 'decimal:12',
            'provider_cost_usd' => 'decimal:12',
            'customer_cost_usd' => 'decimal:12',
            'usage' => 'array',
            'rates_snapshot' => 'array',
            'provider_created_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'image_url_expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }
}
