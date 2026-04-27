<?php
declare(strict_types=1);

class AIditor_Source_Researcher
{
    protected const SEARCH_ENDPOINT = 'https://www.bing.com/search?format=rss&q=%s';

    public static function build_search_queries(array $source): array
    {
        $queries = array();
        $title   = trim((string) ($source['source_title'] ?? ''));

        if ('' === $title) {
            return $queries;
        }

        $host = self::extract_search_host((string) ($source['source_homepage'] ?? ''));

        if ('' !== $host) {
            $queries[] = sprintf('"%s" site:%s', $title, $host);
        }

        $owner = trim((string) ($source['source_owner_name'] ?? ''));
        if ('' !== $owner) {
            $queries[] = $title . ' ' . $owner;
        }

        $slug = trim((string) ($source['source_slug'] ?? ''));
        if ('' !== $slug) {
            $queries[] = str_replace('-', ' ', $slug);
        }

        $queries[] = $title;

        return array_values(array_unique(array_filter($queries)));
    }

    public function build_research_packet(array $source): array
    {
        $packet = array(
            'official_pages' => array(),
            'search_results' => array(),
        );

        foreach ($this->build_official_urls($source) as $url) {
            $summary = $this->fetch_page_summary($url);

            if (! empty($summary)) {
                $packet['official_pages'][] = $summary;
            }
        }

        foreach (self::build_search_queries($source) as $query) {
            $results = $this->search_web($query, $source);

            foreach ($results as $result) {
                $packet['search_results'][] = $result;
            }

            if (count($packet['search_results']) >= 2) {
                break;
            }
        }

        $packet['search_results'] = array_values(array_slice($this->deduplicate_results($packet['search_results']), 0, 2));

        return $packet;
    }

