<?php

namespace App\Models;

use Database\Factories\UniformItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $level_id
 * @property string $name
 * @property string|null $size
 * @property int $price
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['level_id', 'name', 'size', 'price', 'is_active'])]
class UniformItem extends Model
{
    /** @use HasFactory<UniformItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
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

    public function label(): string
    {
        return $this->name.($this->size ? ' ('.$this->size.')' : '');
    }
}
