<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $username
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Role $role
 * @property int|null $level_id
 * @property bool $is_active
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'phone', 'address', 'password', 'role', 'level_id', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Students linked to this guardian account.
     *
     * @return BelongsToMany<Student, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'guardian_student')
            ->withPivot('relationship')
            ->withTimestamps();
    }

    /**
     * The level this staff member is locked to; null means every level.
     *
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * Whether this user may work with data of the given level.
     */
    public function canAccessLevel(Level|int|null $level): bool
    {
        if ($this->level_id === null) {
            return true;
        }

        $levelId = $level instanceof Level ? $level->id : $level;

        return $levelId === $this->level_id;
    }

    /**
     * Users allowed to work with the given level: those locked to it and those covering every level.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithAccessToLevel(Builder $query, Level|int $level): void
    {
        $levelId = $level instanceof Level ? $level->id : $level;

        $query->where(fn (Builder $query) => $query->whereNull('level_id')->orWhere('level_id', $levelId));
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    /**
     * Find an active user by username, email or phone number.
     */
    public static function findForLogin(string $login): ?self
    {
        $login = Str::lower(trim($login));

        return static::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('username', $login)
                ->orWhere('email', $login)
                ->orWhere('phone', static::normalizePhone($login)))
            ->first();
    }

    /**
     * Normalize an Indonesian phone number to the 08xx format.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
