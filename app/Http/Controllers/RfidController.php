<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\RfidCard;
use App\Models\User;
use App\Models\AccessLog;

class RfidController extends Controller
{
    public function index(Request $request)
    {
        $query = RfidCard::with('user');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('card_uid', 'like', "%{$search}%")
                ->orWhereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('employee_student_id', 'like', "%{$search}%");
                });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $cards = $query->orderBy('created_at', 'desc')->paginate(10);
        $usersWithoutCard = User::whereDoesntHave('activeRfidCard')->get();

        return view('rfid.index', compact('cards', 'usersWithoutCard'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'card_uid' => 'required|string|max:50|unique:rfid_cards,card_uid',
            'user_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:active,disabled,lost',
        ]);

        $card = RfidCard::create([
            'card_uid' => strtoupper($validated['card_uid']),
            'user_id' => $validated['user_id'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'assigned_at' => $validated['user_id'] ? now() : null,
        ]);

        return back()->with('success', 'RFID Card registered successfully.');
    }

    public function assign(Request $request, RfidCard $card)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        // Disable existing active cards for this user if replacing lost card
        RfidCard::where('user_id', $validated['user_id'])
            ->where('id', '!=', $card->id)
            ->update(['status' => 'disabled']);

        $card->update([
            'user_id' => $validated['user_id'],
            'status' => 'active',
            'assigned_at' => now(),
        ]);

        return back()->with('success', 'RFID Card assigned to user.');
    }

    public function toggleStatus(RfidCard $card)
    {
        $card->status = ($card->status === 'active') ? 'disabled' : 'active';
        $card->save();

        return back()->with('success', "Card {$card->card_uid} status updated to {$card->status}.");
    }

    public function markLost(RfidCard $card)
    {
        $card->status = 'lost';
        $card->save();

        UserController::notifyDevicesToSync();

        return back()->with('warning', "Card {$card->card_uid} reported lost and deactivated.");
    }

    public function destroy(RfidCard $card)
    {
        $card->delete();

        UserController::notifyDevicesToSync();

        return back()->with('success', 'RFID Card record deleted.');
    }
}
