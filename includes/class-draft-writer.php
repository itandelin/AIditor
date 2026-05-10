<?php
declare(strict_types=1);

class AIditor_Draft_Writer
{
    protected const LOCAL_IMAGE_DOWNLOAD_TIMEOUT_SECONDS = 30;

    protected const LOCAL_IMAGE_WEBP_QUALITY = 80;

    protected const LOCAL_IMAGE_MIME_TYPE = 'image/webp';

    protected AIditor_Settings $settings;

    protected string $last_image_localize_error = '';

    public function __construct(AIditor_Settings $settings)
    {
        $this->settings = $settings;
    }

    public function write(array $article, array $source, array $import_request): int
    {
        if (! function_exists('wp_insert_post')) {
            throw new RuntimeException('当前环境缺少 WordPress 运行时，无法创建或更新文章。');
        }

        $settings = $this->settings->get();

        $post_type       = (string) ($import_request['post_type'] ?? 'post');
        $post_status     = $this->resolve_post_status((string) ($import_request['post_status'] ?? $settings['default_post_status'] ?? 'draft'));
        $target_taxonomy = (string) ($import_request['target_taxonomy'] ?? '');
        $target_term_id  = (int) ($import_request['target_term_id'] ?? 0);
        $existing_post_id = (int) ($import_request['existing_post_id'] ?? 0);
        $author_id      = (int) ($import_request['author_id'] ?? 0);
        $content_html    = AIditor_Content_Normalizer::strip_leading_title_heading(
            (string) ($article['article_html'] ?? ''),
            (string) ($source['source_title'] ?? '')
        );
        $content_html    = trim($content_html);
        $post_content    = self::convert_html_to_block_content($content_html);
        $post_excerpt    = trim((string) ($article['article_excerpt'] ?? ''));

        if ('' === $post_excerpt) {
            $post_excerpt = trim((string) ($source['source_summary_zh'] ?? $source['source_summary'] ?? ''));
        }

        $post_data = array(
            'post_type'    => 'post',
            'post_status'  => $post_status,
            'post_title'   => (string) ($source['source_title'] ?? ''),
            'post_excerpt' => $post_excerpt,
            'post_content' => $post_content,
        );

        $author_id = $this->resolve_author_id($author_id);

        if ($author_id > 0) {
            $post_data['post_author'] = $author_id;
        }

        if ($existing_post_id > 0) {
            $this->assert_can_update_existing_post($existing_post_id, $source);

            if (! function_exists('wp_update_post')) {
                throw new RuntimeException('当前环境缺少 WordPress 更新能力，无法替换已有文章。');
            }

            $post_data = array(
                'ID'           => $existing_post_id,
                'post_title'   => (string) ($source['source_title'] ?? ''),
                'post_excerpt' => $post_excerpt,
                'post_content' => $post_content,
            );

            if ($author_id > 0) {
                $post_data['post_author'] = $author_id;
            }

            $post_id   = wp_update_post(wp_slash($post_data), true);
        } else {
            $post_data['post_type'] = $post_type;
            $post_id = wp_insert_post(wp_slash($post_data), true);
        }

        if (is_wp_error($post_id)) {
            throw new RuntimeException($post_id->get_error_message());
        }

        $post_id = (int) $post_id;
        $effective_post_type = $post_type;

        if (function_exists('get_post_type')) {
            $detected_post_type = get_post_type($post_id);
            if (is_string($detected_post_type) && '' !== $detected_post_type) {
                $effective_post_type = $detected_post_type;
            }
        }

        if ('' !== $target_taxonomy && $target_term_id > 0) {
            $result = wp_set_object_terms($post_id, array($target_term_id), $target_taxonomy, false);
            if (is_wp_error($result)) {
                throw new RuntimeException($result->get_error_message());
            }
        }

        $extra_tax_terms = $import_request['extra_tax_terms'] ?? array();
        if (is_array($extra_tax_terms)) {
            foreach ($extra_tax_terms as $taxonomy => $term_ids) {
                if (! is_string($taxonomy) || ! is_array($term_ids) || empty($term_ids)) {
                    continue;
                }

                $result = wp_set_object_terms($post_id, array_values(array_unique(array_map('intval', $term_ids))), $taxonomy, false);
                if (is_wp_error($result)) {
                    throw new RuntimeException($result->get_error_message());
                }
            }
        }

        $this->write_default_post_tag($post_id, $effective_post_type, $article, $source);

        $meta = array(
            '_aiditor_ingest_source_site'     => (string) ($source['source_site'] ?? ''),
            '_aiditor_ingest_source_slug'     => (string) ($source['source_slug'] ?? ''),
            '_aiditor_ingest_source_version'  => (string) ($source['source_version'] ?? ''),
            '_aiditor_ingest_source_url'      => (string) ($source['source_url'] ?? ''),
            '_aiditor_ingest_source_homepage' => (string) ($source['source_homepage'] ?? ''),
            '_aiditor_ingest_source_reference_url' => (string) ($source['source_reference_url'] ?? ''),
            '_aiditor_ingest_source_owner'    => trim((string) (($source['source_owner_name'] ?? '') . ' ' . ($source['source_owner_handle'] ?? ''))),
            '_aiditor_ingest_source_hash'     => self::build_source_hash($source),
            '_aiditor_ingest_ai_model'        => (string) ($article['ai_model'] ?? ''),
            '_aiditor_ingest_ai_generated_at' => (string) ($article['ai_generated_at'] ?? ''),
        );

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        $this->write_links_field($post_id, (string) ($source['source_reference_url'] ?? ''));
        $this->write_generic_custom_fields($post_id, is_array($article['generic_fields'] ?? null) ? $article['generic_fields'] : array());
        $this->write_yoast_seo_meta($post_id, $article, $source, $target_taxonomy, $target_term_id);

        return $post_id;
    }

