<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\AccessPin;
use Illuminate\Support\Facades\Hash;

class PinController extends Controller
{
    public function index()
    {
        $pins = AccessPin::with('user')->orderBy('created_at', 'desc')->get();
        $users = User::where('status', 'active')->orderBy('first_name')->get();

        return view('pins.index', compact('pins', 'users'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'  => 'required|exists:users,id',
            'pin_code' => 'required|string|digits_between:4,8',
        ]);

        // Each user can only have one active PIN at a time - deactivate any existing
        AccessPin::where('user_id', $validated['user_id'])
            ->where('status', 'active')
            ->update(['status' => 'disabled']);

        AccessPin::create([
            'user_id'      => $validated['user_id'],
            'pin_code'     => Hash::make($validated['pin_code']),
            'offline_hash' => hash('sha256', $validated['pin_code'].config('trilock.offline_pin_salt')),
            'status'       => 'active',
            'assigned_at'  => now(),
        ]);

        $user = User::find($validated['user_id']);
        return back()->with('success', "PIN assigned to {$user->full_name} successfully.");
    }

    public function toggleStatus(AccessPin $pin)
    {
        $pin->status = ($pin->status === 'active') ? 'disabled' : 'active';
        $pin->save();

        return back()->with('success', "PIN status set to {$pin->status}.");
    }

    public function destroy(AccessPin $pin)
    {
        $pin->delete();
        return back()->with('success', 'PIN removed successfully.');
    }
}
