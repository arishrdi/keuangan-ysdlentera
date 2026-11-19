<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pengeluaran')] class extends Component {}; ?>

<div>
    <livewire:finance.entry-list kind="pengeluaran" />
</div>
