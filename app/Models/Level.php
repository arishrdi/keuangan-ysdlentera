<?php

namespace App\Models;

use Database\Factories\LevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * An education level (jenjang) of the foundation: PAUD, SD or SMP.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 * @property string|null $address
 * @property string|null $phone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'sort_order', 'address', 'phone'])]
class Level extends Model
{
    /** @use HasFactory<LevelFactory> */
    use HasFactory;

    /**
     * Session key holding the level the user is currently working in.
     */
    public const string SESSION_KEY = 'active_level';

    /**
     * Session value meaning "all levels".
     */
    public const string ALL = 'all';

    /**
     * The level the user is currently working in, or null when viewing all levels.
     */
    public static function active(): ?self
    {
        $assigned = static::assignedToCurrentUser();

        if ($assigned !== null) {
            return $assigned;
        }

        $selected = Session::get(self::SESSION_KEY);

        if (! is_int($selected)) {
            return null;
        }

        return static::query()->find($selected);
    }

    /**
     * Whether the user has chosen a level (or "all levels") in this session.
     */
    public static function hasActiveSelection(): bool
    {
        return Session::has(self::SESSION_KEY) || Auth::user()?->level_id !== null;
    }

    /**
     * The level the signed-in staff member is locked to, if any.
     */
    public static function assignedToCurrentUser(): ?self
    {
        $levelId = Auth::user()?->level_id;

        return $levelId === null ? null : static::query()->find($levelId);
    }

    /**
     * Switch the session to the given level, or to all levels when null.
     */
    public static function activate(?self $level): void
    {
        Session::put(self::SESSION_KEY, $level->id ?? self::ALL);
    }

    /**
     * Payment categories with their activation flag for this level.
     *
     * @return BelongsToMany<PaymentCategory, $this>
     */
    public function paymentCategories(): BelongsToMany
    {
        return $this->belongsToMany(PaymentCategory::class)->withPivot('is_active')->withTimestamps();
    }

    /**
     * @return HasMany<PaymentChannel, $this>
     */
    public function paymentChannels(): HasMany
    {
        return $this->hasMany(PaymentChannel::class);
    }

    /**
     * @return HasMany<Classroom, $this>
     */
    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    /**
     * @return HasMany<Student, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order');
    }
}