    protected function build_official_urls(array $source): array
    {
        $urls = array();

        foreach (array('source_homepage', 'source_repository') as $field) {
            $url = trim((string) ($source[$field] ?? ''));

            if ('' === $url || ! preg_match('#^https?://#i', $url)) {
                continue;
            }

            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }

    protected function fetch_page_summary(string $url): array
    {
        try {
            $body = $this->request_text($url, 'text/html,application/xhtml+xml');
        } catch (Throwable $exception) {
            return array();
        }

        $host  = self::extract_search_host($url);
        $title = $this->extract_html_title($body);
        $meta  = $this->extract_meta_description($body);
        $lead  = 'github.com' === $host ? '' : $this->extract_lead_text($body);

        if ('' === $title && '' === $meta && '' === $lead) {
            return array();
        }

        return array(
            'url'              => $url,
            'host'             => $host,
            'page_title'       => $title,
            'meta_description' => $meta,
            'lead_text'        => $lead,
        );
    }

    protected function search_web(string $query, array $source): array
    {
        $url = sprintf(self::SEARCH_ENDPOINT, rawurlencode($query));

        try {
            $body = $this->request_text($url, 'application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.8');
        } catch (Throwable $exception) {
            return array();
        }

        if (! function_exists('simplexml_load_string')) {
            return array();
        }

        $xml = @simplexml_load_string($body);
        if (! $xml || ! isset($xml->channel->item)) {
            return array();
        }

        $results = array();

        foreach ($xml->channel->item as $item) {
            $row = array(
                'query'       => $query,
                'title'       => trim((string) ($item->title ?? '')),
                'url'         => trim((string) ($item->link ?? '')),
                'description' => trim((string) ($item->description ?? '')),
            );

            if (! $this->is_relevant_search_result($source, $row)) {
                continue;
            }

            $results[] = $row;

            if (count($results) >= 3) {
                break;
            }
        }

        return $results;
    }

    protected function is_relevant_search_result(array $source, array $row): bool
    {
        $haystack = mb_strtolower(trim($row['title'] . ' ' . $row['description']), 'UTF-8');
        $title    = mb_strtolower(trim((string) ($source['source_title'] ?? '')), 'UTF-8');
        $host     = self::extract_search_host((string) ($source['source_homepage'] ?? ''));
        $url_host = self::extract_search_host((string) ($row['url'] ?? ''));

        if ('' !== $host && '' !== $url_host && false !== strpos($url_host, $host)) {
            return true;
        }

        if ('' !== $host) {
            if ('' !== $title && false !== strpos($haystack, $title) && '' !== $url_host) {
                return false !== strpos($url_host, $host);
            }

            return false;
        }

        if ('' !== $title && false !== strpos($haystack, $title)) {
            return true;
        }

        $slug = mb_strtolower(str_replace('-', ' ', trim((string) ($source['source_slug'] ?? ''))), 'UTF-8');
        if ('' !== $slug && false !== strpos($haystack, $slug)) {
            return true;
        }

        return false;
    }

    protected function deduplicate_results(array $results): array
    {
        $seen    = array();
        $deduped = array();

        foreach ($results as $row) {
            $url = trim((string) ($row['url'] ?? ''));

            if ('' === $url || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $deduped[]  = $row;
        }

        return $deduped;
    }

    protected function request_text(string $url, string $accept): string
    {
        $sslverify = $this->should_verify_ssl();

        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get(
                $url,
                array(
                    'timeout'   => 20,
                    'sslverify' => $sslverify,
                    'headers'   => array(
                        'Accept'     => $accept,
                        'User-Agent' => 'AIditor/' . (defined('AIDITOR_VERSION') ? AIDITOR_VERSION : '0.0.1'),
                    ),
                )
            );

            if (is_wp_error($response)) {
                throw new RuntimeException($response->get_error_message());
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $body   = (string) wp_remote_retrieve_body($response);

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(sprintf('资料检索请求失败，HTTP 状态码为 %d。', $status));
            }

            return $body;
        }

        if (! function_exists('curl_init')) {
            throw new RuntimeException('当前环境没有可用的 HTTP 客户端，无法执行资料检索请求。');
        }

        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => $sslverify,
                CURLOPT_SSL_VERIFYHOST => $sslverify ? 2 : 0,
                CURLOPT_HTTPHEADER     => array(
                    'Accept: ' . $accept,
                    'User-Agent: AIditor/0.0.1',
                ),
            )
        );

        $body = curl_exec($curl);
        if (false === $body) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('资料检索请求失败：' . $error);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('资料检索请求失败，HTTP 状态码为 %d。', $status));
        }

        return (string) $body;
    }

    protected function extract_html_title(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return $this->clean_text((string) $matches[1]);
        }

        return '';
    }

    protected function extract_meta_description(string $html): string
    {
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/is', $html, $matches)) {
            return $this->clean_text((string) $matches[1]);
        }

        if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\'](.*?)["\']/is', $html, $matches)) {
            return $this->clean_text((string) $matches[1]);
        }

        return '';
    }

    protected function extract_lead_text(string $html): string
    {
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $matches) && ! empty($matches[1])) {
            $paragraphs = array();

            foreach ($matches[1] as $paragraph) {
                $clean = $this->clean_text((string) $paragraph);

                if (mb_strlen($clean, 'UTF-8') < 30) {
                    continue;
                }

                $paragraphs[] = $clean;

                if (count($paragraphs) >= 2) {
                    break;
                }
            }

            return implode("\n", $paragraphs);
        }

        return '';
    }

    protected function clean_text(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    protected function should_verify_ssl(): bool
    {
        if (function_exists('apply_filters')) {
            return (bool) apply_filters('aiditor_sslverify', true);
        }

        return true;
    }

    protected static function extract_search_host(string $url): string
    {
        if ('' === trim($url)) {
            return '';
        }

        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        $host  = strtolower((string) ($parts['host'] ?? ''));
        $host  = preg_replace('/^www\./', '', $host);
        $host  = is_string($host) ? $host : '';

        if ('' === $host) {
            return '';
        }

        return $host;
    }
}
