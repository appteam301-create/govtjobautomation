<?php
namespace App\Services\Crawling;
use App\Services\Security\UrlGuard;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpFetcher
{
    public function __construct(private UrlGuard $guard){}

    public function fetch(string $url): FetchResult
    {
        $this->guard->assertSafe($url);
        $response=Http::withHeaders([
            'User-Agent'=>config('govjobs.crawler.user_agent'),
            'Accept'=>'text/html,application/xhtml+xml,application/pdf;q=0.9,*/*;q=0.8',
            'Accept-Language'=>'en-IN,en;q=0.9',
            'Cache-Control'=>'no-cache',
        ])->timeout(config('govjobs.crawler.timeout'))
          ->retry(2,500,throw:false)
          ->withOptions(['allow_redirects'=>['max'=>6,'strict'=>true,'track_redirects'=>true]])
          ->get($url);

        if(!$response->successful()) throw new RuntimeException('HTTP '.$response->status());
        $body=$response->body();
        if(strlen($body)>config('govjobs.crawler.max_bytes')) throw new RuntimeException('Response exceeds size limit.');
        $effective=(string)($response->effectiveUri()??$url);
        $this->guard->assertSafe($effective);
        return new FetchResult($effective,$response->status(),strtolower((string)$response->header('Content-Type')),$body,$response->headers());
    }
}
