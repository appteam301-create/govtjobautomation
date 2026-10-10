<?php
namespace App\Services\Extraction;

use App\Models\DiscoveredDocument;
use App\Models\GovernmentSource;
use App\Models\JobCandidate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class OfficialSupplementalEnricher
{
    public function enrich(JobCandidate $candidate): array
    {
        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];
        $source = $candidate->government_source_id ? GovernmentSource::find($candidate->government_source_id) : null;
        $document = $candidate->discovered_document_id ? DiscoveredDocument::find($candidate->discovered_document_id) : null;
        $metadata = $document && is_array($document->metadata) ? $document->metadata : [];

        $changed = [];
        $references = is_array($data['field_sources'] ?? null) ? $data['field_sources'] : [];

        if ($this->isMissing($data['logo'] ?? null)) {
            $logo = $this->resolveLogo($candidate, $source, $metadata);
            if ($logo !== null) {
                $data['logo'] = $logo['url'];
                $data['logo_source_page'] = $logo['source_page'];
                if (!empty($logo['storage_path'])) $data['logo_storage_path'] = $logo['storage_path'];
                $references['logo'] = [
                    'provider'=>'official_source_backfill',
                    'source_url'=>$logo['source_page'],
                    'source_title'=>'Official website logo',
                    'reference'=>'Logo extracted from the official discovered/source website.',
                    'confidence'=>1.0,
                    'needs_admin_approval'=>false,
                    'fetched_at'=>now()->toIso8601String(),
                ];
                $changed[] = 'logo';
            }
        }

        if ($this->isMissing($data['apply_url'] ?? null) && empty($candidate->application_url)) {
            $applyUrl = $this->resolveJobDetailUrl($candidate, $source, $document, $metadata);
            if ($applyUrl) {
                $data['apply_url'] = $applyUrl;
                $candidate->application_url = $applyUrl;
                $references['apply_url'] = [
                    'provider'=>'official_source_backfill',
                    'source_url'=>(string)($data['discovered_from_url'] ?? $source?->recruitment_url ?? $candidate->official_source_url),
                    'source_title'=>'Official discovered job page',
                    'reference'=>'Exact job detail/apply page matched from the official recruitment listing.',
                    'confidence'=>1.0,
                    'needs_admin_approval'=>false,
                    'fetched_at'=>now()->toIso8601String(),
                ];
                $changed[] = 'apply_url';
            }
        }

        $data['field_sources'] = $references;
        $candidate->extracted_data = $data;

        $columns = Schema::getColumnListing($candidate->getTable());
        if (in_array('logo', $columns, true) && !empty($data['logo'])) {
            $candidate->setAttribute('logo', $data['logo']);
        }

        $candidate->save();

        return ['updated_fields'=>array_values(array_unique($changed))];
    }

    private function resolveLogo(JobCandidate $candidate, ?GovernmentSource $source, array $metadata): ?array
    {
        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];
        $settings = $source && is_array($source->settings) ? $source->settings : [];

        $officialHost = $this->hostOf(
            (string)($source?->recruitment_url ?: ($data['source_url'] ?? $candidate->official_source_url))
        );

        $cached = trim((string)($settings['official_logo_url'] ?? ''));
        if ($cached !== '' && $this->isOfficialAssetUrl($cached, $officialHost)) {
            return [
                'url'=>$cached,
                'source_page'=>(string)($settings['official_logo_source_page'] ?? ($data['discovered_from_url'] ?? $source?->recruitment_url)),
                'storage_path'=>$this->cacheLogoLocally($cached),
            ];
        }

        $pages = array_values(array_unique(array_filter([
            (string)($data['discovered_from_url'] ?? ''),
            (string)($metadata['discovered_from'] ?? ''),
            (string)($source?->recruitment_url ?? ''),
            $this->isHtmlLikeUrl((string)$candidate->official_source_url) ? (string)$candidate->official_source_url : '',
        ], fn($url) => $this->validUrl(trim($url)))));

        foreach ($pages as $pageUrl) {
            $pageHost = $this->hostOf($pageUrl);
            if ($officialHost !== '' && !$this->sameOfficialHost($pageHost, $officialHost)) continue;

            try {
                $response = Http::withHeaders([
                    'User-Agent'=>config('govjobs.crawler.user_agent'),
                    'Accept'=>'text/html,application/xhtml+xml,*/*;q=0.8',
                    'Accept-Language'=>'en-IN,en;q=0.9',
                ])->timeout(10)->get($pageUrl);

                if (!$response->successful()) continue;
                if (!str_contains(strtolower((string)$response->header('Content-Type')), 'html')) continue;

                $logo = $this->extractLogoFromHtml($response->body(), $pageUrl, $officialHost ?: $pageHost);
                if (!$logo) continue;

                if ($source) {
                    $settings['official_logo_url'] = $logo;
                    $settings['official_logo_source_page'] = $pageUrl;
                    $settings['official_logo_checked_at'] = now()->toIso8601String();
                    $source->settings = $settings;
                    $source->save();
                }

                return [
                    'url'=>$logo,
                    'source_page'=>$pageUrl,
                    'storage_path'=>$this->cacheLogoLocally($logo),
                ];
            } catch (Throwable $e) {
                continue;
            }
        }

        return null;
    }

    private function resolveJobDetailUrl(
        JobCandidate $candidate,
        ?GovernmentSource $source,
        ?DiscoveredDocument $document,
        array $metadata
    ): ?string {
        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];
        $discoveredFrom = (string)($data['discovered_from_url'] ?? $metadata['discovered_from'] ?? $source?->recruitment_url ?? '');

        $officialSource = trim((string)$candidate->official_source_url);
        if ($this->validUrl($officialSource)
            && $this->isHtmlLikeUrl($officialSource)
            && ($discoveredFrom === '' || $this->normalizeUrl($officialSource) !== $this->normalizeUrl($discoveredFrom))
        ) {
            return $officialSource;
        }

        if ($document && $this->validUrl((string)$document->url)
            && $this->isHtmlLikeUrl((string)$document->url)
            && ($discoveredFrom === '' || $this->normalizeUrl((string)$document->url) !== $this->normalizeUrl($discoveredFrom))
        ) {
            return (string)$document->url;
        }

        if (!$this->validUrl($discoveredFrom)) return null;

        try {
            $response = Http::withHeaders([
                'User-Agent'=>config('govjobs.crawler.user_agent'),
                'Accept'=>'text/html,application/xhtml+xml,*/*;q=0.8',
                'Accept-Language'=>'en-IN,en;q=0.9',
            ])->timeout(10)->get($discoveredFrom);

            if (!$response->successful()) return null;
            if (!str_contains(strtolower((string)$response->header('Content-Type')), 'html')) return null;

            $html = $response->body();
            if (!preg_match_all('#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER)) {
                return null;
            }

            $officialHost = $this->hostOf($discoveredFrom);
            $jobTokens = $this->identityTokens((string)$candidate->job_title);
            $best = null;
            $bestScore = 0;

            foreach ($matches as $match) {
                $href = html_entity_decode(trim((string)$match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $absolute = $this->absoluteUrl($href, $discoveredFrom);

                if (!$absolute || !$this->validUrl($absolute) || !$this->isHtmlLikeUrl($absolute)) continue;
                if (!$this->sameOfficialHost($this->hostOf($absolute), $officialHost)) continue;
                if ($this->normalizeUrl($absolute) === $this->normalizeUrl($discoveredFrom)) continue;

                $haystack = mb_strtolower($label.' '.$absolute);
                $score = 0;

                foreach ($jobTokens as $token) {
                    if (str_contains($haystack, $token)) $score += 20;
                }
                if (preg_match('#/(notice|recruitment|career|vacancy|advertisement)/#i', (string)parse_url($absolute, PHP_URL_PATH))) $score += 25;
                if (preg_match('/\b(view|details|notification|recruitment|apply)\b/i', $label)) $score += 5;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $absolute;
                }
            }

            return $bestScore >= 25 ? $best : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function cacheLogoLocally(string $url): ?string
    {
        if (!$this->validUrl($url)) return null;

        try {
            $response = Http::withHeaders([
                'User-Agent'=>config('govjobs.crawler.user_agent'),
                'Accept'=>'image/*,*/*;q=0.5',
            ])->timeout(10)->get($url);

            if (!$response->successful()) return null;

            $contentType = strtolower((string)$response->header('Content-Type'));
            if (!str_starts_with($contentType, 'image/')) return null;

            $ext = match (true) {
                str_contains($contentType, 'png') => 'png',
                str_contains($contentType, 'svg') => 'svg',
                str_contains($contentType, 'webp') => 'webp',
                str_contains($contentType, 'gif') => 'gif',
                default => 'jpg',
            };

            $dir = '/data/job-logos';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            if (!is_dir($dir)) return null;

            $path = $dir.'/'.hash('sha256', $url).'.'.$ext;
            if (!is_file($path)) file_put_contents($path, $response->body());

            return is_file($path) ? $path : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function extractLogoFromHtml(string $html, string $pageUrl, string $officialHost): ?string
    {
        $candidates = [];

        if (preg_match_all('#<img\b[^>]*>#i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                $src = $this->htmlAttribute($tag, 'src') ?: $this->htmlAttribute($tag, 'data-src');
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

        foreach ([
            ['pattern'=>'#<meta\b[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>#i','score'=>55],
            ['pattern'=>'#<meta\b[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>#i','score'=>55],
        ] as $rule) {
            if (preg_match($rule['pattern'], $html, $m)) $candidates[] = [$rule['score'], $m[1]];
        }

        if (preg_match_all('#<link\b[^>]*>#i', $html, $links)) {
            foreach ($links[0] as $tag) {
                $rel = strtolower((string)$this->htmlAttribute($tag, 'rel'));
                if (!str_contains($rel, 'icon')) continue;
                $href = $this->htmlAttribute($tag, 'href');
                if ($href) $candidates[] = [25, $href];
            }
        }

        usort($candidates, fn($a,$b) => $b[0] <=> $a[0]);

        foreach ($candidates as [, $raw]) {
            $absolute = $this->absoluteUrl($raw, $pageUrl);
            if ($absolute && $this->isOfficialAssetUrl($absolute, $officialHost)) return $absolute;
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

    private function identityTokens(string $value): array
    {
        $value = mb_strtolower(preg_replace('/[^\pL\pN]+/u', ' ', $value));
        $stop = ['the','and','for','with','under','post','posts','recruitment','notification','application','applications','district','department'];
        $tokens = [];
        foreach (preg_split('/\s+/u', trim($value)) ?: [] as $token) {
            if (mb_strlen($token) < 4 || in_array($token, $stop, true)) continue;
            $tokens[$token] = true;
        }
        return array_keys($tokens);
    }

    private function isHtmlLikeUrl(string $url): bool
    {
        if (!$this->validUrl($url)) return false;
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
        return !str_ends_with($path, '.pdf')
            && !preg_match('/\.(jpg|jpeg|png|gif|webp|svg|doc|docx|xls|xlsx|zip)$/i', $path);
    }

    private function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'javascript:') || str_starts_with($url, 'data:')) return null;
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

    private function validUrl(string $url): bool
    {
        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url);
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim(Str::lower(trim($url)), '/');
    }

    private function hostOf(string $url): string
    {
        return strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    }

    private function sameOfficialHost(string $a, string $b): bool
    {
        $a = preg_replace('/^www\./i','',strtolower($a));
        $b = preg_replace('/^www\./i','',strtolower($b));
        return $a !== '' && $b !== '' && ($a === $b || str_ends_with($a, '.'.$b) || str_ends_with($b, '.'.$a));
    }

    private function isOfficialAssetUrl(string $url, string $officialHost): bool
    {
        return $this->validUrl($url)
            && $officialHost !== ''
            && $this->sameOfficialHost($this->hostOf($url), $officialHost);
    }

    private function isMissing(mixed $value): bool
    {
        if ($value === null) return true;
        if (is_string($value)) {
            $v = trim(mb_strtolower($value));
            return $v === '' || in_array($v, ['pending','n/a','na','not available','unknown','null','-','—'], true);
        }
        return false;
    }
}
