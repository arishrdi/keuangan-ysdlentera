<?php

namespace App\Models;

use App\Enums\PaymentChannelType;
use Database\Factories\PaymentChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bank account or static QRIS code where guardians can send payments for a level.
 *
 * @property int $id
 * @property int $level_id
 * @property int|null $cash_account_id
 * @property PaymentChannelType $type
 * @property string $name
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $account_holder
 * @property string|null $qris_image_path
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['level_id', 'cash_account_id', 'type', 'name', 'bank_name', 'account_number', 'account_holder', 'qris_image_path', 'is_active'])]
class PaymentChannel extends Model
{
    /** @use HasFactory<PaymentChannelFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentChannelType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * The cash account that receives money sent to this channel.
     *
     * @return BelongsTo<CashAccount, $this>
     */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
