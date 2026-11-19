<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A line of an itemized (uniform) bill.
 *
 * @property int $id
 * @property int $bill_id
 * @property int|null $uniform_item_id
 * @property string $name
 * @property string|null $size
 * @property int $quantity
 * @property int $unit_price
 * @property int $subtotal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['bill_id', 'uniform_item_id', 'name', 'size', 'quantity', 'unit_price', 'subtotal'])]
class BillItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'subtotal' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
