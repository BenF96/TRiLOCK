<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Fingerprint;
use App\Models\User;

class FingerprintController extends Controller
{
    public function index(Request $request)
    {
        $query = Fingerprint::with('user');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('fingerprint_id', 'like', "%{$search}%")
                ->orWhereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('employee_student_id', 'like', "%{$search}%");
                });
        }

        $fingerprints = $query->orderBy('fingerprint_id', 'asc')->paginate(10);
        $usersWithoutFingerprint = User::whereDoesntHave('activeFingerprint')->get();

        return view('fingerprints.index', compact('fingerprints', 'usersWithoutFingerprint'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'fingerprint_id' => 'required|integer|min:1|max:127|unique:fingerprints,fingerprint_id',
            'status' => 'nullable|in:active,disabled',
        ]);

        Fingerprint::create([
            'user_id' => $validated['user_id'],
            'fingerprint_id' => $validated['fingerprint_id'],
            'status' => $validated['status'] ?? 'active',
            'registered_at' => now(),
        ]);

        return back()->with('success', 'Fingerprint enrolled successfully.');
    }

    public function toggleStatus(Fingerprint $fingerprint)
    {
        $fingerprint->status = ($fingerprint->status === 'active') ? 'disabled' : 'active';
        $fingerprint->save();

        UserController::notifyDevicesToSync();

        return back()->with('success', "Fingerprint #{$fingerprint->fingerprint_id} status changed to {$fingerprint->status}.");
    }

    public function destroy(Fingerprint $fingerprint)
    {
        $fpId = $fingerprint->fingerprint_id;
        $fingerprint->delete();

        UserController::notifyDevicesToSync([$fpId]);

        return back()->with('success', "Fingerprint #{$fpId} deleted.");
    }
}
