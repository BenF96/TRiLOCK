<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\Device;
use App\Services\DatabaseBackupService;

class SettingController extends Controller
{
    public function __construct(private readonly DatabaseBackupService $backups)
    {
    }

    public function index()
    {
        $settings = Setting::getSettings();
        $devices = Device::all();
        $backupFiles = $this->backups->listBackups();

        return view('settings.index', compact('settings', 'devices', 'backupFiles'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'door_unlock_duration' => 'required|integer|min:1|max:60',
            'lab_name' => 'required|string|max:255',
        ]);

        $settings = Setting::getSettings();

        $settings->update([
            'door_unlock_duration' => $validated['door_unlock_duration'],
            'auto_lock'            => $request->has('auto_lock'),
            'camera_enabled'       => $request->has('camera_enabled'),
            'capture_entry'        => $request->has('capture_entry'),
            'buzzer_enabled'       => $request->has('buzzer_enabled'),
            'lab_name'             => $validated['lab_name'],
        ]);

        return back()->with('success', 'TRILOCK System Settings updated successfully.');
    }

    public function backupDatabase()
    {
        $result = $this->backups->backup();

        if (!$result['ok']) {
            return back()->with('error', 'Database backup failed: ' . $result['error']);
        }

        return back()->with('success', "Database backup created: {$result['filename']}");
    }

    public function downloadBackup(string $filename)
    {
        $response = $this->backups->downloadResponse($filename);

        abort_if($response === null, 404);

        return $response;
    }

    public function restoreDatabase(Request $request)
    {
        $validated = $request->validate([
            'filename' => 'required|string',
        ]);

        $result = $this->backups->restore($validated['filename']);

        if (!$result['ok']) {
            $suffix = isset($result['safety_backup'])
                ? " A safety backup was taken first: {$result['safety_backup']}."
                : '';

            return back()->with('error', 'Database restore failed: ' . $result['error'] . $suffix);
        }

        return back()->with(
            'success',
            "Database restored from {$result['filename']}. A safety backup of the prior state was saved as {$result['safety_backup']}."
        );
    }
}
