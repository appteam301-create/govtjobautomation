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

        $raw = $this->extractText((string)$doc->url, (string)($doc->document_type ?? 'html'), $seedText);
        $raw = trim($seedText . "\n" . $raw);
        $raw = mb_substr($raw, 0, 160000);

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
                'official_notification_url' => (string)$doc->url,
                'source_name' => $source->name,
                'source_url' => $source->recruitment_url,
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
            ])->timeout(20)->retry(1, 300, throw: false)->get($fetchUrl);

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

        shell_exec('timeout 30s pdftotext -layout ' . escapeshellarg($tmp) . ' ' . escapeshellarg($txt) . ' 2>/dev/null');
        $text = is_file($txt) ? trim((string)file_get_contents($txt)) : '';

        if (mb_strlen($text) < 120) {
            $dir = $tmp . '_ocr';
            @mkdir($dir);
            $prefix = $dir . '/page';
            shell_exec('timeout 35s pdftoppm -f 1 -l 4 -jpeg -r 160 ' . escapeshellarg($tmp) . ' ' . escapeshellarg($prefix) . ' >/dev/null 2>&1');

            $ocrParts = [];
            foreach (glob($prefix . '-*.jpg') ?: [] as $image) {
                $outBase = $image . '_ocr';
                shell_exec('timeout 20s tesseract ' . escapeshellarg($image) . ' ' . escapeshellarg($outBase) . ' -l eng 2>/dev/null');
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
            'last date of application','last date','end date','closing date','closing on','apply by',
            'applications close','application closes','upto','up to',
        ];

        $datePattern = $this->datePattern();

        foreach ($labels as $label) {
            $pattern = '/' . preg_quote($label, '/') . '.{0,100}?' . $datePattern . '/isu';
            if (preg_match($pattern, $raw, $m)) {
                $parsed = $this->parseGovDate($m[1]);
                if ($parsed) return $parsed;
            }
        }

        if (preg_match('/' . $datePattern . '\\s*(?:to|till|until|–|-)\\s*' . $datePattern . '/iu', $raw, $m)) {
            $parsed = $this->parseGovDate($m[2]);
            if ($parsed) return $parsed;
        }

        if ($listingTrusted) {
            $dates = $this->extractAllDates($context !== '' ? $context : mb_substr($raw, 0, 3500));
            $future = array_values(array_filter($dates, fn(string $d) => $this->isFutureLastDate($d)));
            sort($future);
            if ($future) return end($future) ?: null;
        }

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
            return Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay()->gt(Carbon::today($tz));
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

        return (bool)preg_match('/\\b(final result|provisional result|result of|admit card|hall ticket|answer key|merit list|shortlist|shortlisted|document verification|interview schedule|exam schedule|examination schedule|objection|response sheet|cut[ -]?off|appointment order|tender|procurement|auction|cancelled|cancellation|withdrawn)\\b/u', $t);
    }

    private function looksLikeVacancy(string $text): bool
    {
        $t = mb_strtolower($this->cleanWhitespace($text));
        return (bool)preg_match('/\\b(recruit(?:ment|ing)?|vacanc(?:y|ies)|applications? invited|apply online|walk[ -]?in|apprentice(?:ship)?|engagement|posts?|hiring|agniveer|constable|sub[ -]?inspector|resident|consultant|professor|director|registrar|librarian|accountant|scientist|research fellow|project (?:staff|associate|assistant|scientist|officer)|staff nurse|engineer|officer|assistant|clerk|manager|executive|technician|stenographer|trainee|fellowship|tutor|demonstrator|driver|attendant|tradesman|multi[ -]?tasking staff|mts|data entry operator|deo)\\b/u', $t);
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
