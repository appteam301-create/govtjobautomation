
<?php
// Temporary source export for packaging; removed after ZIP reconstruction.
$base = '/app';
$zipPath = '/tmp/latestgovtjobs-automation-full.zip';
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $excludedPrefixes = ['vendor/','node_modules/','.git/','storage/logs/','storage/framework/','bootstrap/cache/','database/database.sqlite'];
    $excludedFiles = ['.env'];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $absolute=$file->getPathname();
        $relative=ltrim(str_replace('\\','/',substr($absolute,strlen($base))),'/');
        if (in_array($relative,$excludedFiles,true)) continue;
        $skip=false;
        foreach($excludedPrefixes as $prefix){
            if($relative===rtrim($prefix,'/') || str_starts_with($relative,$prefix)){ $skip=true; break; }
        }
        if($skip) continue;
        $zip->addFile($absolute,$relative);
    }
    $zip->close();
    $b64=base64_encode(file_get_contents($zipPath));
    $chunks=str_split($b64,6000);
    echo "SOURCEZIP_BEGIN:".strlen($b64).":".count($chunks)."\n";
    foreach($chunks as $i=>$chunk){ echo "SOURCEZIP:".$i.":".$chunk."\n"; }
    echo "SOURCEZIP_END\n";
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
