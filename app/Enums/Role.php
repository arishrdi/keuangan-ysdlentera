<?php

namespace App\Enums;

enum Role: string
{
    case Foundation = 'yayasan';
    case Principal = 'kepala_sekolah';
    case Treasurer = 'bendahara';
    case Admin = 'admin';
    case Guardian = 'wali_murid';

    public function label(): string
    {
        return match ($this) {
            self::Foundation => 'Yayasan',
            self::Principal => 'Kepala Sekolah',
            self::Treasurer => 'Bendahara',
            self::Admin => 'Admin',
            self::Guardian => 'Wali Murid',
        };
    }

    /**
     * Whether the role belongs to school staff, who may access every level.
     */
    public function isStaff(): bool
    {
        return $this !== self::Guardian;
    }

    /**
     * Whether the role must choose a single active level right after logging in.
     */
    public function mustSelectLevelAfterLogin(): bool
    {
        return in_array($this, [self::Principal, self::Treasurer], true);
    }

    /**
     * @return array<int, self>
     */
    public static function staff(): array
    {
        return array_values(array_filter(self::cases(), fn (self $role): bool => $role->isStaff()));
    }
}