    public function write_mapped(array $article, array $source, array $import_request, array $field_mapping): int
    {
        if (! function_exists('wp_insert_post')) {
            throw new RuntimeException('当前环境缺少 WordPress 运行时，无法创建或更新文章。');
        }

        $fields = is_array($article['fields'] ?? null) ? $article['fields'] : array();
        $post_type = sanitize_key((string) ($import_request['post_type'] ?? 'post'));
        $post_status = $this->resolve_post_status((string) ($import_request['post_status'] ?? 'draft'));
        $target_taxonomy = sanitize_key((string) ($import_request['target_taxonomy'] ?? ''));
        $target_term_id = (int) ($import_request['target_term_id'] ?? 0);
        $extra_tax_terms = is_array($import_request['extra_tax_terms'] ?? null) ? $import_request['extra_tax_terms'] : array();
        $author_id = $this->resolve_author_id((int) ($import_request['author_id'] ?? 0));
        $existing_post_id = (int) ($import_request['existing_post_id'] ?? 0);
        $post_data = array(
            'post_type'   => $post_type,
            'post_status' => $post_status,
        );
        $meta_updates = array();
        $taxonomy_updates = array();
        $content_result = array(
            'content_html'      => '',
            'featured_image_id' => 0,
        );

        if ($author_id > 0) {
            $post_data['post_author'] = $author_id;
        }

        foreach ($field_mapping as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $source_key = sanitize_key((string) ($mapping['source'] ?? ''));
            $destination_type = sanitize_key((string) ($mapping['destination_type'] ?? ''));
            $destination = sanitize_key((string) ($mapping['destination'] ?? ''));

            if ('' === $source_key || '' === $destination_type || '' === $destination || ! array_key_exists($source_key, $fields)) {
                continue;
            }

            $value = $fields[$source_key];

            if ('core' === $destination_type) {
                $this->apply_core_mapping($post_data, $destination, $value, $source, $source_key);
                continue;
            }

            if ('meta' === $destination_type) {
                $meta_updates[$destination] = $this->normalize_meta_value($value);
                continue;
            }

            if ('taxonomy' === $destination_type) {
                $taxonomy_updates[$destination] = $this->merge_taxonomy_terms(
                    $taxonomy_updates[$destination] ?? array(),
                    $this->normalize_taxonomy_terms($value)
                );
            }
        }

        if (empty($post_data['post_title'])) {
            $post_data['post_title'] = (string) ($fields['title'] ?? $source['source_title'] ?? '');
        }

        if (! isset($post_data['post_excerpt'])) {
            $post_data['post_excerpt'] = trim((string) ($fields['summary'] ?? $source['source_summary'] ?? ''));
        }

        if (! isset($post_data['post_content'])) {
            $post_data['post_content'] = self::convert_html_to_block_content((string) ($fields['content'] ?? ''));
        }

        if (! empty($import_request['creation_media'])) {
            $this->reset_image_localize_error();
            $content_result = $this->localize_creation_content_images((string) ($fields['content'] ?? ''), (string) ($import_request['cover_image_url'] ?? ''));
            if ('' !== $content_result['content_html']) {
                $post_data['post_content'] = self::convert_html_to_block_content($content_result['content_html']);
            }

            if (
                ! empty($import_request['apply_featured_image'])
                && '' !== esc_url_raw(trim((string) ($import_request['cover_image_url'] ?? '')))
                && empty($content_result['featured_image_id'])
            ) {
                throw new RuntimeException($this->build_featured_image_localize_failure_message());
            }
        }

        if ($existing_post_id > 0) {
            $this->assert_can_update_existing_post($existing_post_id, $source);

            if (! function_exists('wp_update_post')) {
                throw new RuntimeException('当前环境缺少 WordPress 更新能力，无法替换已有文章。');
            }

            $post_data['ID'] = $existing_post_id;
            $post_id = wp_update_post(wp_slash($post_data), true);
        } else {
            $post_id = wp_insert_post(wp_slash($post_data), true);
        }

        if (is_wp_error($post_id)) {
            throw new RuntimeException($post_id->get_error_message());
        }

        $post_id = (int) $post_id;

        if ('' !== $target_taxonomy && $target_term_id > 0) {
            $result = wp_set_object_terms($post_id, array($target_term_id), $target_taxonomy, false);
            if (is_wp_error($result)) {
                throw new RuntimeException($result->get_error_message());
            }
        }

        foreach ($extra_tax_terms as $taxonomy => $term_ids) {
            if (! is_string($taxonomy) || ! is_array($term_ids) || empty($term_ids)) {
                continue;
            }

            $result = wp_set_object_terms($post_id, array_values(array_unique(array_map('intval', $term_ids))), $taxonomy, false);
            if (is_wp_error($result)) {
                throw new RuntimeException($result->get_error_message());
            }
        }

        foreach ($taxonomy_updates as $taxonomy => $term_names) {
            if ('' === $taxonomy || empty($term_names)) {
                continue;
            }

            $result = wp_set_object_terms($post_id, $term_names, $taxonomy, true);
            if (is_wp_error($result)) {
                throw new RuntimeException($result->get_error_message());
            }
        }

        $effective_post_type = function_exists('get_post_type') ? (string) get_post_type($post_id) : $post_type;
        if (empty($taxonomy_updates['post_tag'])) {
            $this->write_default_post_tag($post_id, $effective_post_type ?: $post_type, $article, $source);
        }

        if (
            ! empty($import_request['creation_media'])
            && ! empty($import_request['apply_featured_image'])
            && ! empty($content_result['featured_image_id'])
        ) {
            $this->assign_featured_image($post_id, (int) $content_result['featured_image_id']);
        }

        if (
            ! empty($import_request['creation_media'])
            && ! empty($content_result['featured_image_url'])
            && isset($meta_updates['cover_image_url'])
        ) {
            $meta_updates['cover_image_url'] = (string) $content_result['featured_image_url'];
        }

        update_post_meta($post_id, '_aiditor_ingest_source_url', (string) ($source['source_url'] ?? ''));

        foreach ($meta_updates as $meta_key => $meta_value) {
            update_post_meta($post_id, $meta_key, $meta_value);
        }

        return $post_id;
    }

