<?php
namespace App\Services\Crawling;

use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class HtmlDiscovery
{
    public function discover(string $html, string $baseUrl, array $options = []): array
    {
        if (trim($html) === '') return [];

        $listing = (bool)($options['listing_is_recruitment'] ?? false);
        $crawler = new Crawler($html, $baseUrl);
        $items = [];

        try {
            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$items, $baseUrl) {
                $href = trim((string)$node->attr('href'));
                if ($href === '' || str_starts_with($href, '#') || preg_match('/^(javascript:|mailto:|tel:)/i', $href)) return;

                $url = $this->resolve($baseUrl, $href);
                if (!$url || !preg_match('#^https?://#i', $url)) return;

                $anchorText = $this->clean((string)$node->text('', true));
                $context = $this->nearestContext($node);
                $title = $this->isGenericAnchor($anchorText) && $context !== '' ? $context : $anchorText;

                $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
                $type = (str_ends_with($path, '.pdf') || preg_match('/(?:^|[?&])(file|pdf|download)=/i', $url)) ? 'pdf' : 'html';

                $key = hash('sha256', $url . '|' . mb_strtolower($title));
                $items[$key] = [
                    'url' => $url,
                    'title' => mb_substr($title ?: basename($path), 0, 500),
                    'type' => $type,
                    'context' => mb_substr($context, 0, 1500),
                    'synthetic' => false,
                ];
            });
        } catch (Throwable $e) {}

        if ($listing) $this->addSyntheticListingRows($crawler, $baseUrl, $items);

        return array_values($items);
    }

    private function addSyntheticListingRows(Crawler $crawler, string $baseUrl, array &$items): void
    {
        foreach (['table tr','li','article','.views-row','.list-group-item','.card','.notice','.news-item'] as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$items, $baseUrl) {
                    $context = $this->clean((string)$node->text('', true));
                    if (!$this->looksLikeListingEntry($context)) return;

                    $directUrl = null;
                    $type = 'html';

                    try {
                        $links = $node->filter('a[href]');
                        if ($links->count() > 0) {
                            foreach ($links as $domNode) {
                                $href = trim((string)$domNode->getAttribute('href'));
                                if ($href === '' || str_starts_with($href, '#') || preg_match('/^(javascript:|mailto:|tel:)/i', $href)) continue;
                                $candidate = $this->resolve($baseUrl, $href);
                                if (!$candidate) continue;

                                $candidatePath = strtolower((string)(parse_url($candidate, PHP_URL_PATH) ?? ''));
                                if (str_ends_with($candidatePath, '.pdf')) {
                                    $directUrl = $candidate;
                                    $type = 'pdf';
                                    break;
                                }
                                if ($directUrl === null) $directUrl = $candidate;
                            }
                        }
                    } catch (Throwable $e) {}

                    $hash = substr(hash('sha256', $context), 0, 16);
                    $url = $directUrl ?: (preg_replace('/#.*$/', '', $baseUrl) . '#job-' . $hash);

                    $key = hash('sha256', $url . '|' . mb_strtolower($context));
                    $items[$key] = [
                        'url' => $url,
                        'title' => mb_substr($context, 0, 500),
                        'type' => $type,
                        'context' => mb_substr($context, 0, 1500),
                        'synthetic' => true,
                    ];
                });
            } catch (Throwable $e) {}
        }
    }

    private function nearestContext(Crawler $node): string
    {
        $dom = $node->getNode(0);
        $parent = $dom?->parentNode;

        for ($i = 0; $parent && $i < 5; $i++, $parent = $parent->parentNode) {
            $name = strtolower((string)$parent->nodeName);
            if (in_array($name, ['tr','li','article','section','div'], true)) {
                $text = $this->clean((string)$parent->textContent);
                if (mb_strlen($text) >= 8 && mb_strlen($text) <= 2000) return $text;
            }
        }
        return '';
    }

    private function looksLikeListingEntry(string $text): bool
    {
        $t = mb_strtolower($text);
        if (mb_strlen($t) < 12 || mb_strlen($t) > 1800) return false;

        if (preg_match('/\\b(final result|provisional result|admit card|answer key|merit list|shortlist|tender|procurement|auction|cancelled|cancellation)\\b/u', $t)) return false;

        $dateCount = preg_match_all('/\\b\\d{1,2}[\\/\\-.]\\d{1,2}[\\/\\-.]\\d{2,4}\\b|\\b\\d{4}[\\/\\-.]\\d{1,2}[\\/\\-.]\\d{1,2}\\b|\\b\\d{1,2}\\s+[A-Za-z]{3,9}\\s+\\d{2,4}\\b|\\b[A-Za-z]{3,9}\\s+\\d{1,2},?\\s+\\d{2,4}\\b/i', $text);
        if ($dateCount !== false && $dateCount > 4) return false;

        $hasDate = $dateCount > 0;
        $hasJobWord = (bool)preg_match('/\\b(recruit(?:ment|ing)?|vacanc(?:y|ies)|applications? invited|walk[ -]?in|apprentice|engagement|posts?|resident|consultant|professor|scientist|engineer|officer|assistant|clerk|manager|technician|stenographer|trainee|fellowship|driver|attendant|mts|data entry operator)\\b/u', $t);
        $hasDownload = (bool)preg_match('/\\b(download|view|pdf|notification|advertisement|apply)\\b/u', $t);

        return $hasJobWord || ($hasDate && $hasDownload);
    }

    private function isGenericAnchor(string $title): bool
    {
        return in_array(mb_strtolower(trim($title)), [
            '', 'click here', 'read more', 'view', 'view details', 'details',
            'download', 'pdf', 'link', 'notification', 'advertisement', 'apply', 'apply here',
        ], true);
    }

    private function clean(string $value): string
    {
        return trim((string)preg_replace('/\\s+/u', ' ', $value));
    }

    private function resolve(string $base, string $href): ?string
    {
        if (preg_match('#^https?://#i', $href)) return $href;

        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) return null;
        if (str_starts_with($href, '//')) return $b['scheme'] . ':' . $href;

        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '/')) return $origin . $href;

        $path = $b['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path);
        $full = $dir . $href;
        $segments = [];

        foreach (explode('/', $full) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') array_pop($segments);
            else $segments[] = $segment;
        }

        return $origin . '/' . implode('/', $segments);
    }
}
