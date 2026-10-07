<?php
// Temporary packaging helper. The generated ZIP substitutes clean versions of
// this file and routes/web.php so no export helper is included in the package.
$base = '/app';
$zipPath = '/tmp/latestgovtjobs-automation-full-clean.zip';
@unlink($zipPath);
$zip = new ZipArchive();

if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $excludedPrefixes = [
        'vendor/', 'node_modules/', '.git/', 'storage/logs/', 'storage/framework/',
        'bootstrap/cache/', 'database/database.sqlite', 'storage/app/',
    ];
    $excludedFiles = ['.env'];

    $cleanBootstrap = <<<'CLEAN_BOOTSTRAP'
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
CLEAN_BOOTSTRAP;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($it as $file) {
        if (!$file->isFile()) continue;

        $absolute = $file->getPathname();
        $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($base))), '/');

        if (in_array($relative, $excludedFiles, true)) continue;

        $skip = false;
        foreach ($excludedPrefixes as $prefix) {
            if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;

        if ($relative === 'bin/bootstrap-production.php') {
            $zip->addFromString($relative, $cleanBootstrap);
            continue;
        }

        if ($relative === 'routes/web.php') {
            $routeText = file_get_contents($absolute);
            $routeText = preg_replace(
                "#\nRoute::get\('/internal/export-source/pkg-20261007-8f4e2c91a7'.*?\n\}\);\s*$#s",
                "\n",
                $routeText
            );
            $zip->addFromString($relative, $routeText);
            continue;
        }

        $zip->addFile($absolute, $relative);
    }

    $zip->close();

    $b64 = base64_encode(file_get_contents($zipPath));
    $chunks = str_split($b64, 6000);

    echo "CLEANZIP_BEGIN:".strlen($b64).":".count($chunks)."\n";
    foreach ($chunks as $i => $chunk) {
        echo "CLEANZIP:".$i.":".$chunk."\n";
    }
    echo "CLEANZIP_END\n";
}

?>
use Illuminate\Contracts\Console\Kernel;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';

$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Production intentionally starts with no automatic source catalog.
// Sources are added by the user from the web UI.
// Restarts must never repopulate or overwrite user data.
echo "Production bootstrap complete. Automatic source seeding is disabled.\n";
