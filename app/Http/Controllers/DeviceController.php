<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $query = Device::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('device_name', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%")
                  ->orWhere('mac_address', 'like', "%{$search}%");
            });
        }

        if ($request->filled('device_type')) {
            $query->where('device_type', $request->device_type);
        }

        $devices = $query->orderBy('device_name')->paginate(10);

        return view('devices.index', compact('devices'));
    }

    public function create()
    {
        return view('devices.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_name' => 'required|string|max:255',
            'device_type' => 'required|in:esp32_main,esp32_cam,other',
            'ip_address'  => 'nullable|ip',
            'mac_address' => 'nullable|string|max:17|unique:devices,mac_address',
        ]);

        $device = Device::create([
            ...$validated,
            'status'    => 'offline',
            'api_token' => 'trilock_dev_'.Str::random(48),
        ]);

        return redirect()->route('devices.edit', $device)->with('success', "Device \"{$device->device_name}\" registered. Copy its ID and API token into the firmware config below before powering it on.");
    }

    public function edit(Device $device)
    {
        return view('devices.edit', compact('device'));
    }

    public function update(Request $request, Device $device)
    {
        $validated = $request->validate([
            'device_name' => 'required|string|max:255',
            'device_type' => 'required|in:esp32_main,esp32_cam,other',
            'ip_address'  => 'nullable|ip',
            'mac_address' => 'nullable|string|max:17|unique:devices,mac_address,'.$device->id,
        ]);

        $device->update($validated);

        return redirect()->route('devices.index')->with('success', "Device \"{$device->device_name}\" updated.");
    }

    public function regenerateToken(Device $device)
    {
        $device->update(['api_token' => 'trilock_dev_'.Str::random(48)]);

        return back()->with('warning', "New API token generated for \"{$device->device_name}\" — the old token stops working immediately, update the firmware before it reconnects.");
    }

    public function destroy(Device $device)
    {
        $name = $device->device_name;
        $device->delete();

        return redirect()->route('devices.index')->with('success', "Device \"{$name}\" removed.");
    }
}
