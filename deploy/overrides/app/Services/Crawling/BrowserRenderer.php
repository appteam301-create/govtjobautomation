<?php
namespace App\Services\Crawling;

use App\Services\Security\UrlGuard;
use RuntimeException;

class BrowserRenderer
{
    public function __construct(private UrlGuard $guard){}

    public function render(string $url): string
    {
        $this->guard->assertSafe($url);
        $binary=(string)(env('CHROME_BINARY','/usr/bin/chromium'));
        if(!is_file($binary)) throw new RuntimeException('Chromium binary not found.');

        $seconds=max(8,min(45,(int)env('CRAWLER_BROWSER_TIMEOUT',20)));
        $ua=(string)env('CRAWLER_USER_AGENT','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36');
        $cmd='timeout '.$seconds.'s '.escapeshellarg($binary)
            .' --headless --no-sandbox --disable-gpu --disable-dev-shm-usage'
            .' --disable-blink-features=AutomationControlled --user-agent='.escapeshellarg($ua)
            .' --disable-background-networking --disable-extensions --disable-sync'
            .' --hide-scrollbars --virtual-time-budget=6000 --dump-dom '
            .escapeshellarg($url).' 2>/dev/null';

        $html=shell_exec($cmd);
        if(!is_string($html) || trim($html)==='') throw new RuntimeException('Browser render failed or timed out.');
        if(strlen($html)>(int)config('govjobs.crawler.max_bytes',15728640)) throw new RuntimeException('Rendered response exceeds size limit.');
        return $html;
    }
}