    protected function assert_can_update_existing_post(int $post_id, array $source): void
    {
        if ($post_id <= 0 || ! function_exists('get_post_meta')) {
            throw new RuntimeException('缺少可更新文章的来源校验能力。');
        }

        $source_site = trim((string) ($source['source_site'] ?? ''));
        $source_slug = trim((string) ($source['source_slug'] ?? ''));
        $existing_site = trim((string) get_post_meta($post_id, '_aiditor_ingest_source_site', true));
        $existing_slug = trim((string) get_post_meta($post_id, '_aiditor_ingest_source_slug', true));

        if (
            '' === $source_site
            || '' === $source_slug
            || '' === $existing_site
            || '' === $existing_slug
            || ! hash_equals($existing_site, $source_site)
            || ! hash_equals($existing_slug, $source_slug)
        ) {
            throw new RuntimeException('已有文章来源标识不匹配，已阻止覆盖写入。');
        }
    }

    public static function build_source_hash(array $source): string
    {
        return sha1((string) ($source['source_slug'] ?? '') . '|' . (string) ($source['source_version'] ?? '') . '|' . (string) ($source['source_markdown'] ?? ''));
    }

    public static function convert_html_to_block_content(string $html): string
    {
        $html = trim($html);

        if ('' === $html) {
            return '';
        }

        $blocks = self::convert_html_to_blocks_with_dom($html);

        if (empty($blocks)) {
            $blocks = self::convert_html_to_blocks_fallback($html);
        }

        $blocks = array_values(
            array_filter(
                $blocks,
                static function ($block): bool {
                    return is_string($block) && '' !== trim($block);
                }
            )
        );

        if (empty($blocks)) {
            return self::wrap_html_block($html);
        }

        return implode("\n", $blocks);
    }

    protected function write_links_field(int $post_id, string $url): void
    {
        $url = trim($url);

        if ('' === $url) {
            return;
        }

        $field_key = $this->resolve_acf_field_key($post_id, 'links');

        if ('' !== $field_key && function_exists('update_field')) {
            update_field($field_key, $url, $post_id);
            return;
        }

        update_post_meta($post_id, 'links', $url);

        if ('' !== $field_key) {
            update_post_meta($post_id, '_links', $field_key);
        }
    }

    protected function write_generic_custom_fields(int $post_id, array $fields): void
    {
        foreach ($fields as $key => $value) {
            if (! is_string($key) || in_array($key, array('title', 'summary', 'description', 'content'), true)) {
                continue;
            }

            $meta_key = sanitize_key($key);
            if ('' === $meta_key) {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $encoded = function_exists('wp_json_encode')
                    ? wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $value = is_string($encoded) ? $encoded : '';
            }

            update_post_meta($post_id, $this->get_generic_meta_key($meta_key), (string) $value);
        }
    }

    protected function get_generic_meta_key(string $key): string
    {
        $key = sanitize_key($key);

        if ('' === $key || 0 === strpos($key, '_aiditor_')) {
            return $key;
        }

        return '_aiditor_field_' . $key;
    }

    protected function resolve_post_status(string $post_status): string
    {
        $post_status = trim($post_status);

        return in_array($post_status, array('draft', 'pending', 'private', 'publish'), true) ? $post_status : 'draft';
    }

