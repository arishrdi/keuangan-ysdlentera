<?php

use App\Models\User;

test('phone numbers are normalized to the 08 format', function (?string $input, ?string $expected) {
    expect(User::normalizePhone($input))->toBe($expected);
})->with([
    'local format' => ['081234567890', '081234567890'],
    'international with plus' => ['+62 812-3456-7890', '081234567890'],
    'international without plus' => ['6281234567890', '081234567890'],
    'with spaces and dashes' => ['0812 3456-7890', '081234567890'],
    'empty string' => ['', null],
    'null' => [null, null],
]);
