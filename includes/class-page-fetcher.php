<?php
declare(strict_types=1);

class AIditor_Page_Fetcher
{
    protected const MAX_BODY_BYTES = 524288;

    protected const MAX_TEXT_CHARS = 60000;

    public function fetch(string $url): array
    {
        $url = esc_url_raw(trim($url));

        if ('' === $url || ! preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException('必须提供有效的 HTTP 或 HTTPS 地址。');
        }

        if (! function_exists('wp_remote_get')) {
            throw new RuntimeException('当前 WordPress 环境没有可用的 HTTP 请求能力。');
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 25,
                'redirection' => 5,
                'sslverify'   => false,
                'headers'     => array(
                    'User-Agent' => 'Mozilla/5.0 AIditor/0.4 WordPress AI Collector',
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ),
            )
        );

        if (is_wp_error($response)) {
            throw new RuntimeException('页面请求失败：' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('页面请求失败，HTTP 状态码为 %d。', $status));
        }

        $body = (string) wp_remote_retrieve_body($response);
        if (strlen($body) > self::MAX_BODY_BYTES) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        $final_url = (string) wp_remote_retrieve_header($response, 'x-final-url');
        if ('' === $final_url) {
            $final_url = $url;
        }

        return $this->normalize_html($body, $final_url);
    }

    protected function normalize_html(string $html, string $url): array
    {
        $html = str_replace(array("\r\n", "\r"), "\n", $html);
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', (string) $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', (string) $html);
        $html = preg_replace('#<!--.*?-->#s', ' ', (string) $html);

        $title = '';
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $matches)) {
            $title = $this->clean_text(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $description = '';
        if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\'][^>]*>#is', $html, $matches)) {
            $description = $this->clean_text(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $links      = $this->extract_links($html, $url);
        $full_text  = $this->clean_text(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $main       = $this->extract_main_content($html, $title);
        $main_text  = trim((string) ($main['text'] ?? ''));

        if (mb_strlen($main_text, 'UTF-8') < 80) {
            $main_text = $this->clean_article_text($full_text, $title);
        }

        return array(
            'url'                => $url,
            'title'              => $title,
            'description'        => $description,
            'text'               => mb_substr($main_text, 0, self::MAX_TEXT_CHARS, 'UTF-8'),
            'full_text'          => mb_substr($full_text, 0, self::MAX_TEXT_CHARS, 'UTF-8'),
            'content_source'     => (string) ($main['source'] ?? 'fallback'),
            'content_candidates' => is_array($main['candidates'] ?? null) ? array_slice($main['candidates'], 0, 5) : array(),
            'links'              => $links,
            'fetched_at'         => gmdate('c'),
        );
    }

    protected function extract_main_content(string $html, string $title): array
    {
        if (! class_exists('DOMDocument') || ! class_exists('DOMXPath')) {
            return array(
                'text'       => $this->clean_article_text(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $title),
                'source'     => 'strip_tags',
                'candidates' => array(),
            );
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return array(
                'text'       => $this->clean_article_text(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $title),
                'source'     => 'strip_tags',
                'candidates' => array(),
            );
        }

        $xpath = new DOMXPath($document);
        $this->remove_noise_nodes($xpath);

        $candidates = $this->collect_content_candidates($xpath, $title);

        if (empty($candidates)) {
            $body = $xpath->query('//body')->item(0);
            $text = $body instanceof DOMNode
                ? $this->clean_article_text($this->node_to_text($body), $title)
                : $this->clean_article_text(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $title);

            return array(
                'text'       => $text,
                'source'     => 'body',
                'candidates' => array(),
            );
        }

        usort(
            $candidates,
            static function (array $left, array $right): int {
                return (int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0);
            }
        );

        $best = $candidates[0];

        return array(
            'text'       => (string) ($best['text'] ?? ''),
            'source'     => (string) ($best['source'] ?? 'candidate'),
            'candidates' => array_map(
                static function (array $candidate): array {
                    return array(
                        'source' => (string) ($candidate['source'] ?? ''),
                        'score'  => (int) ($candidate['score'] ?? 0),
                        'length' => (int) ($candidate['length'] ?? 0),
                        'text'   => mb_substr((string) ($candidate['text'] ?? ''), 0, 1200, 'UTF-8'),
                    );
                },
                array_slice($candidates, 0, 5)
            ),
        );
    }

    protected function remove_noise_nodes(DOMXPath $xpath): void
    {
        $noise_fragments = array(
            'ad-', 'advert', 'ads', 'article-author', 'article-date', 'article-meta',
            'breadcrumb', 'byline', 'comment', 'copyright', 'create-time', 'date-time',
            'datetime', 'entry-date', 'entry-meta', 'footer', 'header', 'login', 'menu',
            'nav', 'pager', 'pagination', 'post-author', 'post-date', 'post-meta',
            'publish', 'read-count', 'recommend', 'related', 'search', 'share', 'sidebar',
            'social', 'tag-list', 'toolbar', 'update-time', 'widget',
            '相关阅读', '相关推荐',
        );

        $queries = array(
            '//script|//style|//noscript|//template|//svg|//canvas|//iframe',
            '//form|//button|//select|//input|//textarea',
            '//header|//footer|//nav|//aside',
            '//*[@role="navigation" or @role="banner" or @role="contentinfo" or @role="complementary" or @aria-hidden="true"]',
            '//*[' . $this->build_contains_xpath('class', $noise_fragments) . ' or ' . $this->build_contains_xpath('id', $noise_fragments) . ']',
        );

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if (! $nodes instanceof DOMNodeList) {
                continue;
            }

            $remove = array();
            foreach ($nodes as $node) {
                if ($node instanceof DOMNode) {
                    $remove[] = $node;
                }
            }

            foreach ($remove as $node) {
                if ($node->parentNode instanceof DOMNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    protected function collect_content_candidates(DOMXPath $xpath, string $title): array
    {
        $content_fragments = array(
            'article-content', 'article_body', 'article-body', 'article__content',
            'content-body', 'content-main', 'detail-content', 'entry-content',
            'markdown-body', 'news-content', 'post-content', 'post__content',
            'rich-text', 'text-content', '正文',
        );

        $queries = array(
            array('//article', 'article'),
            array('//main', 'main'),
            array('//*[@role="main"]', 'role=main'),
            array('//*[' . $this->build_contains_xpath('class', $content_fragments) . ']', 'content-class'),
            array('//*[' . $this->build_contains_xpath('id', $content_fragments) . ']', 'content-id'),
            array('//*[self::section or self::div][count(.//p) >= 2]', 'paragraph-container'),
        );

        $seen       = array();
        $candidates = array();

        foreach ($queries as $entry) {
            $nodes = $xpath->query($entry[0]);

            if (! $nodes instanceof DOMNodeList) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMNode) {
                    continue;
                }

                $hash = spl_object_hash($node);
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $candidate   = $this->build_candidate($xpath, $node, $entry[1], $title);

                if (! empty($candidate)) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    protected function build_candidate(DOMXPath $xpath, DOMNode $node, string $source, string $title): array
    {
        $text   = $this->clean_article_text($this->node_to_text($node), $title);
        $length = mb_strlen($text, 'UTF-8');

        if ($length < 60) {
            return array();
        }

        $paragraphs = $xpath->query('.//p', $node);
        $headings   = $xpath->query('.//h1|.//h2|.//h3', $node);
        $links      = $xpath->query('.//a', $node);

        $link_text_length = 0;
        if ($links instanceof DOMNodeList) {
            foreach ($links as $link) {
                if ($link instanceof DOMNode) {
                    $link_text_length += mb_strlen($this->clean_text($this->node_to_text($link)), 'UTF-8');
                }
            }
        }

        $paragraph_count = $paragraphs instanceof DOMNodeList ? $paragraphs->length : 0;
        $heading_count   = $headings instanceof DOMNodeList ? $headings->length : 0;
        $link_ratio      = $length > 0 ? $link_text_length / $length : 0;
        $noise_penalty   = $this->calculate_noise_penalty($text);

        $score = $length
            + (min($paragraph_count, 24) * 120)
            + (min($heading_count, 6) * 45)
            - (int) round($link_ratio * $length * 0.9)
            - $noise_penalty;

        return array(
            'source' => $source,
            'score'  => max(0, $score),
            'length' => $length,
            'text'   => $text,
        );
    }

    protected function calculate_noise_penalty(string $text): int
    {
        $penalty = 0;
        $patterns = array(
            '/首页/u',
            '/导航/u',
            '/菜单/u',
            '/登录/u',
            '/注册/u',
            '/分享/u',
            '/相关阅读/u',
            '/相关推荐/u',
            '/上一篇/u',
            '/下一篇/u',
            '/发布时间\s*[:：]/u',
            '/阅读\s*[:：]/u',
        );

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                $penalty += count($matches[0]) * 80;
            }
        }

        return $penalty;
    }

    protected function clean_article_text(string $text, string $title = ''): string
    {
        $text = $this->clean_text($text);

        if ('' === $text) {
            return '';
        }

        $lines = preg_split('/\n/u', $text);
        $lines = is_array($lines) ? array_values(array_map('trim', $lines)) : array($text);
        $lines = array_values(
            array_filter(
                $lines,
                function (string $line): bool {
                    return '' !== $line && ! $this->is_boilerplate_line($line);
                }
            )
        );

        foreach ($lines as $index => $line) {
            if ($index > 15) {
                break;
            }

            if (in_array($line, array('正文', '文章正文', '内容正文'), true)) {
                $lines = array_slice($lines, $index + 1);
                break;
            }
        }

        $title = trim($title);
        if ('' !== $title && ! empty($lines)) {
            $first = preg_replace('/\s+/u', '', (string) $lines[0]);
            $plain_title = preg_replace('/\s+/u', '', $title);

            if (
                $first === $plain_title
                || (mb_strlen((string) $first, 'UTF-8') > 8 && false !== mb_strpos((string) $plain_title, (string) $first, 0, 'UTF-8'))
                || (mb_strlen((string) $plain_title, 'UTF-8') > 8 && false !== mb_strpos((string) $first, (string) $plain_title, 0, 'UTF-8'))
            ) {
                array_shift($lines);
            }
        }

        return trim(implode("\n\n", $lines));
    }

    protected function is_boilerplate_line(string $line): bool
    {
        $line = trim($line);

        if ('' === $line) {
            return true;
        }

        $patterns = array(
            '/^首页$/u',
            '/^当前位置[:：]?/u',
            '/^面包屑/u',
            '/^发布于/u',
            '/^\d{4}-\d{1,2}-\d{1,2}\s+\d{1,2}:\d{1,2}/u',
            '/^\d{4}年\d{1,2}月\d{1,2}日.*来源/u',
            '/^发布时间\s*[:：]/u',
            '/^阅读\s*[:：]?\s*\d*/u',
            '/^阅读时长\s*[:：]/u',
            '/^作者\s*[:：]/u',
            '/^来源\s*[:：]/u',
            '/^责任编辑\s*[:：]/u',
            '/^大字体$/u',
            '/^小字体$/u',
            '/^分享/u',
            '/^复制链接$/u',
            '/^上一篇/u',
            '/^下一篇/u',
            '/^相关阅读$/u',
            '/^相关推荐$/u',
            '/^评论/u',
            '/^返回/u',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        if (mb_strlen($line, 'UTF-8') <= 18 && preg_match('/^(AI资讯|AI新闻资讯|新闻资讯|资讯|产品库|模型算力广场|MCP服务|GEO平台|ZH)$/u', $line)) {
            return true;
        }

        return false;
    }

    protected function node_to_text(DOMNode $node): string
    {
        if (XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType) {
            return html_entity_decode((string) $node->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (! $node->hasChildNodes()) {
            return '';
        }

        $name = strtolower((string) $node->nodeName);

        if ('br' === $name) {
            return "\n";
        }

        $text = $this->is_block_node($name) ? "\n" : '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMNode) {
                $text .= $this->node_to_text($child);
            }
        }

        if ($this->is_block_node($name)) {
            $text .= "\n";
        }

        return $text;
    }

    protected function is_block_node(string $name): bool
    {
        return in_array(
            $name,
            array(
                'address', 'article', 'blockquote', 'dd', 'details', 'div', 'dl', 'dt',
                'figcaption', 'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr',
                'li', 'main', 'ol', 'p', 'pre', 'section', 'table', 'tbody', 'td',
                'tfoot', 'th', 'thead', 'tr', 'ul',
            ),
            true
        );
    }

    protected function build_contains_xpath(string $attribute, array $needles): string
    {
        $haystack = sprintf(
            "translate(@%s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')",
            preg_replace('/[^a-z_:-]/i', '', $attribute)
        );

        $parts = array();
        foreach ($needles as $needle) {
            $parts[] = sprintf('contains(%s, %s)', $haystack, $this->xpath_literal(mb_strtolower((string) $needle, 'UTF-8')));
        }

        return implode(' or ', $parts);
    }

    protected function xpath_literal(string $value): string
    {
        if (false === strpos($value, "'")) {
            return "'" . $value . "'";
        }

        if (false === strpos($value, '"')) {
            return '"' . $value . '"';
        }

        $parts = explode("'", $value);

        return "concat('" . implode("', \"'\", '", $parts) . "')";
    }

    protected function extract_links(string $html, string $base_url): array
    {
        $links = array();

        if (! preg_match_all('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER)) {
            return array();
        }

        foreach ($matches as $match) {
            $href = trim(html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ('' === $href || preg_match('#^(javascript:|mailto:|tel:)#i', $href)) {
                continue;
            }

            $absolute = $this->resolve_url($href, $base_url);
            if ('' === $absolute) {
                continue;
            }

            $label = $this->clean_text(html_entity_decode(strip_tags((string) $match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $key   = strtolower($absolute);

            if (! isset($links[$key])) {
                $links[$key] = array(
                    'url'   => $absolute,
                    'text'  => mb_substr($label, 0, 180, 'UTF-8'),
                );
            }
        }

        return array_slice(array_values($links), 0, 300);
    }

    protected function resolve_url(string $href, string $base_url): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return esc_url_raw($href);
        }

        if (0 === strpos($href, '//')) {
            $scheme = (string) parse_url($base_url, PHP_URL_SCHEME);
            return esc_url_raw(($scheme ?: 'https') . ':' . $href);
        }

        $parts = wp_parse_url($base_url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host   = (string) $parts['host'];
        $port   = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path   = (string) ($parts['path'] ?? '/');

        if (0 === strpos($href, '/')) {
            return esc_url_raw($scheme . '://' . $host . $port . $href);
        }

        $dir = preg_replace('#/[^/]*$#', '/', $path);

        return esc_url_raw($scheme . '://' . $host . $port . $dir . $href);
    }

    protected function clean_text(string $text): string
    {
        $text = preg_replace('/[ \t\x{00a0}]+/u', ' ', $text);
        $text = preg_replace('/[ ]*\n+[ ]*/u', "\n", (string) $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", (string) $text);

        return trim((string) $text);
    }
}