    protected function apply_core_mapping(array &$post_data, string $destination, $value, array $source, string $source_key): void
    {
        if ('post_title' === $destination) {
            $post_data['post_title'] = trim((string) $value);
            return;
        }

        if ('post_excerpt' === $destination) {
            $post_data['post_excerpt'] = trim((string) $value);
            return;
        }

        if ('post_content' === $destination) {
            $title = 'title' === $source_key ? trim((string) $value) : (string) ($source['source_title'] ?? '');
            $content_html = AIditor_Content_Normalizer::strip_leading_title_heading((string) $value, $title);
            $post_data['post_content'] = self::convert_html_to_block_content(trim($content_html));
        }
    }

    protected function normalize_meta_value($value): string
    {
        if (is_array($value) || is_object($value)) {
            $encoded = function_exists('wp_json_encode')
                ? wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : '';
        }

        return trim((string) $value);
    }

    protected function localize_creation_content_images(string $content_html, string $cover_image_url = ''): array
    {
        $content_html = trim($content_html);
        $cover_image_url = esc_url_raw(trim($cover_image_url));
        $featured_image_id = 0;
        $featured_image_url = '';

        if ('' === $content_html) {
            return $this->localize_cover_without_content('', $cover_image_url);
        }

        if (! class_exists('DOMDocument')) {
            return $this->localize_cover_without_content($content_html, $cover_image_url);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $content_html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $this->localize_cover_without_content($content_html, $cover_image_url);
        }

        $images = $document->getElementsByTagName('img');

        for ($index = $images->length - 1; $index >= 0; $index--) {
            $image = $images->item($index);
            if (! $image instanceof DOMElement) {
                continue;
            }

            $remote_url = esc_url_raw(trim((string) $image->getAttribute('src')));
            if ('' === $remote_url) {
                continue;
            }

            $attachment = $this->sideload_remote_image($remote_url);
            if (null === $attachment) {
                continue;
            }

            $image->setAttribute('src', $attachment['url']);
            if ('' === trim((string) $image->getAttribute('alt')) && '' !== $attachment['title']) {
                $image->setAttribute('alt', $attachment['title']);
            }

            if (0 === $featured_image_id && ('' === $cover_image_url || $cover_image_url === $remote_url)) {
                $featured_image_id = (int) $attachment['id'];
                $featured_image_url = (string) $attachment['url'];
            }
        }

        if (0 === $featured_image_id && '' !== $cover_image_url) {
            $attachment = $this->sideload_remote_image($cover_image_url);
            if (null !== $attachment) {
                $featured_image_id = (int) $attachment['id'];
                $featured_image_url = (string) $attachment['url'];
            }
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $html = '';

        if ($body instanceof DOMElement) {
            foreach ($body->childNodes as $child) {
                $html .= $document->saveHTML($child);
            }
        }

        return array(
            'content_html'      => '' !== trim($html) ? $html : $content_html,
            'featured_image_id' => $featured_image_id,
            'featured_image_url' => $featured_image_url,
        );
    }

    protected function localize_cover_without_content(string $content_html, string $cover_image_url): array
    {
        $featured_image_id = 0;
        $featured_image_url = '';

        if ('' !== $cover_image_url) {
            $attachment = $this->sideload_remote_image($cover_image_url);
            if (null !== $attachment) {
                $featured_image_id = (int) $attachment['id'];
                $featured_image_url = (string) $attachment['url'];
            }
        }

        return array(
            'content_html'       => $content_html,
            'featured_image_id'  => $featured_image_id,
            'featured_image_url' => $featured_image_url,
        );
    }

    protected function reset_image_localize_error(): void
    {
        $this->last_image_localize_error = '';
    }

    protected function remember_image_localize_error(string $message): void
    {
        $message = trim($message);

        if ('' === $message) {
            return;
        }

        $this->last_image_localize_error = $message;
    }

    protected function build_featured_image_localize_failure_message(): string
    {
        $detail = trim($this->last_image_localize_error);

        if ('' === $detail) {
            return '封面图本地化失败：无法转码为 WebP 或无法入库媒体库，请检查站点图像处理能力。';
        }

        return '封面图本地化失败：' . $detail;
    }

    protected function sideload_remote_image(string $url): ?array
    {
        $url = esc_url_raw(trim($url));
        if ('' === $url) {
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        if (! function_exists('download_url') || ! function_exists('media_handle_sideload')) {
            $this->remember_image_localize_error('WordPress 媒体库功能未加载，无法执行封面图入库。');
            return null;
        }

        $tmp = $this->download_remote_image_to_temp($url);
        if (null === $tmp) {
            return null;
        }

        $webp_tmp = $this->transcode_temp_image_to_webp($tmp);
        if (null === $webp_tmp) {
            @unlink($tmp);
            return null;
        }

        @unlink($tmp);
        $tmp = $webp_tmp;

        $filename = $this->build_localized_image_filename($url);
        $filetype = wp_check_filetype($filename);

        if (self::LOCAL_IMAGE_MIME_TYPE !== (string) ($filetype['type'] ?? '')) {
            $this->remember_image_localize_error('生成的 WebP 文件名未被 WordPress 识别为 image/webp。');
            @unlink($tmp);
            return null;
        }

        $file_array = array(
            'name'     => sanitize_file_name($filename),
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file_array, 0);
        if (is_wp_error($attachment_id)) {
            $this->remember_image_localize_error('媒体库入库失败：' . $attachment_id->get_error_message());
            @unlink($tmp);
            return null;
        }

        if (! function_exists('get_attached_file')) {
            return array(
                'id'    => (int) $attachment_id,
                'url'   => (string) wp_get_attachment_url($attachment_id),
                'title' => trim((string) get_the_title($attachment_id)),
            );
        }

        $attached_path = (string) get_attached_file((int) $attachment_id);
        $attached_mime = $this->resolve_attached_image_mime((int) $attachment_id, $attached_path);

        if (self::LOCAL_IMAGE_MIME_TYPE !== $attached_mime) {
            if (function_exists('wp_delete_attachment')) {
                wp_delete_attachment((int) $attachment_id, true);
            }
            $this->remember_image_localize_error(
                '媒体库已保存文件，但 MIME 校验失败：期望 image/webp，实际为 ' . ('' !== $attached_mime ? $attached_mime : 'unknown') . '。'
            );
            return null;
        }

        return array(
            'id'    => (int) $attachment_id,
            'url'   => (string) wp_get_attachment_url($attachment_id),
            'title' => trim((string) get_the_title($attachment_id)),
        );
    }

    protected function download_remote_image_to_temp(string $url): ?string
    {
        $download_error = '';
        $tmp = download_url($url, self::LOCAL_IMAGE_DOWNLOAD_TIMEOUT_SECONDS);

        if (! is_wp_error($tmp)) {
            return (string) $tmp;
        }

        $download_error = trim($tmp->get_error_message());

        $fallback_error = '';
        $fallback_tmp = $this->download_remote_image_via_http_api($url, $fallback_error);
        if (null !== $fallback_tmp) {
            return $fallback_tmp;
        }

        $message_parts = array();

        if ('' !== $download_error) {
            $message_parts[] = 'WordPress 下载失败：' . $download_error;
        }

        if ('' !== trim($fallback_error)) {
            $message_parts[] = 'HTTP 备用下载失败：' . trim($fallback_error);
        }

        if (empty($message_parts)) {
            $message_parts[] = '下载远程图片失败。';
        }

        $this->remember_image_localize_error(implode('；', $message_parts));

        return null;
    }

    protected function download_remote_image_via_http_api(string $url, string &$error_message = ''): ?string
    {
        $error_message = '';

        if (! function_exists('wp_remote_get') || ! function_exists('wp_tempnam')) {
            $error_message = '当前环境缺少 WordPress HTTP 下载能力。';
            return null;
        }

        $tmp = wp_tempnam($url . '.aiditor-download');
        if (! is_string($tmp) || '' === $tmp) {
            $error_message = '无法创建图片下载临时文件。';
            return null;
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout'             => self::LOCAL_IMAGE_DOWNLOAD_TIMEOUT_SECONDS,
                'redirection'         => 5,
                'sslverify'           => (bool) apply_filters('aiditor_sslverify', true),
                'stream'              => true,
                'filename'            => $tmp,
                'reject_unsafe_urls'   => false,
                'headers'             => array(
                    'Accept' => 'image/*, */*;q=0.8',
                ),
                'user-agent'          => 'Mozilla/5.0 (compatible; AIditor/1.0)',
            )
        );

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            @unlink($tmp);
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $status_message = function_exists('wp_remote_retrieve_response_message')
                ? trim((string) wp_remote_retrieve_response_message($response))
                : '';
            $error_message = sprintf(
                'HTTP 状态码为 %d%s',
                $status,
                '' !== $status_message ? '，' . $status_message : ''
            );
            @unlink($tmp);
            return null;
        }

        if (! file_exists($tmp)) {
            $error_message = 'HTTP 下载成功，但临时文件未生成。';
            return null;
        }

        return $tmp;
    }

