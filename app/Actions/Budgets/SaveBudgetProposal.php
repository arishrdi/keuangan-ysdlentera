<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetStatus;
use App\Enums\TransactionCategoryType;
use App\Models\ActivityLog;
use App\Models\BudgetProposal;
use App\Models\Level;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a budget proposal while it is a draft or sent back for revision.
 */
class SaveBudgetProposal
{
    /**
     * @param  array<int, array{name: string, quantity: int, unit: string, unit_price: int}>  $items
     * @param  array<int, UploadedFile>  $newAttachments
     * @param  array<int, int>  $removedAttachmentIds
     */
    public function handle(
        User $actor,
        ?BudgetProposal $proposal,
        Level $level,
        TransactionCategory $category,
        string $title,
        string $reason,
        array $items,
        array $newAttachments = [],
        array $removedAttachmentIds = [],
    ): BudgetProposal {
        if ($proposal !== null && ! $proposal->status->isEditable()) {
            throw ValidationException::withMessages(['title' => 'Pengajuan yang sudah dikirim tidak bisa diubah.']);
        }

        if ($category->type !== TransactionCategoryType::Budget || ! $category->is_active) {
            throw ValidationException::withMessages(['categoryId' => 'Pilih kategori anggaran yang aktif.']);
        }

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Tambahkan minimal satu rincian kebutuhan.']);
        }

        return DB::transaction(function () use ($actor, $proposal, $level, $category, $title, $reason, $items, $newAttachments, $removedAttachmentIds): BudgetProposal {
            $proposal ??= new BudgetProposal(['status' => BudgetStatus::Draft, 'created_by' => $actor->id]);

            $proposal->fill([
                'level_id' => $proposal->exists ? $proposal->level_id : $level->id,
                'transaction_category_id' => $category->id,
                'title' => $title,
                'reason' => $reason,
            ])->save();

            $proposal->items()->delete();
            $proposal->items()->createMany(array_map(fn (array $item, int $index) => [
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $item['quantity'] * $item['unit_price'],
                'sort_order' => $index,
            ], $items, array_keys($items)));

            $proposal->refreshTotal();

            foreach ($proposal->attachments()->whereKey($removedAttachmentIds)->get() as $attachment) {
                Storage::disk('local')->delete($attachment->path);
                $attachment->delete();
            }

            foreach ($newAttachments as $file) {
                $proposal->attachments()->create([
                    'path' => $file->store('budget-attachments', 'local'),
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                ]);
            }

            ActivityLog::record('budget.saved', 'Menyimpan pengajuan anggaran '.$proposal->title, $proposal, ['total' => $proposal->total_amount]);

            return $proposal;
        });
    }
}
