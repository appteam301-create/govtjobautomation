<?php
namespace App\Jobs;

use App\Models\DiscoveredDocument;
use App\Models\GovernmentSource;
use App\Models\JobCandidate;
use App\Services\Extraction\JobDetailsEnricher;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProcessDiscoveredDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 150;

    public function __construct(public int $documentId) {}

    public function handle(JobDetailsEnricher $enricher): void
    {
        if (is_file('/data/crawl-reset.flag')) return;

        $doc = DiscoveredDocument::find($this->documentId);
        if (!$doc) return;

        $source = GovernmentSource::find($doc->government_source_id);
        if (!$source) return;

        $settings = is_array($source->settings) ? $source->settings : [];
        $listingTrusted = (bool)($settings['listing_is_recruitment'] ?? false);

        $title = $this->cleanWhitespace((string)$doc->title);
        $metadata = is_array($doc->metadata) ? $doc->metadata : [];
        $context = $this->cleanWhitespace((string)($metadata['context'] ?? $doc->raw_text ?? ''));
        $seedText = trim($title . "\n" . $context);

        if ($this->isClearlyNotVacancy($seedText)) return;

        // Trusted recruitment pages still contain navigation/subscription/archive links.
        // Require a vacancy/application signal in the actual row before following it.
        if ($listingTrusted && !$this->trustedListingLooksLikeVacancy($title, $context)) return;

        // Fast expiry check from the recruitment-list row before downloading a detail page/PDF.
        $seedDeadline = $this->findLastDate($seedText, $context, $listingTrusted);
        if ($seedDeadline && !$this->isFutureLastDate($seedDeadline)) {
            $this->deleteExistingCandidate((string)$doc->url, (int)$doc->id);
            return;
        }
        if ($listingTrusted && !$seedDeadline && $this->listingContextIsClearlyExpired($context)) {
            $this->deleteExistingCandidate((string)$doc->url, (int)$doc->id);
            return;
        }

        $synthetic = (bool)($metadata['synthetic'] ?? false);
        $raw = $seedText;

        // If the trusted recruitment listing already gives a future deadline, do not
        // re-download every detail page/PDF. Save from the listing row and leave
        // unavailable fields pending. Deep-fetch only when a deadline still has to be found.
        if (!$seedDeadline && !$synthetic) {
            $detailText = $this->extractText((string)$doc->url, (string)($doc->document_type ?? 'html'), '');
            if ($detailText !== '') $raw = trim($seedText . "\n" . $detailText);
        }

        $raw = mb_substr($raw, 0, 120000);

        if ($this->isClearlyNotVacancy(mb_substr($raw, 0, 5000))) return;

        $jobTitle = $this->deriveJobTitle($title, $context);
        if (!$listingTrusted && !$this->looksLikeVacancy($jobTitle . ' ' . mb_substr($raw, 0, 1200))) return;

        $lastDate = $this->findLastDate($raw, $context, $listingTrusted) ?: $seedDeadline;
        if (!$this->isFutureLastDate($lastDate)) {
            $this->deleteExistingCandidate((string)$doc->url, (int)$doc->id);
            return;
        }

        $vacancies = $this->findVacancyCount($raw);
        $applyUrl = $this->findApplyUrl($raw);
        $logoUrl = $this->resolveOfficialLogo($source, $metadata, $doc);

        $model = new JobCandidate();
        $columns = Schema::getColumnListing($model->getTable());

        $payload = [
            'government_source_id' => $source->id,
            'discovered_document_id' => $doc->id,
            'job_title' => $jobTitle ?: 'Recruitment Notification',
            'organization' => $source->organization,
            'total_vacancies' => $vacancies,
            'application_last_date' => $lastDate,
            'application_url' => $applyUrl,
            'logo' => $logoUrl,
            'notification_pdf_url' => ($doc->document_type ?? '') === 'pdf' ? (string)$doc->url : null,
            'official_source_url' => (string)$doc->url,
            'confidence_score' => $this->confidence($jobTitle, $raw, $vacancies, $lastDate),
            'status' => 'needs_review',
            'extracted_data' => [
                'raw_text' => $raw,
                'job_title' => $jobTitle,
                'organization' => $source->organization,
                'total_vacancies' => $vacancies,
                'application_end_date' => $lastDate,
                'apply_url' => $applyUrl,
                'logo' => $logoUrl,
                'official_notification_url' => (string)$doc->url,
                'source_name' => $source->name,
                'source_url' => $source->recruitment_url,
                'discovered_from_url' => (string)($metadata['discovered_from'] ?? $source->recruitment_url),
                'source_context' => $context,
            ],
        ];

        $payload = array_intersect_key($payload, array_flip($columns));

        $existing = null;
        if (in_array('official_source_url', $columns, true)) {
            $existing = JobCandidate::query()->where('official_source_url', (string)$doc->url)->first();
        }
        if (!$existing && in_array('discovered_document_id', $columns, true)) {
            $existing = JobCandidate::query()->where('discovered_document_id', $doc->id)->first();
        }

        if (is_file('/data/crawl-reset.flag')) return;

        $candidate = $existing ?: new JobCandidate();
        $candidate->forceFill($payload);
        $candidate->save();

        $enricher->enrich($candidate);
    }

    private function extractText(string $url, string $type, string $fallback): string
    {
        $fetchUrl = preg_replace('/#.*$/', '', $url) ?: $url;

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('govjobs.crawler.user_agent'),
                'Accept' => 'text/html,application/pdf,*/*;q=0.8',
                'Accept-Language' => 'en-IN,en;q=0.9',
            ])->timeout(8)->get($fetchUrl);

            if (!$response->successful()) return $fallback;

            $contentType = strtolower((string)$response->header('Content-Type'));
            $path = strtolower((string)(parse_url($fetchUrl, PHP_URL_PATH) ?: ''));
            if ($type === 'pdf' || str_contains($contentType, 'pdf') || str_ends_with($path, '.pdf')) {
                return $this->extractPdfText($response->body(), $fallback);
            }

            return $this->htmlToText($response->body()) ?: $fallback;
        } catch (Throwable $e) {
            return $fallback;
        }
    }

    private function extractPdfText(string $bytes, string $fallback): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'govjob_');
        if (!$tmp) return $fallback;

        file_put_contents($tmp, $bytes);
        $txt = $tmp . '.txt';

        shell_exec('timeout 10s pdftotext -layout ' . escapeshellarg($tmp) . ' ' . escapeshellarg($txt) . ' 2>/dev/null');
        $text = is_file($txt) ? trim((string)file_get_contents($txt)) : '';

        if (mb_strlen($text) < 120) {
            $dir = $tmp . '_ocr';
            @mkdir($dir);
            $prefix = $dir . '/page';
            shell_exec('timeout 12s pdftoppm -f 1 -l 2 -jpeg -r 140 ' . escapeshellarg($tmp) . ' ' . escapeshellarg($prefix) . ' >/dev/null 2>&1');

            $ocrParts = [];
            foreach (glob($prefix . '-*.jpg') ?: [] as $image) {
                $outBase = $image . '_ocr';
                shell_exec('timeout 8s tesseract ' . escapeshellarg($image) . ' ' . escapeshellarg($outBase) . ' -l eng 2>/dev/null');
                $ocrFile = $outBase . '.txt';
                if (is_file($ocrFile)) {
                    $ocrParts[] = (string)file_get_contents($ocrFile);
                    @unlink($ocrFile);
                }
                @unlink($image);
            }
            @rmdir($dir);

            $ocr = trim(implode("\n", $ocrParts));
            if (mb_strlen($ocr) > mb_strlen($text)) $text = $ocr;
        }

        @unlink($tmp);
        @unlink($txt);
        return $text !== '' ? $text : $fallback;
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\\1>#is', ' ', $html);
        $html = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\\b[^>]*>#i', "\n", (string)$html);
        $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\\R{3,}/u', "\n\n", (string)$text);
        return trim((string)$text);
    }

    private function resolveOfficialLogo(GovernmentSource $source, array $metadata, DiscoveredDocument $doc): ?string
    {
        $settings = is_array($source->settings) ? $source->settings : [];
        $cached = trim((string)($settings['official_logo_url'] ?? ''));
        $officialHost = $this->hostOf((string)$source->recruitment_url);

        if ($cached !== '' && $this->isOfficialAssetUrl($cached, $officialHost)) {
            return $cached;
        }

        $pageCandidates = array_values(array_unique(array_filter([
            (string)($metadata['discovered_from'] ?? ''),
            (string)$source->recruitment_url,
            (($doc->document_type ?? '') === 'html' ? (string)$doc->url : ''),
        ], fn($url) => filter_var($url, FILTER_VALIDATE_URL) !== false)));

        foreach ($pageCandidates as $pageUrl) {
            $pageHost = $this->hostOf($pageUrl);
            if ($officialHost !== '' && !$this->sameOfficialHost($pageHost, $officialHost)) continue;

            try {
                $response = Http::withHeaders([
                    'User-Agent' => config('govjobs.crawler.user_agent'),
                    'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
                    'Accept-Language' => 'en-IN,en;q=0.9',
                ])->timeout(8)->get($pageUrl);

                if (!$response->successful()) continue;

                $contentType = strtolower((string)$response->header('Content-Type'));
                if ($contentType !== '' && !str_contains($contentType, 'html')) continue;

                $logo = $this->extractLogoFromHtml($response->body(), $pageUrl, $officialHost ?: $pageHost);
                if (!$logo) continue;

                // Cache one official logo per government source/site so repeated jobs
                // from the same official website do not re-fetch the logo every time.
                $settings['official_logo_url'] = $logo;
                $settings['official_logo_source_page'] = $pageUrl;
                $settings['official_logo_checked_at'] = now()->toIso8601String();
                $source->settings = $settings;
                $source->save();

                return $logo;
            } catch (Throwable $e) {
                // Logo extraction must never break job discovery.
            }
        }

        return null;
    }

    private function extractLogoFromHtml(string $html, string $pageUrl, string $officialHost): ?string
    {
        $candidates = [];

        // Highest priority: images explicitly identified as logo/brand/emblem in header/site markup.
        if (preg_match_all('#<img\b[^>]*>#i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                $src = $this->htmlAttribute($tag, 'src');
                if (!$src) $src = $this->htmlAttribute($tag, 'data-src');
                if (!$src) continue;

                $hint = strtolower(implode(' ', array_filter([
                    $this->htmlAttribute($tag, 'class'),
                    $this->htmlAttribute($tag, 'id'),
                    $this->htmlAttribute($tag, 'alt'),
                    $this->htmlAttribute($tag, 'title'),
                    $src,
                ])));

                $score = 0;
                if (preg_match('/\b(logo|site-logo|brand|emblem|crest|identity)\b/i', $hint)) $score += 100;
                if (preg_match('/\b(header|branding|navbar|masthead)\b/i', $hint)) $score += 25;
                if (preg_match('/\b(banner|slider|gallery|thumbnail|avatar|photo)\b/i', $hint)) $score -= 80;

                if ($score > 0) $candidates[] = [$score, $src];
            }
        }

        // Metadata often exposes the site's identity image. Keep below explicit logo markup.
        foreach ([
            ['pattern'=>'#<meta\b[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>#i','score'=>55],
            ['pattern'=>'#<meta\b[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>#i','score'=>55],
        ] as $rule) {
            if (preg_match($rule['pattern'], $html, $m)) $candidates[] = [$rule['score'], $m[1]];
        }

        // Favicon/site icon is a valid fallback when no larger official logo is exposed.
        if (preg_match_all('#<link\b[^>]*>#i', $html, $links)) {
            foreach ($links[0] as $tag) {
                $rel = strtolower((string)$this->htmlAttribute($tag, 'rel'));
                if (!str_contains($rel, 'icon')) continue;
                $href = $this->htmlAttribute($tag, 'href');
                if ($href) $candidates[] = [25, $href];
            }
        }

        usort($candidates, fn($a,$b) => $b[0] <=> $a[0]);

        foreach ($candidates as [, $rawUrl]) {
            $absolute = $this->absoluteUrl($rawUrl, $pageUrl);
            if (!$absolute) continue;
            if (!$this->isOfficialAssetUrl($absolute, $officialHost)) continue;
            return $absolute;
        }

        return null;
    }

    private function htmlAttribute(string $tag, string $name): ?string
    {
        if (preg_match('/\b'.preg_quote($name,'/').'\s*=\s*(["\'])(.*?)\1/is', $tag, $m)) {
            $value = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return $value !== '' ? $value : null;
        }
        return null;
    }

    private function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'javascript:')) return null;
        if (filter_var($url, FILTER_VALIDATE_URL)) return $url;

        $scheme = (string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https');
        $host = (string)parse_url($baseUrl, PHP_URL_HOST);
        if ($host === '') return null;

        if (str_starts_with($url, '//')) return $scheme.':'.$url;
        if (str_starts_with($url, '/')) return $scheme.'://'.$host.$url;

        $path = (string)(parse_url($baseUrl, PHP_URL_PATH) ?: '/');
        $dir = rtrim(str_replace('\\','/',dirname($path)), '/');
        return $scheme.'://'.$host.($dir !== '' ? $dir : '').'/'.$url;
    }

    private function hostOf(string $url): string
    {
        return strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    }

    private function sameOfficialHost(string $a, string $b): bool
    {
        $a = preg_replace('/^www\./i','',strtolower($a));
        $b = preg_replace('/^www\./i','',strtolower($b));
        if ($a === '' || $b === '') return false;
        return $a === $b || str_ends_with($a, '.'.$b) || str_ends_with($b, '.'.$a);
    }

    private function isOfficialAssetUrl(string $url, string $officialHost): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http','https'], true)) return false;

        $assetHost = $this->hostOf($url);
        return $officialHost !== '' && $this->sameOfficialHost($assetHost, $officialHost);
    }

    private function findVacancyCount(string $text): ?int
    {
        foreach ([
            '/(?:total\\s+(?:number\\s+of\\s+)?vacanc(?:y|ies)|total\\s+posts?|no\\.?\\s*of\\s*posts?|number\\s+of\\s+posts?|vacanc(?:y|ies))[\\s:\\-–]*(\\d{1,6})/i',
            '/(\\d{1,6})\\s+(?:vacanc(?:y|ies)|posts?)\\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $value = (int)str_replace(',', '', $m[1]);
                if ($value > 0 && $value < 100000) return $value;
            }
        }
        return null;
    }

    private function findLastDate(string $raw, string $context, bool $listingTrusted): ?string
    {
        $labels = [
            'last date for receipt of applications','last date for receipt of application',
            'last date of receipt of application','last date for submission of online application',
            'last date for submission of application','last date of submission of application',
            'closing date of online application','closing date for online application',
            'closing date of application','closing date for application','online registration closes',
            'application end date','last date to apply','last date for application',
            'last date of application','last date','closing date','closing on','apply by',
            'applications close','application closes'
        ];

        $datePattern = $this->datePattern();

        foreach ($labels as $label) {
            $pattern = '/' . preg_quote($label, '/') . '.{0,100}?' . $datePattern . '/isu';
            if (preg_match($pattern, $raw, $m)) {
                $parsed = $this->parseGovDate($m[1]);
                if ($parsed) return $parsed;
            }
        }

        // Some official recruitment tables expose explicit Start Date / End Date columns.
        // Accept an End Date only when the surrounding row clearly identifies it as a deadline.
        $row = $context !== '' ? $context : mb_substr($raw, 0, 3500);
        if (preg_match('/(?:end date|last date|closing date)[^\\d]{0,40}' . $datePattern . '/isu', $row, $m)) {
            $parsed = $this->parseGovDate($m[1]);
            if ($parsed) return $parsed;
        }

        // Trusted recruitment listings commonly show Opening Date + Last Date as
        // table columns without repeating the column labels inside each row. When the
        // row is already known to be a vacancy and contains at least two valid dates,
        // the latest row date is the application deadline.
        if ($listingTrusted) {
            $dates = $this->extractAllDates($context !== '' ? $context : mb_substr($raw, 0, 3500));
            if (count($dates) >= 2) {
                sort($dates);
                return end($dates) ?: null;
            }
        }

        // Never infer a deadline from a single arbitrary future date, because it may
        // be a publication, exam, interview or result date.
        return null;
    }

    private function listingContextIsClearlyExpired(string $context): bool
    {
        if (trim($context) === '') return false;

        $dates = $this->extractAllDates($context);
        if (count($dates) < 2) return false;

        foreach ($dates as $date) {
            if ($this->isFutureLastDate($date)) return false;
        }

        return true;
    }

    private function datePattern(): string
    {
        return '(\\d{4}[\\/\\-.]\\d{1,2}[\\/\\-.]\\d{1,2}|\\d{1,2}[\\/\\-.]\\d{1,2}[\\/\\-.]\\d{2,4}|\\d{1,2}(?:st|nd|rd|th)?\\s+[A-Za-z]{3,9}\\s+\\d{2,4}|[A-Za-z]{3,9}\\s+\\d{1,2}(?:st|nd|rd|th)?,?\\s+\\d{2,4})';
    }

    private function extractAllDates(string $text): array
    {
        $out = [];
        if (preg_match_all('/' . $this->datePattern() . '/iu', $text, $matches)) {
            foreach ($matches[1] as $value) {
                $parsed = $this->parseGovDate($value);
                if ($parsed) $out[$parsed] = true;
            }
        }
        return array_keys($out);
    }

    private function parseGovDate(string $value): ?string
    {
        $tz = config('app.timezone', 'Asia/Kolkata');
        $value = trim((string)preg_replace('/(\\d{1,2})(st|nd|rd|th)\\b/i', '$1', $value));

        $formats = [
            'd/m/Y','d-m-Y','d.m.Y','d/m/y','d-m-y','d.m.y',
            'Y/m/d','Y-m-d','Y.m.d','d F Y','d M Y',
            'F d Y','M d Y','F d, Y','M d, Y',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value, $tz);
                if ($date !== false) {
                    $year = (int)$date->format('Y');
                    if ($year >= 2020 && $year <= ((int)date('Y') + 3)) return $date->format('Y-m-d');
                }
            } catch (Throwable $e) {}
        }

        try {
            $date = Carbon::parse($value, $tz);
            $year = (int)$date->format('Y');
            return ($year >= 2020 && $year <= ((int)date('Y') + 3)) ? $date->format('Y-m-d') : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function isFutureLastDate(?string $date): bool
    {
        if (!$date) return false;
        $tz = config('app.timezone', 'Asia/Kolkata');

        try {
            $deadline = Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
            $today = Carbon::today($tz);
            $latestReasonable = $today->copy()->addMonthsNoOverflow(18);

            return $deadline->gt($today) && $deadline->lte($latestReasonable);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function findApplyUrl(string $raw): ?string
    {
        foreach (['apply online','online application','application link','apply here','register online'] as $label) {
            $pattern = '/' . preg_quote($label, '/') . '.{0,180}?(https?:\\/\\/[^\\s<>"\']+)/isu';
            if (preg_match($pattern, $raw, $m)) return rtrim($m[1], ').,;');
        }
        return null;
    }

    private function deriveJobTitle(string $title, string $context): string
    {
        $title = $this->cleanWhitespace($title);
        $context = $this->cleanWhitespace($context);

        if ($this->isGenericAnchorTitle($title) && $context !== '') $title = $context;

        $title = preg_replace('/^(new\\s+)?(notification|advertisement|advert|advt\\.?)[\\s:\\-–]*/i', '', $title);
        $title = preg_replace('/\\b(start date|end date|last date|closing date)\\b.*$/i', '', (string)$title);
        $title = trim((string)$title, " \t\n\r\0\x0B-–|:");

        return mb_substr($title, 0, 300);
    }

    private function isGenericAnchorTitle(string $title): bool
    {
        return in_array(mb_strtolower(trim($title)), [
            '', 'view', 'view details', 'details', 'download', 'pdf', 'click here',
            'read more', 'notification', 'advertisement', 'apply', 'apply here',
        ], true);
    }

    private function isClearlyNotVacancy(string $text): bool
    {
        $t = mb_strtolower($this->cleanWhitespace($text));
        if ($t === '') return false;

        return (bool)preg_match('/\\b(final result|provisional result|result of|selected candidates?|provisionally selected|waitlisted|selection list|admit card|hall ticket|provisional answer keys?|answer keys?|merit list|shortlist|shortlisted|eligible candidates?|ineligible candidates?|document verification|interview schedule|exam schedule|examination schedule|objection|response sheet|cut[ -]?off|appointment order|limited departmental competitive examination|departmental competitive examination|departmental quota|promotion to the post|promotion to the cadre|subscribe for updates|last updated|visitor count|visitors|tender|procurement|auction|corrigendum|addendum|extension of (?:last|due) date|revised notice|cancelled|cancellation|withdrawn)\\b/u', $t);
    }

    private function looksLikeVacancy(string $text): bool
    {
        $t = mb_strtolower($this->cleanWhitespace($text));
        return (bool)preg_match('/\\b(recruit(?:ment|ing)?|vacanc(?:y|ies)|applications? invited|apply online|walk[ -]?in|apprentice(?:ship)?|engagement|posts?|hiring|agniveer|constable|sub[ -]?inspector|resident|consultant|professor|director|registrar|librarian|accountant|scientist|research fellow|project (?:staff|associate|assistant|scientist|officer)|staff nurse|engineer|officer|assistant|clerk|manager|executive|technician|stenographer|trainee|fellowship|tutor|demonstrator|driver|attendant|tradesman|multi[ -]?tasking staff|mts|data entry operator|deo)\\b/u', $t);
    }

    private function trustedListingLooksLikeVacancy(string $title, string $context): bool
    {
        $text = mb_strtolower($this->cleanWhitespace($title . ' ' . $context));
        if ($text === '' || $this->isClearlyNotVacancy($text)) return false;

        if (preg_match('/\\b(subscribe|archive|home|contact|about us|last updated|visitors?|sitemap|feedback|login|register portal)\\b/u', $text)) {
            return false;
        }

        return (bool)preg_match('/\\b(apply|application|applications|recruit(?:ment|ing)?|vacanc(?:y|ies)|walk[ -]?in|apprentice(?:ship)?|engagement|post(?:s)?|hiring|selection for the post|invites? eligible candidates|invites? applications?)\\b/u', $text);
    }

    private function cleanWhitespace(string $value): string
    {
        return trim((string)preg_replace('/\\s+/u', ' ', $value));
    }

    private function confidence(string $title, string $raw, ?int $vacancies, ?string $lastDate): int
    {
        $score = 45;
        if ($title !== '') $score += 15;
        if (mb_strlen($raw) > 400) $score += 10;
        if ($vacancies !== null) $score += 10;
        if ($lastDate !== null) $score += 15;
        if (preg_match('/qualification|eligibility|pay scale|salary|selection process/i', $raw)) $score += 5;
        return min(100, $score);
    }

    private function deleteExistingCandidate(string $url, int $documentId): void
    {
        $model = new JobCandidate();
        $columns = Schema::getColumnListing($model->getTable());

        if (in_array('official_source_url', $columns, true)) {
            JobCandidate::query()->where('official_source_url', $url)->delete();
        }
        if (in_array('discovered_document_id', $columns, true)) {
            JobCandidate::query()->where('discovered_document_id', $documentId)->delete();
        }
    }
}