    protected function transcode_temp_image_to_webp(string $tmp): ?string
    {
        if ('' === trim($tmp)) {
            $this->remember_image_localize_error('下载到的临时图片路径为空。');
            return null;
        }

        if (! function_exists('wp_get_image_editor')) {
            $this->remember_image_localize_error('当前环境缺少 wp_get_image_editor，无法执行 WebP 转码。');
            return null;
        }

        $editor = wp_get_image_editor($tmp);
        if (is_wp_error($editor)) {
            $this->remember_image_localize_error('初始化 WordPress 图片编辑器失败：' . $editor->get_error_message());
            return null;
        }

        if (method_exists($editor, 'set_quality')) {
            $editor->set_quality(self::LOCAL_IMAGE_WEBP_QUALITY);
        }

        if (! function_exists('wp_tempnam')) {
            $this->remember_image_localize_error('当前环境缺少 wp_tempnam，无法创建 WebP 临时文件。');
            return null;
        }

        $webp_tmp = wp_tempnam($tmp . '.webp');
        if (! is_string($webp_tmp) || '' === $webp_tmp) {
            $this->remember_image_localize_error('创建 WebP 临时文件失败。');
            return null;
        }

        if (! preg_match('/\.webp$/i', $webp_tmp)) {
            @unlink($webp_tmp);
            $webp_tmp .= '.webp';
        }

        $result = $editor->save($webp_tmp, self::LOCAL_IMAGE_MIME_TYPE);
        if (is_wp_error($result) || ! is_array($result) || empty($result['path'])) {
            $message = is_wp_error($result) ? $result->get_error_message() : '图片编辑器未返回有效输出路径。';
            $this->remember_image_localize_error('保存 WebP 临时文件失败：' . $message);
            @unlink($webp_tmp);
            return null;
        }

        $saved_path = (string) $result['path'];
        if ('' === $saved_path || ! file_exists($saved_path)) {
            $this->remember_image_localize_error('WebP 临时文件未生成到磁盘。');
            @unlink($webp_tmp);
            return null;
        }

        if ($saved_path !== $webp_tmp && file_exists($webp_tmp)) {
            @unlink($webp_tmp);
        }

        $verified_mime = $this->detect_image_mime_type($saved_path);
        if (self::LOCAL_IMAGE_MIME_TYPE !== $verified_mime) {
            $this->remember_image_localize_error(
                'WebP 转码完成，但输出文件 MIME 校验失败：期望 image/webp，实际为 ' . ('' !== $verified_mime ? $verified_mime : 'unknown') . '。'
            );
            @unlink($saved_path);
            return null;
        }

        return $saved_path;
    }

