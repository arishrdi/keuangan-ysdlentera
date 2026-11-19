<?php

namespace App\Models;

use App\Enums\CashDirection;
use App\Enums\Gender;
use App\Enums\StudentStatus;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $level_id
 * @property string|null $nis
 * @property string|null $nisn
 * @property string $name
 * @property Gender $gender
 * @property string|null $birth_place
 * @property Carbon|null $birth_date
 * @property string|null $address
 * @property StudentStatus $status
 * @property Carbon|null $status_changed_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $savings_balance
 */
#[Fillable(['level_id', 'nis', 'nisn', 'name', 'gender', 'birth_place', 'birth_date', 'address', 'status', 'status_changed_on'])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'status' => StudentStatus::class,
            'birth_date' => 'date',
            'status_changed_on' => 'date',
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
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * The enrollment in the currently active academic year.
     *
     * @return HasOne<Enrollment, $this>
     */
    public function currentEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)
            ->whereHas('academicYear', fn (Builder $query) => $query->where('is_active', true));
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardian_student')
            ->withPivot('relationship')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<SavingsTransaction, $this>
     */
    public function savingsTransactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    /**
     * The current savings balance of the student.
     */
    public function savingsBalance(): int
    {
        return (int) $this->savingsTransactions()
            ->selectRaw('coalesce(sum(case when direction = ? then amount else -amount end), 0) as balance', [CashDirection::In->value])
            ->value('balance');
    }

    /**
     * Add the current savings balance as `savings_balance`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithSavingsBalance(Builder $query): void
    {
        $query->addSelect([
            'savings_balance' => SavingsTransaction::query()
                ->selectRaw('coalesce(sum(case when direction = ? then amount else -amount end), 0)', [CashDirection::In->value])
                ->whereColumn('savings_transactions.student_id', 'students.id'),
        ]);
    }

    /**
     * Only students whose savings balance is above zero.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithPositiveSavings(Builder $query): void
    {
        $query->whereRaw(
            '(select coalesce(sum(case when direction = ? then amount else -amount end), 0) from savings_transactions where savings_transactions.student_id = students.id) > 0',
            [CashDirection::In->value],
        );
    }

    /**
     * Refuse to let a student leave while savings are still held for them.
     *
     * @throws ValidationException
     */
    public function ensureSavingsAreSettled(string $errorKey = 'student_status'): void
    {
        $balance = $this->savingsBalance();

        if ($balance > 0) {
            throw ValidationException::withMessages([
                $errorKey => 'Saldo tabungan '.$this->name.' masih '.Number::rupiah($balance).'. Catat penarikan seluruh saldo terlebih dahulu.',
            ]);
        }
    }

    /**
     * The grade of the student's classroom in the given academic year, if placed.
     */
    public function gradeIn(AcademicYear $academicYear): ?int
    {
        return $this->enrollments()
            ->where('enrollments.academic_year_id', $academicYear->id)
            ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
            ->value('classrooms.grade');
    }

    /**
     * Whether the user is a guardian officially linked to this student.
     */
    public function isGuardedBy(User $user): bool
    {
        return $this->guardians()->whereKey($user->id)->exists();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', StudentStatus::Active);
    }

    /**
     * Place the student in a classroom for the classroom's academic year,
     * replacing any existing placement in that year.
     */
    public function enrollIn(Classroom $classroom): Enrollment
    {
        return $this->enrollments()->updateOrCreate(
            ['academic_year_id' => $classroom->academic_year_id],
            ['classroom_id' => $classroom->id],
        );
    }
}
