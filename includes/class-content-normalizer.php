<?php
declare(strict_types=1);

class AIditor_Content_Normalizer
{
    public static function extract_front_matter(string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $markdown = ltrim($markdown, "\xEF\xBB\xBF");

        if (! preg_match('/\A---\n(.*?)\n---\n?/s', $markdown, $matches)) {
            return array();
        }

        $meta  = array();
        $lines = preg_split('/\n/', (string) $matches[1]);

        foreach ($lines as $line) {
            if (! is_string($line) || false === strpos($line, ':')) {
                continue;
            }

            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $key           = trim((string) $key);
            $value         = trim((string) $value);

            if ('' === $key) {
                continue;
            }

            $meta[$key] = trim($value, " \t\n\r\0\x0B\"'");
        }

        return $meta;
    }

    public static function strip_front_matter(string $markdown): string
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $markdown = ltrim($markdown, "\xEF\xBB\xBF");

        if (preg_match('/\A---\n.*?\n---\n?/s', $markdown, $matches)) {
            $markdown = substr($markdown, strlen($matches[0]));
        }

        return ltrim($markdown);
    }

    public static function extract_primary_heading(string $markdown): string
    {
        $content = self::strip_front_matter($markdown);

        if (preg_match('/^\s*#\s+(.+)$/m', $content, $matches)) {
            return trim((string) $matches[1]);
        }

        return '';
    }

    public static function resolve_source_title(array $detail, string $markdown, array $context = array()): string
    {
        $candidates = array(
            trim((string) ($detail['name'] ?? '')),
            trim((string) ($context['title'] ?? '')),
            self::extract_primary_heading($markdown),
            trim((string) ($detail['slug'] ?? $context['slug'] ?? '')),
        );

        foreach ($candidates as $index => $candidate) {
            if ('' === $candidate) {
                continue;
            }

            if ($index < 2 && self::is_generic_source_title($candidate)) {
                continue;
            }

            return $candidate;
        }

        return '';
    }

    public static function extract_reference_url(string $markdown): string
    {
        $content = self::strip_front_matter($markdown);

        if (preg_match('/\[(?:详情可查看|详情查看)\]\((https?:\/\/[^)\s]+)\)/u', $content, $matches)) {
            return trim((string) $matches[1]);
        }

        foreach (preg_split('/\n/', $content) as $line) {
            if (! is_string($line) || ! preg_match('/详情可查看|详情查看/u', $line)) {
                continue;
            }

            if (preg_match('/(https?:\/\/[^\s)]+)/u', $line, $matches)) {
                return trim((string) $matches[1]);
            }
        }

        return '';
    }

    public static function strip_leading_title_heading(string $html, string $title): string
    {
        $html  = trim($html);
        $title = trim($title);

        if ('' === $html || '' === $title) {
            return $html;
        }

        if (! preg_match('/\A\s*<h([1-2])[^>]*>(.*?)<\/h\1>\s*/is', $html, $matches)) {
            return $html;
        }

        $heading = self::normalize_heading_text((string) $matches[2]);
        $title   = self::normalize_heading_text($title);

        $what_is_title = self::normalize_heading_text('什么是' . $title);

        if ($heading !== $title && $heading !== $what_is_title) {
            return $html;
        }

        return ltrim(substr($html, strlen((string) $matches[0])));
    }

    public static function select_preferred_markdown_path(array $files): ?string
    {
        $paths = array();

        foreach ($files as $file) {
            if (is_string($file)) {
                $paths[] = $file;
                continue;
            }

            if (is_array($file)) {
                if (isset($file['path']) && is_string($file['path'])) {
                    $paths[] = $file['path'];
                    continue;
                }

                if (isset($file['name']) && is_string($file['name'])) {
                    $paths[] = $file['name'];
                }
            }
        }

        $preferred = array('SKILL.md', 'README.md', 'skill.md');

        foreach ($preferred as $target) {
            foreach ($paths as $path) {
                if (0 === strcasecmp(basename($path), $target)) {
                    return $path;
                }
            }
        }

        return null;
    }

    public function normalize(array $detail, string $markdown, array $context = array()): array
    {
        $slug = trim((string) ($detail['slug'] ?? $context['slug'] ?? ''));
        if ('' === $slug) {
            throw new InvalidArgumentException('规范化内容时必须提供来源 slug。');
        }

        $front_matter = self::extract_front_matter($markdown);

        $owner = $detail['owner'] ?? $detail['author'] ?? $detail['publisher'] ?? array();
        if (! is_array($owner)) {
            $owner = array();
        }

        $source_title = self::resolve_source_title($detail, $markdown, $context);
        if ('' === $source_title) {
            $source_title = $slug;
        }

        $source_summary = trim((string) ($detail['description'] ?? $context['summary'] ?? ''));
        $source_summary_zh = trim((string) ($detail['description_zh'] ?? $context['summary_zh'] ?? $source_summary));
        $metadata_homepage = self::extract_metadata_homepage($front_matter);
        $source_homepage = self::first_non_empty(
            array(
                self::sanitize_external_url((string) ($detail['homepage'] ?? '')),
                self::sanitize_external_url((string) ($front_matter['homepage'] ?? '')),
                self::sanitize_external_url($metadata_homepage),
                self::sanitize_external_url((string) ($context['homepage'] ?? '')),
            )
        );
        $source_reference_url = self::first_non_empty(
            array(
                self::sanitize_external_url(self::extract_reference_url($markdown)),
                $source_homepage,
            )
        );
        $source_repository = self::first_non_empty(
            array(
                $context['repository'] ?? '',
                $front_matter['source'] ?? '',
            )
        );
        $source_url = self::first_non_empty(
            array(
                trim((string) ($context['source_url'] ?? '')),
                trim((string) ($detail['url'] ?? '')),
                trim((string) ($detail['source_url'] ?? '')),
                $source_homepage,
            )
        );
        $source_owner_handle = self::first_non_empty(
            array(
                $owner['slug'] ?? '',
                $owner['handle'] ?? '',
                $owner['username'] ?? '',
                $context['owner_handle'] ?? '',
                $context['owner_slug'] ?? '',
            )
        );
        $source_site = self::resolve_source_site(
            (string) ($detail['source'] ?? $context['source'] ?? ''),
            $source_url,
            $source_homepage
        );

        return array(
            'source_site'         => $source_site,
            'source_slug'         => $slug,
            'source_title'        => $source_title,
            'source_summary'      => $source_summary,
            'source_summary_zh'   => $source_summary_zh,
            'source_url'          => $source_url,
            'source_homepage'     => $source_homepage,
            'source_repository'   => $source_repository,
            'source_reference_url' => self::first_non_empty(
                array(
                    $source_reference_url,
                    $source_repository,
                    $source_url,
                )
            ),
            'source_owner_name'   => self::first_non_empty(
                array(
                    $owner['name'] ?? '',
                    $owner['display_name'] ?? '',
                    $front_matter['author'] ?? '',
                )
            ),
            'source_owner_handle' => $source_owner_handle,
            'source_version'      => trim((string) ($detail['version'] ?? $context['version'] ?? '')),
            'source_tags'         => $this->normalize_tags($detail['tags'] ?? $detail['categories'] ?? $context['tags'] ?? array()),
            'source_stats'        => array(
                'downloads' => (int) ($detail['downloads'] ?? $context['downloads'] ?? 0),
                'stars'     => (int) ($detail['stars'] ?? $context['stars'] ?? 0),
            ),
            'source_markdown'      => trim(self::strip_front_matter($markdown)),
            'source_markdown_file' => trim((string) ($context['markdown_file'] ?? '')),
            'source_front_matter'  => $front_matter,
        );
    }

    protected function normalize_tags($raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        if ($this->is_associative_array($raw)) {
            $tags = array();

            foreach ($raw as $key => $value) {
                if (is_string($key) && ! is_numeric($key) && ! is_scalar($value)) {
                    $tags[] = $key;
                }
            }

            return array_values(array_unique($tags));
        }

        $tags = array();

        foreach ($raw as $value) {
            if (is_string($value) && '' !== trim($value)) {
                $tags[] = trim($value);
                continue;
            }

            if (is_array($value)) {
                foreach (array('name', 'slug', 'label') as $field) {
                    if (isset($value[$field]) && is_string($value[$field]) && '' !== trim($value[$field])) {
                        $tags[] = trim($value[$field]);
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($tags));
    }

    protected function is_associative_array(array $values): bool
    {
        $expected_key = 0;

        foreach ($values as $key => $unused) {
            if ($key !== $expected_key) {
                return true;
            }

            ++$expected_key;
        }

        return false;
    }

    protected static function normalize_heading_text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\p{P}\p{S}\s]+/u', '', $text);

        return is_string($text) ? $text : '';
    }

    protected static function is_generic_source_title(string $title): bool
    {
        return in_array(mb_strtolower(trim($title), 'UTF-8'), array('skills', 'skill', 'readme'), true);
    }

    protected static function extract_metadata_homepage(array $front_matter): string
    {
        $metadata = trim((string) ($front_matter['metadata'] ?? ''));
        if ('' === $metadata) {
            return '';
        }

        $decoded = json_decode($metadata, true);
        if (! is_array($decoded)) {
            return '';
        }

        foreach (array('openclaw', 'clawdbot') as $namespace) {
            if (isset($decoded[$namespace]['homepage']) && is_string($decoded[$namespace]['homepage'])) {
                return trim($decoded[$namespace]['homepage']);
            }
        }

        return '';
    }

    protected static function sanitize_external_url(string $url): string
    {
        $url = trim($url);

        if ('' === $url || ! preg_match('#^https?://#i', $url)) {
            return '';
        }

        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (! is_array($parts)) {
            return '';
        }

        return $url;
    }

    protected static function resolve_source_site(string $source, string $source_url, string $source_homepage): string
    {
        $source = strtolower(trim($source));
        if ('' !== $source) {
            return $source;
        }

        $url = self::first_non_empty(array($source_url, $source_homepage));
        if ('' === $url) {
            return 'generic_ai';
        }

        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (! is_array($parts)) {
            return 'generic_ai';
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ('' === $host) {
            return 'generic_ai';
        }

        return preg_replace('/[^a-z0-9_\-\.]/', '', $host) ?: 'generic_ai';
    }

    protected static function first_non_empty(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);

            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }
}
