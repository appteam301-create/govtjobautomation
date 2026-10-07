<?php
use Illuminate\Contracts\Console\Kernel;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';

$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Production intentionally starts with no automatic source catalog.
// Sources are added by the user from the web UI.
// Restarts must never repopulate or overwrite user data.
echo "Production bootstrap complete. Automatic source seeding is disabled.\n";
