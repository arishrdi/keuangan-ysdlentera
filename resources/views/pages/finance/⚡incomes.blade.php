<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pemasukan')] class extends Component {}; ?>

<div>
    <livewire:finance.entry-list kind="pemasukan" />
</div>
