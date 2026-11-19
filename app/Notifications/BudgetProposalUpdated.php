<?php

namespace App\Notifications;

use App\Models\BudgetProposal;
use Illuminate\Notifications\Notification;

class BudgetProposalUpdated extends Notification
{
    public function __construct(public BudgetProposal $proposal, public string $title, public string $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => route('budgets.show', $this->proposal),
        ];
    }
}
