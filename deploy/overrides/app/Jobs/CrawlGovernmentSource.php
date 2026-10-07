<?php
namespace App\Jobs;

use App\Enums\CrawlerMode;
use App\Models\CrawlRun;
use App\Models\CrawlerHealth;
use App\Models\DiscoveredDocument;
use App\Models\GovernmentSource;
use App\Services\Crawling\BrowserRenderer;
use App\Services\Crawling\ChangeDetector;
use App\Services\Crawling\ContentNormalizer;
use App\Services\Crawling\HtmlDiscovery;
use App\Services\Crawling\HttpFetcher;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CrawlGovernmentSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 210;

    public function __construct(public int $sourceId) {}

    public function handle(
        HttpFetcher $fetcher,
        HtmlDiscovery $discovery,
        ContentNormalizer $norm,
        ChangeDetector $changes,
        BrowserRenderer $browser
    ): void {
        if (is_file('/data/crawl-reset.flag')) return;

        $source = GovernmentSource::findOrFail($this->sourceId);
        $settings = is_array($source->settings) ? $source->settings : [];
        $listing = (bool)($settings['listing_is_recruitment'] ?? false);
        $mode = $source->crawler_mode;
        $forceBrowser = (bool)($settings['force_browser'] ?? false);

        $run = CrawlRun::create([
            'government_source_id' => $source->id,
            'started_at' => now(),
            'status' => 'running',
        ]);

        $httpError = null;
        $browserError = null;
        $httpStatus = null;
        $baseUrl = $source->recruitment_url;
        $items = [];

        try {
            try {
                $result = $fetcher->fetch($source->recruitment_url);
                $baseUrl = $result->url;
                $httpStatus = $result->status;
                $items = $discovery->discover($result->body, $baseUrl, ['listing_is_recruitment' => $listing]);
            } catch (Throwable $e) {
                $httpError = $e;
            }

            $needBrowser = $httpError !== null || $forceBrowser || $mode === CrawlerMode::Js || ($mode === CrawlerMode::Auto && count($items) < 6);

            if ($needBrowser) {
                try {
                    $rendered = $browser->render($source->recruitment_url);
                    $renderedItems = $discovery->discover($rendered, $source->recruitment_url, ['listing_is_recruitment' => $listing]);
                    if (count($renderedItems) > count($items)) {
                        $items = $renderedItems;
                        $baseUrl = $source->recruitment_url;
                    }
                    $httpStatus = $httpStatus ?? 200;
                } catch (Throwable $e) {
                    $browserError = $e;
                }
            }

            if (!$items && $httpError && $browserError) {
                throw new \RuntimeException('HTTP fetch failed: ' . $httpError->getMessage() . ' | Browser: ' . $browserError->getMessage());
            }
            if (!$items && $httpError && !$browserError) throw $httpError;

            $candidateItems = $this->filterCandidates($items, $listing);
            $maxItems = max(10, min(120, (int)($settings['max_items'] ?? 80)));
            $candidateItems = array_slice($candidateItems, 0, $maxItems);

            $newDocuments = 0;
            $documentErrors = 0;

            foreach ($candidateItems as $item) {
                if (is_file('/data/crawl-reset.flag')) return;

                try {
                    $normalized = $norm->url((string)$item['url']);
                    $fingerprint = $norm->hash(
                        $normalized . '|' .
                        mb_strtolower((string)($item['title'] ?? '')) . '|' .
                        mb_strtolower((string)($item['context'] ?? ''))
                    );

                    if (!$changes->isNew($source, $fingerprint, $item)) continue;

                    $doc = DiscoveredDocument::create([
                        'government_source_id' => $source->id,
                        'crawl_run_id' => $run->id,
                        'url' => $item['url'],
                        'normalized_url' => $normalized,
                        'title' => $item['title'] ?? '',
                        'document_type' => $item['type'] ?? 'html',
                        'content_hash' => $fingerprint,
                        'raw_text' => $item['context'] ?? ($item['title'] ?? ''),
                        'metadata' => [
                            'discovered_from' => $baseUrl,
                            'context' => $item['context'] ?? '',
                            'synthetic' => (bool)($item['synthetic'] ?? false),
                        ],
                        'discovered_at' => now(),
                    ]);

                    ProcessDiscoveredDocument::dispatchSync($doc->id);
                    $newDocuments++;
                } catch (Throwable $e) {
                    $documentErrors++;
                    report($e);
                }
            }

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'http_status' => $httpStatus,
                'metrics' => [
                    'discovered_links' => count($items),
                    'candidate_links' => count($candidateItems),
                    'new_documents' => $newDocuments,
                    'document_errors' => $documentErrors,
                    'http_error' => $httpError ? mb_substr($httpError->getMessage(), 0, 300) : null,
                    'browser_error' => $browserError ? mb_substr($browserError->getMessage(), 0, 300) : null,
                ],
            ]);

            $source->update([
                'last_crawled_at' => now(),
                'next_crawl_at' => now()->addMinutes($source->check_frequency_minutes),
            ]);

            CrawlerHealth::updateOrCreate(
                ['government_source_id' => $source->id],
                ['status' => 'healthy','consecutive_failures' => 0,'last_success_at' => now(),'last_error' => null]
            );
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $e->getMessage(),
                'metrics' => [
                    'discovered_links' => count($items),
                    'http_error' => $httpError ? mb_substr($httpError->getMessage(), 0, 300) : null,
                    'browser_error' => $browserError ? mb_substr($browserError->getMessage(), 0, 300) : null,
                ],
            ]);

            $health = CrawlerHealth::firstOrNew(['government_source_id' => $source->id]);
            $health->status = 'failed';
            $health->consecutive_failures = ($health->consecutive_failures ?? 0) + 1;
            $health->last_failure_at = now();
            $health->last_error = mb_substr($e->getMessage(), 0, 1000);
            $health->save();

            throw $e;
        }
    }

    private function filterCandidates(array $items, bool $listing): array
    {
        $bad = '/\\b(final result|provisional result|result of|document verification|admit card|hall ticket|answer key|merit list|shortlist|shortlisted|eligibility list|tender|procurement|auction|objection|cut[ -]?off|interview schedule|exam schedule|expression of interest|cancelled|cancellation|withdrawn)\\b/u';
        $positive = '/\\b(recruit(?:ment|ing)?|vacanc(?:y|ies)|applications? invited|apply online|walk[ -]?in|apprentice(?:ship)?|engagement|posts?|hiring|agniveer|constable|sub[ -]?inspector|resident|consultant|professor|director|registrar|librarian|accountant|scientist|research (?:associate|fellow|scientist)|project (?:staff|associate|assistant|scientist|officer)|staff nurse|engineer|officer|assistant|clerk|manager|executive|technician|stenographer|trainee|fellowship|tutor|demonstrator|driver|attendant|tradesman|multi[ -]?tasking staff|mts|data entry operator|deo)\\b/u';

        $scored = [];

        foreach ($items as $item) {
            $title = $this->clean((string)($item['title'] ?? ''));
            $context = $this->clean((string)($item['context'] ?? ''));
            $hay = mb_strtolower($title . ' ' . $context . ' ' . (string)($item['url'] ?? ''));

            if ($title === '' || mb_strlen($title) < 5 || mb_strlen($title) > 600) continue;
            if (preg_match($bad, $hay)) continue;

            $dateState = $listing ? $this->listingDateState($context . ' ' . $title) : 0;
            if ($dateState < 0) continue;

            $score = 0;
            if (preg_match($positive, $hay)) $score += 5;
            if (($item['type'] ?? '') === 'pdf') $score += 3;
            if (!empty($item['synthetic'])) $score += 2;
            if ($this->containsDate($context . ' ' . $title)) $score += 3;
            if ($dateState > 0) $score += 5;

            $path = strtolower((string)(parse_url((string)($item['url'] ?? ''), PHP_URL_PATH) ?? ''));
            if (preg_match('/(recruit|vacan|career|advert|notice|notification|job|employment)/', $path)) $score += 2;

            if ($listing ? ($score < 3) : ($score < 5)) continue;

            $item['_score'] = $score;
            $scored[] = $item;
        }

        usort($scored, fn(array $a, array $b) => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));

        $deduped = [];
        foreach ($scored as $item) {
            $key = strtolower(rtrim((string)($item['url'] ?? ''), '/'));
            if (!isset($deduped[$key]) || ($item['_score'] ?? 0) > ($deduped[$key]['_score'] ?? 0)) {
                $deduped[$key] = $item;
            }
        }

        return array_values(array_map(function (array $item) {
            unset($item['_score']);
            return $item;
        }, array_values($deduped)));
    }

    private function listingDateState(string $text): int
    {
        $dates = $this->extractDates($text);
        if (!$dates) return 0;

        $explicitDeadline = (bool)preg_match('/\\b(last date|end date|closing date|closing on|apply by|application closes?|applications close|upto|up to)\\b/i', $text);
        $today = Carbon::today(config('app.timezone', 'Asia/Kolkata'))->format('Y-m-d');
        $future = array_values(array_filter($dates, fn(string $d) => $d > $today));

        if ($future) return 1;
        if ($explicitDeadline || count($dates) >= 2) return -1;
        return 0;
    }

    private function extractDates(string $text): array
    {
        $pattern = '(\\d{4}[\\/\\-.]\\d{1,2}[\\/\\-.]\\d{1,2}|\\d{1,2}[\\/\\-.]\\d{1,2}[\\/\\-.]\\d{2,4}|\\d{1,2}(?:st|nd|rd|th)?\\s+[A-Za-z]{3,9}\\s+\\d{2,4}|[A-Za-z]{3,9}\\s+\\d{1,2}(?:st|nd|rd|th)?,?\\s+\\d{2,4})';
        $out = [];

        if (preg_match_all('/' . $pattern . '/iu', $text, $matches)) {
            foreach ($matches[1] as $value) {
                $date = $this->parseDate($value);
                if ($date) $out[$date] = true;
            }
        }

        return array_keys($out);
    }

    private function parseDate(string $value): ?string
    {
        $tz = config('app.timezone', 'Asia/Kolkata');
        $value = trim((string)preg_replace('/(\\d{1,2})(st|nd|rd|th)\\b/i', '$1', $value));

        foreach (['d/m/Y','d-m-Y','d.m.Y','d/m/y','d-m-y','d.m.y','Y/m/d','Y-m-d','Y.m.d','d F Y','d M Y','F d Y','M d Y','F d, Y','M d, Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value, $tz);
                if ($date !== false) return $date->format('Y-m-d');
            } catch (\Throwable $e) {}
        }

        try {
            return Carbon::parse($value, $tz)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function containsDate(string $text): bool
    {
        return (bool)preg_match('/\\b\\d{1,2}[\\/\\-.]\\d{1,2}[\\/\\-.]\\d{2,4}\\b|\\b\\d{4}[\\/\\-.]\\d{1,2}[\\/\\-.]\\d{1,2}\\b|\\b\\d{1,2}\\s+[A-Za-z]{3,9}\\s+\\d{2,4}\\b/i', $text);
    }

    private function clean(string $value): string
    {
        return trim((string)preg_replace('/\\s+/u', ' ', $value));
    }
}
