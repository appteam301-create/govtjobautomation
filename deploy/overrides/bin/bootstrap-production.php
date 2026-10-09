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


// One-time backfill for an existing candidate created before discovered_from_url was stored.
try {
    $candidate = \App\Models\JobCandidate::find(191);
    if ($candidate) {
        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];
        if (empty($data['discovered_from_url'])) {
            $data['discovered_from_url'] = 'https://www.py.gov.in/women-child-applications-are-invited-eligible-and-interested-candidates-post-chairpersonmember-under';
            $candidate->extracted_data = $data;
            $candidate->save();
        }
    }
} catch (\Throwable $e) {
    report($e);
}
