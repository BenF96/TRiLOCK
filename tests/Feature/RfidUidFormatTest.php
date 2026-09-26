<?php

namespace Tests\Feature;

use App\Models\RfidCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RfidUidFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\TriLockTestSeeder::class);
        $this->withHeader('X-Device-Token', config('trilock.device_api_key'));
    }

    public function test_normalize_uid()
    {
        $this->assertSame('A4 B9 12 FD', RfidCard::normalizeUid('a4b912fd'));
        $this->assertSame('A4 B9 12 FD', RfidCard::normalizeUid('A4:B9:12:FD'));
        $this->assertSame('A4 B9 12 FD', RfidCard::normalizeUid(' a4 b9 12 fd '));
        $this->assertSame('04 A1 B2 C3 D4 E5 F6', RfidCard::normalizeUid('04A1B2C3D4E5F6')); // 7-byte UID
        $this->assertSame('ABC', RfidCard::normalizeUid('abc')); // not whole bytes - left as-is
    }

    public function test_card_saved_without_spaces_is_recognised_at_the_door()
    {
        $user = User::where('role', '!=', 'admin')->first() ?? User::first();
        RfidCard::create(['user_id' => $user->id, 'card_uid' => 'de:ad:be:ef', 'status' => 'active']);

        $this->assertDatabaseHas('rfid_cards', ['card_uid' => 'DE AD BE EF']);

        // The lock sends the spaced form
        $this->postJson('/api/access', ['auth_method' => 'rfid', 'card_uid' => 'DE AD BE EF'])
            ->assertJson(['status' => 'granted']);
    }

    public function test_same_card_in_another_spelling_is_rejected_as_duplicate()
    {
        $existing = RfidCard::first(); // seeded as A1B2C3D4 -> "A1 B2 C3 D4"
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->post('/rfid', [
            'card_uid' => strtolower(str_replace(' ', ':', $existing->card_uid)),
        ])->assertSessionHasErrors('card_uid');
    }

    public function test_migration_normalises_existing_rows_and_skips_collisions()
    {
        $user = User::first();
        $id1 = DB::table('rfid_cards')->insertGetId(['user_id' => $user->id, 'card_uid' => 'aa:bb:cc:dd', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $id2 = DB::table('rfid_cards')->insertGetId(['user_id' => $user->id, 'card_uid' => 'AABBCCDD', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_09_27_000001_normalize_rfid_card_uids.php'))->up();

        $this->assertSame('AA BB CC DD', DB::table('rfid_cards')->where('id', $id1)->value('card_uid'));
        $this->assertSame('AABBCCDD', DB::table('rfid_cards')->where('id', $id2)->value('card_uid')); // collision - left for an admin
    }
}
