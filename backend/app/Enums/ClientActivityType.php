<?php

namespace App\Enums;

// TASK-015 — Client Activity/Communication Log. Fixed vocabulary, same
// style as ClientStatus: a simple manual log-entry type an Agent picks
// themselves, not a business value like commission %/pricing (BR-7
// doesn't apply). Flag to the human if a 5th type is ever wanted — that
// would become an admin-configurable config table, not a bigger enum.
enum ClientActivityType: string
{
    case Call = 'call';
    case Chat = 'chat';
    case Meeting = 'meeting';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Chat => 'Chat',
            self::Meeting => 'Meeting',
            self::Other => 'Other',
        };
    }
}