    protected function detect_image_mime_type(string $path): string
    {
        $path = trim($path);

        if ('' === $path || ! file_exists($path)) {
            return '';
        }

        if (function_exists('wp_get_image_mime')) {
            $mime = wp_get_image_mime($path);
            if (is_string($mime) && '' !== trim($mime)) {
                return trim($mime);
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if (is_string($mime) && '' !== trim($mime)) {
                return trim($mime);
            }
        }

        $type = wp_check_filetype($path);

        return trim((string) ($type['type'] ?? ''));
    }

    protected function resolve_attached_image_mime(int $attachment_id, string $attached_path): string
    {
        if ($attachment_id > 0 && function_exists('get_post_mime_type')) {
            $mime = get_post_mime_type($attachment_id);
            if (is_string($mime) && '' !== trim($mime)) {
                return trim($mime);
            }
        }

        return $this->detect_image_mime_type($attached_path);
    }

    protected function build_localized_image_filename(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        $basename = basename(is_string($path) ? $path : '');
        $name = sanitize_file_name((string) pathinfo($basename, PATHINFO_FILENAME));

        if ('' === $name) {
            $name = 'aiditor-image-' . wp_generate_password(8, false);
        }

        return $name . '.webp';
    }

    protected function assign_featured_image(int $post_id, int $attachment_id): void
    {
        if ($post_id <= 0 || $attachment_id <= 0 || ! function_exists('set_post_thumbnail')) {
            return;
        }

        set_post_thumbnail($post_id, $attachment_id);
    }

    protected function normalize_taxonomy_terms($value): array
    {
        $terms = array();

        if (is_array($value)) {
            foreach ($value as $item) {
                if (! is_scalar($item)) {
                    continue;
                }

                $name = trim((string) $item);
                if ('' !== $name) {
                    $terms[] = $name;
                }
            }

            return array_values(array_unique($terms));
        }

        $text = trim((string) $value);
        if ('' === $text) {
            return array();
        }

        $parts = preg_split('/[\r\n,，]+/u', $text) ?: array();
        foreach ($parts as $part) {
            $name = trim((string) $part);
            if ('' !== $name) {
                $terms[] = $name;
            }
        }

        return array_values(array_unique($terms));
    }

    protected function merge_taxonomy_terms(array $current, array $next): array
    {
        return array_values(array_unique(array_merge($current, $next)));
    }

    protected function resolve_author_id(int $author_id): int
    {
        if ($author_id > 0 && function_exists('get_user_by') && get_user_by('id', $author_id)) {
            return $author_id;
        }

        if (! function_exists('get_users')) {
            return 0;
        }

        $admins = get_users(
            array(
                'role__in' => array('administrator'),
                'fields'   => 'ID',
                'number'   => 1,
                'orderby'  => 'ID',
                'order'    => 'ASC',
            )
        );

        return is_array($admins) && ! empty($admins) ? (int) $admins[0] : 0;
    }

    protected function write_default_post_tag(int $post_id, string $post_type, array $article, array $source): void
    {
        if (! function_exists('taxonomy_exists') || ! taxonomy_exists('post_tag') || ! is_object_in_taxonomy($post_type, 'post_tag')) {
            return;
        }

        $tag_names = $this->normalize_post_tag_names($article['suggested_tags'] ?? $source['source_tags'] ?? array());

        if (empty($tag_names)) {
            return;
        }

        wp_set_post_tags($post_id, $tag_names, false);
    }

    protected function normalize_post_tag_names($tags): array
    {
        if (! is_array($tags)) {
            return array();
        }

        $normalized = array();

        foreach ($tags as $tag) {
            if (is_string($tag) && '' !== trim($tag)) {
                $normalized[] = trim($tag);
                continue;
            }

            if (! is_array($tag)) {
                continue;
            }

            foreach (array('name', 'slug', 'label') as $field) {
                if (isset($tag[$field]) && is_string($tag[$field]) && '' !== trim($tag[$field])) {
                    $normalized[] = trim($tag[$field]);
                    break;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    protected function resolve_target_term_name(string $target_taxonomy, int $target_term_id): string
    {
        if ('' === trim($target_taxonomy) || $target_term_id <= 0 || ! function_exists('get_term')) {
            return '';
        }

        $term = get_term($target_term_id, $target_taxonomy);

        if (is_wp_error($term) || ! $term || empty($term->name)) {
            return '';
        }

        return trim((string) $term->name);
    }

    protected function write_yoast_seo_meta(int $post_id, array $article, array $source, string $target_taxonomy, int $target_term_id): void
    {
        $focus_keyword = $this->build_yoast_focus_keyword($source, $target_taxonomy, $target_term_id);
        $meta_description = $this->build_yoast_meta_description($article, $source);

        if ('' !== $focus_keyword) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
        }

        if ('' !== $meta_description) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
            update_post_meta($post_id, '_yoast_wpseo_opengraph-description', $meta_description);
            update_post_meta($post_id, '_yoast_wpseo_twitter-description', $meta_description);
        }
    }

    protected function build_yoast_focus_keyword(array $source, string $target_taxonomy, int $target_term_id): string
    {
        $title = trim((string) ($source['source_title'] ?? ''));

        if ('' !== $title) {
            return $this->limit_plain_text($title, 191);
        }

        return $this->limit_plain_text($this->resolve_target_term_name($target_taxonomy, $target_term_id), 191);
    }

    protected function build_yoast_meta_description(array $article, array $source): string
    {
        $description = trim((string) ($article['article_excerpt'] ?? ''));

        if ('' === $description) {
            $description = trim((string) ($source['source_summary_zh'] ?? $source['source_summary'] ?? ''));
        }

        if ('' === $description) {
            $description = trim((string) ($source['source_title'] ?? ''));
        }

        return $this->limit_plain_text($description, 155);
    }

    protected function limit_plain_text(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)) ?: '');

        if ('' === $text || $limit <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') : $text;
        }

        return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
    }

