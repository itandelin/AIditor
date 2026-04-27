<?php
declare(strict_types=1);

class AIditor_Deduper
{
    protected const SOURCE_LOCK_TTL = 900;

    public function inspect(string $source_site, string $source_slug, array $post_types = array()): array
    {
        $post_id = $this->find_existing_post_id($source_site, $source_slug, $post_types);

        return array(
            'is_duplicate' => null !== $post_id,
            'post_id'      => $post_id,
        );
    }

    public function find_existing_post_id(string $source_site, string $source_slug, array $post_types = array()): ?int
    {
        if (! function_exists('get_posts')) {
            return null;
        }

        $posts = get_posts(
            array(
                'post_type'      => $this->normalize_post_type_query_arg($post_types),
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'   => '_aiditor_ingest_source_site',
                        'value' => $source_site,
                    ),
                    array(
                        'key'   => '_aiditor_ingest_source_slug',
                        'value' => $source_slug,
                    ),
                ),
            )
        );

        if (! empty($posts)) {
            return (int) $posts[0];
        }

        return null;
    }

    public function has_aiditor_source_identity(int $post_id, string $source_site, string $source_slug): bool
    {
        if ($post_id <= 0 || ! function_exists('get_post_meta')) {
            return false;
        }

        $existing_site = trim((string) get_post_meta($post_id, '_aiditor_ingest_source_site', true));
        $existing_slug = trim((string) get_post_meta($post_id, '_aiditor_ingest_source_slug', true));

        return '' !== $existing_site
            && '' !== $existing_slug
            && hash_equals($existing_site, trim($source_site))
            && hash_equals($existing_slug, trim($source_slug));
    }

    public function is_post_source_hash_current(int $post_id, string $source_hash): bool
    {
        $source_hash = trim($source_hash);

        if ($post_id <= 0 || '' === $source_hash || ! function_exists('get_post_meta')) {
            return false;
        }

        $existing_hash = trim((string) get_post_meta($post_id, '_aiditor_ingest_source_hash', true));

        return '' !== $existing_hash && hash_equals($existing_hash, $source_hash);
    }

    public function acquire_source_lock(string $source_site, string $source_slug, int $ttl = self::SOURCE_LOCK_TTL): bool
    {
        if (! function_exists('add_option') || ! function_exists('get_option') || ! function_exists('delete_option')) {
            return true;
        }

        $key        = $this->get_source_lock_key($source_site, $source_slug);
        $now        = time();
        $expires_at = $now + max(30, $ttl);

        if (add_option($key, (string) $expires_at, '', 'no')) {
            return true;
        }

        $existing_expires_at = (int) get_option($key, 0);
        if ($existing_expires_at > 0 && $existing_expires_at < $now) {
            delete_option($key);

            return add_option($key, (string) $expires_at, '', 'no');
        }

        return false;
    }

    public function release_source_lock(string $source_site, string $source_slug): void
    {
        if (! function_exists('delete_option')) {
            return;
        }

        delete_option($this->get_source_lock_key($source_site, $source_slug));
    }

    protected function normalize_post_type_query_arg(array $post_types)
    {
        $clean = array();

        foreach ($post_types as $post_type) {
            $post_type = trim((string) $post_type);

            if ('' !== $post_type && preg_match('/^[a-zA-Z0-9_-]+$/', $post_type)) {
                $clean[] = $post_type;
            }
        }

        $clean = array_values(array_unique($clean));

        if (! empty($clean)) {
            return $clean;
        }

        if (! function_exists('get_post_types')) {
            return 'any';
        }

        $all_post_types = get_post_types(array(), 'names');
        if (! is_array($all_post_types) || empty($all_post_types)) {
            return 'any';
        }

        $internal_post_types = array(
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
            'wp_font_family',
            'wp_font_face',
        );

        return array_values(array_diff(array_values($all_post_types), $internal_post_types));
    }

    protected function get_source_lock_key(string $source_site, string $source_slug): string
    {
        return 'aiditor_source_lock_' . md5(trim($source_site) . '|' . trim($source_slug));
    }
}
