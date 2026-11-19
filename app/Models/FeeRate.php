<?php

namespace App\Models;

use Database\Factories\FeeRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The amount charged for a payment category in one level and academic year.
 * A null grade applies to every grade without its own rate.
 *
 * @property int $id
 * @property int $level_id
 * @property int $payment_category_id
 * @property int $academic_year_id
 * @property int|null $grade
 * @property int $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['level_id', 'payment_category_id', 'academic_year_id', 'grade', 'amount'])]
class FeeRate extends Model
{
    /** @use HasFactory<FeeRateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'amount' => 'integer',
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
     * @return BelongsTo<PaymentCategory, $this>
     */
    public function paymentCategory(): BelongsTo
    {
        return $this->belongsTo(PaymentCategory::class);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * The amount for a student in the given grade: the grade-specific rate first, then the level-wide rate.
     */
    public static function amountFor(PaymentCategory $category, Level $level, AcademicYear $academicYear, ?int $grade): ?int
    {
        return static::query()
            ->where('payment_category_id', $category->id)
            ->where('level_id', $level->id)
            ->where('academic_year_id', $academicYear->id)
            ->where(fn ($query) => $query->whereNull('grade')->when($grade !== null, fn ($query) => $query->orWhere('grade', $grade)))
            ->orderByRaw('grade is null')
            ->value('amount');
    }
}