    protected function resolve_acf_field_key(int $post_id, string $field_name): string
    {
        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
            return '';
        }

        $groups = acf_get_field_groups(array('post_id' => $post_id));
        if (! is_array($groups)) {
            return '';
        }

        foreach ($groups as $group) {
            if (! is_array($group) || empty($group['key'])) {
                continue;
            }

            $field_key = $this->find_acf_field_key_in_fields(
                (array) acf_get_fields((string) $group['key']),
                $field_name
            );

            if ('' !== $field_key) {
                return $field_key;
            }
        }

        return '';
    }

    protected function find_acf_field_key_in_fields(array $fields, string $field_name): string
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            if (($field['name'] ?? '') === $field_name && is_string($field['key'] ?? null)) {
                return (string) $field['key'];
            }

            if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                $sub_field_key = $this->find_acf_field_key_in_fields($field['sub_fields'], $field_name);

                if ('' !== $sub_field_key) {
                    return $sub_field_key;
                }
            }
        }

        return '';
    }

    protected static function convert_html_to_blocks_with_dom(string $html): array
    {
        if (! class_exists('DOMDocument')) {
            return array();
        }

        $dom     = new DOMDocument('1.0', 'UTF-8');
        $options = 0;

        if (defined('LIBXML_HTML_NOIMPLIED')) {
            $options |= LIBXML_HTML_NOIMPLIED;
        }

        if (defined('LIBXML_HTML_NODEFDTD')) {
            $options |= LIBXML_HTML_NODEFDTD;
        }

        $previous_use_internal_errors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="aiditor-block-root">' . $html . '</div>',
            $options
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous_use_internal_errors);

        if (! $loaded) {
            return array();
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return array();
        }

        $root = null;

        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement && 'div' === strtolower($child->tagName) && 'aiditor-block-root' === $child->getAttribute('id')) {
                $root = $child;
                break;
            }
        }

        if (! $root instanceof DOMElement) {
            return array();
        }

        $blocks = array();

        foreach ($root->childNodes as $child) {
            foreach (self::convert_dom_node_to_blocks($child) as $block) {
                if ('' !== trim($block)) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    protected static function convert_dom_node_to_blocks(DOMNode $node): array
    {
        if (XML_TEXT_NODE === $node->nodeType) {
            $text = preg_replace('/\s+/u', ' ', trim((string) $node->textContent));

            if (! is_string($text) || '' === $text) {
                return array();
            }

            return array(
                self::build_block_markup(
                    'paragraph',
                    '<p>' . self::escape_html($text) . '</p>'
                ),
            );
        }

        if (! $node instanceof DOMElement) {
            return array();
        }

        $tag_name = strtolower($node->tagName);

        if (in_array($tag_name, array('div', 'section', 'article', 'main'), true)) {
            $blocks = array();

            foreach ($node->childNodes as $child) {
                foreach (self::convert_dom_node_to_blocks($child) as $block) {
                    if ('' !== trim($block)) {
                        $blocks[] = $block;
                    }
                }
            }

            if (! empty($blocks)) {
                return $blocks;
            }

            $html = self::sanitize_block_html(self::get_node_html($node));

            return '' === $html ? array() : array(self::wrap_html_block($html));
        }

        if (preg_match('/^h([1-6])$/', $tag_name, $matches)) {
            $level = (int) ($matches[1] ?? 2);

            return array(
                self::build_block_markup(
                    'heading',
                    self::sanitize_block_html(self::get_node_html($node)),
                    2 === $level ? array() : array('level' => $level)
                ),
            );
        }

        if ('p' === $tag_name) {
            return array(
                self::build_block_markup(
                    'paragraph',
                    self::sanitize_block_html(self::get_node_html($node))
                ),
            );
        }

        if ('ol' === $tag_name || 'ul' === $tag_name) {
            return array(
                self::build_block_markup(
                    'list',
                    self::sanitize_block_html(self::get_node_html($node)),
                    'ol' === $tag_name ? array('ordered' => true) : array()
                ),
            );
        }

        if ('blockquote' === $tag_name) {
            return array(
                self::build_block_markup(
                    'quote',
                    self::sanitize_block_html(self::get_node_html($node))
                ),
            );
        }

        if ('hr' === $tag_name) {
            return array(
                self::build_block_markup(
                    'separator',
                    '<hr class="wp-block-separator has-alpha-channel-opacity"/>'
                ),
            );
        }

        $html = self::sanitize_block_html(self::get_node_html($node));

        return '' === $html ? array() : array(self::wrap_html_block($html));
    }

    protected static function convert_html_to_blocks_fallback(string $html): array
    {
        $matches = array();
        preg_match_all('/<(h[1-6]|p|ol|ul|blockquote)\b[^>]*>.*?<\/\1\s*>|<hr\b[^>]*\/?>/isu', $html, $matches);

        if (empty($matches[0]) || ! is_array($matches[0])) {
            return array();
        }

        $blocks = array();

        foreach ($matches[0] as $fragment) {
            $fragment = trim((string) $fragment);

            if ('' === $fragment) {
                continue;
            }

            if (preg_match('/^<(h([1-6]))\b/iu', $fragment, $heading_matches)) {
                $level = isset($heading_matches[2]) ? (int) $heading_matches[2] : 2;
                $blocks[] = self::build_block_markup('heading', self::sanitize_block_html($fragment), 2 === $level ? array() : array('level' => $level));
                continue;
            }

            if (preg_match('/^<p\b/iu', $fragment)) {
                $blocks[] = self::build_block_markup('paragraph', self::sanitize_block_html($fragment));
                continue;
            }

            if (preg_match('/^<ol\b/iu', $fragment)) {
                $blocks[] = self::build_block_markup('list', self::sanitize_block_html($fragment), array('ordered' => true));
                continue;
            }

            if (preg_match('/^<ul\b/iu', $fragment)) {
                $blocks[] = self::build_block_markup('list', self::sanitize_block_html($fragment));
                continue;
            }

            if (preg_match('/^<blockquote\b/iu', $fragment)) {
                $blocks[] = self::build_block_markup('quote', self::sanitize_block_html($fragment));
                continue;
            }

            if (preg_match('/^<hr\b/iu', $fragment)) {
                $blocks[] = self::build_block_markup('separator', '<hr class="wp-block-separator has-alpha-channel-opacity"/>');
            }
        }

        return $blocks;
    }

    protected static function build_block_markup(string $block_name, string $html, array $attributes = array()): string
    {
        $html = trim($html);

        if ('' === $html) {
            return '';
        }

        $opening = '<!-- wp:' . $block_name;

        if (! empty($attributes)) {
            $json = function_exists('wp_json_encode')
                ? wp_json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (is_string($json) && '' !== $json) {
                $opening .= ' ' . $json;
            }
        }

        $opening .= ' -->';

        return $opening . "\n" . $html . "\n" . '<!-- /wp:' . $block_name . ' -->';
    }

    protected static function wrap_html_block(string $html): string
    {
        return self::build_block_markup('html', self::sanitize_block_html($html));
    }

    protected static function sanitize_block_html(string $html): string
    {
        $html = trim($html);

        if ('' === $html) {
            return '';
        }

        if (function_exists('wp_kses_post')) {
            $html = (string) wp_kses_post($html);
        }

        return trim($html);
    }

    protected static function get_node_html(DOMNode $node): string
    {
        $document = $node->ownerDocument;

        if (! $document instanceof DOMDocument) {
            return '';
        }

        $html = $document->saveHTML($node);

        return is_string($html) ? trim($html) : '';
    }

    protected static function escape_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
