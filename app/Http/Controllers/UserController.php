<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\RfidCard;
use App\Models\Fingerprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['activeRfidCard', 'activeFingerprint']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_student_id', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->orderBy('created_at', 'desc')->paginate(10);

        return view('users.index', compact('users'));
    }

    public function create()
    {
        return view('users.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_student_id' => 'required|string|max:100|unique:users,employee_student_id',
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'role' => 'required|in:admin,student,faculty,lab_tech',
            'status' => 'required|in:active,inactive',
            'photo' => 'nullable|image|max:2048',
            'card_uid' => 'nullable|string|max:50|unique:rfid_cards,card_uid',
            'fingerprint_id' => 'nullable|integer|min:1|max:127|unique:fingerprints,fingerprint_id',
        ]);

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('photos');
        }

        $user = User::create([
            'employee_student_id' => $validated['employee_student_id'],
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'],
            'last_name' => $validated['last_name'],
            'name' => "{$validated['first_name']} {$validated['last_name']}",
            'email' => $validated['email'],
            'password' => Hash::make($request->input('password') ?: \Illuminate\Support\Str::random(32)),
            'role' => $validated['role'],
            'status' => $validated['status'],
            'photo' => $photoPath,
        ]);

        if (!empty($validated['card_uid'])) {
            RfidCard::create([
                'user_id' => $user->id,
                'card_uid' => strtoupper($validated['card_uid']),
                'status' => 'active',
                'assigned_at' => now(),
            ]);
        }

        if (!empty($validated['fingerprint_id'])) {
            Fingerprint::create([
                'user_id' => $user->id,
                'fingerprint_id' => $validated['fingerprint_id'],
                'status' => 'active',
                'registered_at' => now(),
            ]);
        }

        return redirect()->route('users.index')->with('success', "User {$user->full_name} registered successfully.");
    }

    public function edit(User $user)
    {
        $user->load(['activeRfidCard', 'activeFingerprint']);
        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'employee_student_id' => 'required|string|max:100|unique:users,employee_student_id,' . $user->id,
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'role' => 'required|in:admin,student,faculty,lab_tech',
            'status' => 'required|in:active,inactive',
            'photo' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('photo')) {
            if ($user->photo && Storage::exists($user->photo)) {
                Storage::delete($user->photo);
            }
            $user->photo = $request->file('photo')->store('photos');
        }

        $user->employee_student_id = $validated['employee_student_id'];
        $user->first_name = $validated['first_name'];
        $user->middle_name = $validated['middle_name'];
        $user->last_name = $validated['last_name'];
        $user->name = "{$validated['first_name']} {$validated['last_name']}";
        $user->email = $validated['email'];
        $user->role = $validated['role'];
        $user->status = $validated['status'];

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return redirect()->route('users.index')->with('success', "User details updated successfully.");
    }

    public function toggleStatus(User $user)
    {
        $user->status = ($user->status === 'active') ? 'inactive' : 'active';
        $user->save();

        return back()->with('success', "Status for {$user->full_name} set to {$user->status}.");
    }

    public function destroy(User $user)
    {
        $name = $user->full_name;
        $user->delete();

        return redirect()->route('users.index')->with('success', "User {$name} deleted.");
    }
}
