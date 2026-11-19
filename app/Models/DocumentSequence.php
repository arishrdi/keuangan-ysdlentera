<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Running counters for document numbers such as KWT/SD/2026/0001, reset every year per level.
 *
 * @property int $id
 * @property string $type
 * @property int $level_id
 * @property int $year
 * @property int $last_number
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'level_id', 'year', 'last_number'])]
class DocumentSequence extends Model
{
    public const string RECEIPT = 'KWT';

    public const string CASH_TRANSACTION = 'TRX';

    public const string BUDGET = 'AGR';

    public const string SAVINGS = 'TAB';

    /**
     * Reserve the next number of a document type, e.g. "KWT/SD/2026/0001".
     */
    public static function next(string $type, Level $level, int $year): string
    {
        return DB::transaction(function () use ($type, $level, $year): string {
            $sequence = static::query()
                ->where(['type' => $type, 'level_id' => $level->id, 'year' => $year])
                ->lockForUpdate()
                ->first()
                ?? static::query()->create(['type' => $type, 'level_id' => $level->id, 'year' => $year, 'last_number' => 0]);

            $sequence->increment('last_number');

            return sprintf('%s/%s/%d/%04d', $type, $level->code, $year, $sequence->last_number);
        });
    }
}
