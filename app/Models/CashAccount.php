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
 * An account without a level is shared by every level; each level still has its own balance in it.
 *
 * @property int $id
 * @property int|null $level_id
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
     * Accounts a level can use: its own and the shared ones.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAvailableTo(Builder $query, Level $level): void
    {
        $query->where(fn (Builder $query) => $query->whereBelongsTo($level)->orWhereNull('level_id'));
    }

    /**
     * Add the current balance as `balance`, limited to one level's share when a level is given.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithBalance(Builder $query, ?Level $level = null): void
    {
        $query->addSelect([
            'balance' => CashTransaction::query()
                ->selectRaw('coalesce(sum(case when direction = ? then amount else -amount end), 0)', [CashDirection::In->value])
                ->whereColumn('cash_transactions.cash_account_id', 'cash_accounts.id')
                ->when($level, fn ($query, Level $level) => $query->where('cash_transactions.level_id', $level->id)),
        ]);
    }

    /**
     * The balance after every mutation up to and including the given date,
     * limited to one level's share when a level is given.
     */
    public function balance(?CarbonInterface $until = null, ?Level $level = null): int
    {
        return (int) $this->transactions()
            ->when($until, fn ($query, CarbonInterface $until) => $query->whereDate('transacted_on', '<=', $until->toDateString()))
            ->when($level, fn ($query, Level $level) => $query->where('level_id', $level->id))
            ->selectRaw('coalesce(sum(case when direction = ? then amount else -amount end), 0) as balance', [CashDirection::In->value])
            ->value('balance');
    }

    public function isShared(): bool
    {
        return $this->level_id === null;
    }

    public function isAvailableTo(Level $level): bool
    {
        return $this->isShared() || $this->level_id === $level->id;
    }

    /**
     * Short owner label: the level code, or "Bersama" for a shared account.
     */
    public function ownerLabel(): string
    {
        return $this->level->code ?? 'Bersama';
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
