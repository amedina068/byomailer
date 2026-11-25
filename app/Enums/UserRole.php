<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
    case Invited = 'invited';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Editor => 'Editor',
            self::Viewer => 'Viewer',
            self::Invited => 'Invited',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases()
        );
    }

    /**
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            array_filter(
                self::cases(),
                static fn (self $role): bool => $role !== self::Invited
            )
        );
    }
}
