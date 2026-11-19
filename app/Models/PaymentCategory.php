<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Database\Factories\PaymentCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One of the payment categories: Infak, DPP, Bangunan, Seragam, SPP.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property BillingCycle $billing_cycle
 * @property bool $is_installable
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'billing_cycle', 'is_installable', 'sort_order'])]
class PaymentCategory extends Model
{
    /** @use HasFactory<PaymentCategoryFactory> */
    use HasFactory;

    public const string INFAK = 'INFAK';

    public const string DPP = 'DPP';

    public const string BUILDING = 'BANGUNAN';

    public const string UNIFORM = 'SERAGAM';

    public const string SPP = 'SPP';

    /**
     * Minimum amount of a single installment, in rupiah.
     */
    public const int MINIMUM_INSTALLMENT = 10_000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'is_installable' => 'boolean',
        ];
    }

    /**
     * Levels with their activation flag for this category.
     *
     * @return BelongsToMany<Level, $this>
     */
    public function levels(): BelongsToMany
    {
        return $this->belongsToMany(Level::class)->withPivot('is_active')->withTimestamps();
    }

    /**
     * @return HasMany<FeeRate, $this>
     */
    public function feeRates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order');
    }

    /**
     * Categories switched on for the given level.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActiveFor(Builder $query, Level $level): void
    {
        $query->whereHas('levels', fn (Builder $query) => $query
            ->whereKey($level->id)
            ->where('level_payment_category.is_active', true));
    }

    public function isActiveFor(Level $level): bool
    {
        return static::query()->whereKey($this->id)->activeFor($level)->exists();
    }

    public function isUniform(): bool
    {
        return $this->billing_cycle === BillingCycle::Itemized;
    }
}
