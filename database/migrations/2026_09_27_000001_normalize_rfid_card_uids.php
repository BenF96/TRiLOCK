<?php

use App\Models\RfidCard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rewrite stored RFID UIDs into the canonical "A4 B9 12 FD" form the ESP32 sends (see
 * RfidCard::normalizeUid()). Matching is an exact string compare, so a card saved as
 * "A4B912FD" or "a4:b9:12:fd" could never be recognised at the door.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Oldest first, so on a collision the original record keeps the canonical UID
        $existing = DB::table('rfid_cards')->orderBy('id')->pluck('card_uid', 'id');
        $taken = array_flip($existing->all());

        foreach ($existing as $id => $uid) {
            $normalized = RfidCard::normalizeUid($uid);
            if ($normalized === $uid) {
                continue;
            }

            // Two rows are the same physical card in different spellings - leave this one
            // for an admin to resolve rather than violate the unique index.
            if (isset($taken[$normalized])) {
                Log::warning("normalize_rfid_card_uids: card #{$id} ({$uid}) duplicates an existing {$normalized} - left unchanged");
                continue;
            }

            DB::table('rfid_cards')->where('id', $id)->update(['card_uid' => $normalized]);
            unset($taken[$uid]);
            $taken[$normalized] = $id;
        }
    }

    public function down(): void
    {
        // Normalised values are still valid UIDs; nothing to undo.
    }
};
