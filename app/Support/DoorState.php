<?php

namespace App\Support;

use App\Models\Device;
use App\Models\DoorLog;
use Illuminate\Support\Facades\Cache;

/**
 * Live state of the door, as last reported by the ESP32 main controller.
 *
 * The dashboard used to show the status of the most recent door_logs row. That table is
 * an event history and mixes unrelated things - lock state, door position, a 'locked'
 * row for every denied access attempt, and an 'unlocked' row the moment an admin clicks
 * Unlock (before the lock has done anything, even if it's offline). So the "current"
 * status regularly contradicted the real door: a denial while the door stood open showed
 * LOCKED, a queued remote unlock showed UNLOCKED, and any event lost while the lock was
 * offline stuck forever.
 *
 * Now the lock sends a snapshot {door_closed, lock, ajar_alarm} with every door event and
 * every 30s heartbeat; that snapshot is the source of truth. door_logs stays the history.
 */
final class DoorState
{
    private static function key(int $deviceId): string
    {
        return "trilock_door_state_{$deviceId}";
    }

    public static function get(int $deviceId): ?array
    {
        return Cache::get(self::key($deviceId));
    }

    /**
     * Store the device's own report. Fields it didn't send are left as they were.
     *
     * @param array{door_closed?: bool, lock?: string, ajar_alarm?: bool} $snapshot
     */
    public static function record(int $deviceId, array $snapshot): void
    {
        $state = self::get($deviceId) ?? ['door' => 'closed', 'lock' => 'locked', 'alarm' => false];

        if (array_key_exists('door_closed', $snapshot)) {
            $state['door'] = $snapshot['door_closed'] ? 'closed' : 'open';
        }
        if (isset($snapshot['lock'])) {
            $state['lock'] = $snapshot['lock'];
        }
        if (array_key_exists('ajar_alarm', $snapshot)) {
            $state['alarm'] = (bool) $snapshot['ajar_alarm'];
        }
        $state['at'] = now()->toIso8601String();

        Cache::forever(self::key($deviceId), $state);
        Cache::forget('trilock_dashboard_status');
    }

    /**
     * Apply a door event (from firmware that doesn't send a snapshot yet, or ahead of it).
     */
    public static function applyEvent(int $deviceId, string $event): void
    {
        match ($event) {
            'opened'    => self::record($deviceId, ['door_closed' => false]),
            'closed'    => self::record($deviceId, ['door_closed' => true, 'ajar_alarm' => false]),
            'left_open' => self::record($deviceId, ['door_closed' => false, 'ajar_alarm' => true]),
            'locked'    => self::record($deviceId, ['lock' => 'locked']),
            'unlocked'  => self::record($deviceId, ['lock' => 'unlocked']),
            default     => null,
        };
    }

    /**
     * The server knows the lock is about to unlock (access granted, or it handed over a
     * remote unlock) - reflect it now rather than waiting up to 30s for the next heartbeat.
     * The device's next report confirms or corrects it.
     */
    public static function markUnlocked(?int $deviceId): void
    {
        if ($deviceId) {
            self::applyEvent($deviceId, 'unlocked');
        }
    }

    public static function markLocked(?int $deviceId): void
    {
        if ($deviceId) {
            self::applyEvent($deviceId, 'locked');
        }
    }

    /**
     * Single status for the dashboard widgets: left_open | opened | unlocked | locked.
     * Uses the most recently seen main controller.
     */
    public static function current(): string
    {
        $deviceId = Device::where('device_type', 'esp32_main')->orderByDesc('last_seen')->value('id');
        $state = $deviceId ? self::get($deviceId) : null;

        if (!$state) {
            // Nothing reported since the server started tracking live state (e.g. older
            // firmware) - fall back to the last event.
            return DoorLog::latest('id')->value('status') ?? 'locked';
        }

        return match (true) {
            $state['alarm']              => 'left_open',
            $state['door'] === 'open'    => 'opened',
            $state['lock'] === 'unlocked' => 'unlocked',
            default                      => 'locked',
        };
    }
}
