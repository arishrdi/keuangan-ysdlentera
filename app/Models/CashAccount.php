<?php

namespace App\Models;

use App\Enums\CashAccountType;
use App\Enums\CashDirection;
use Carbon\CarbonInterface;
use Database\Factories\CashAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A place where a level keeps its money: the cash box or a bank account.
 *
 * @property int $id
 * @property int $level_id
 * @property CashAccountType $type
 * @property string $name
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $balance
 */
#[Fillable(['level_id', 'type', 'name', 'bank_name', 'account_number', 'is_active'])]
class CashAccount extends Model
{
    /** @use HasFactory<CashAccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CashAccountType::class,
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
     * @return HasMany<CashTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Add the current balance as `balance`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithBalance(Builder $query): void
    {
        $query->addSelect([
            'balance' => CashTransaction::query()
                ->selectRaw('coalesce(sum(case when direction = ? then amount else -amount end), 0)', [CashDirection::In->value])
                ->whereColumn('cash_transactions.cash_account_id', 'cash_accounts.id'),
        ]);
    }

    /**
     * The balance after every mutation up to and including the given date.
     */
    public function balance(?CarbonInterface $until = null): int
    {
        return (int) $this->transactions()
            ->when($until, fn ($query, CarbonInterface $until) => $query->whereDate('transacted_on', '<=', $until->toDateString()))
            ->selectRaw('coalesce(sum(case when direction = ? then amount else -amount end), 0) as balance', [CashDirection::In->value])
            ->value('balance');
    }

    /**
     * The cash box of a level, created on first use.
     */
    public static function cashBoxFor(Level $level): self
    {
        return static::query()
            ->where('level_id', $level->id)
            ->where('type', CashAccountType::Cash)
            ->orderBy('id')
            ->first()
            ?? static::query()->create([
                'level_id' => $level->id,
                'type' => CashAccountType::Cash,
                'name' => 'Kas Tunai '.$level->name,
            ]);
    }
}
