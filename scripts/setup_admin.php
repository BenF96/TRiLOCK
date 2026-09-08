<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$u = App\Models\User::first();
if (!$u) {
    $u = new App\Models\User();
    $u->name = 'Ben Famaranglas';
    $u->email = 'benfamaranglas69@gmail.com';
    $u->role = 'admin';
    $u->status = 'active';
}
$u->password = Illuminate\Support\Facades\Hash::make('password123');
$u->save();
echo "User ready: " . $u->email . " / password123\n";
