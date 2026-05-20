<?php
declare(strict_types=1);

class AIditor_Updater
{
    private string $plugin_file;
    private string $plugin_slug;
    private string $github_repo;
    private string $current_version;
    private string $transient_key;

    public function __construct(string $plugin_file, string $current_version, string $github_repo)
    {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = dirname(plugin_basename($plugin_file));
        $this->current_version = $current_version;
        $this->github_repo     = $github_repo;
        $this->transient_key   = 'aiditor_github_release';
    }

    public function register_hooks(): void
    {
        add_filter('site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_action('upgrader_process_complete', array($this, 'purge_cache'), 10, 2);
    }

    /**
     * @param object $transient
     * @return object
     */
    public function check_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (! $release || is_wp_error($release)) {
            return $transient;
        }

        $latest_version = ltrim($release->tag_name, 'v');

        if (version_compare($latest_version, $this->current_version, '>')) {
            $item = new stdClass();
            $item->id            = $this->plugin_slug;
            $item->slug          = $this->plugin_slug;
            $item->plugin        = plugin_basename($this->plugin_file);
            $item->new_version   = $latest_version;
            $item->url           = $release->html_url;
            $item->package       = $this->get_download_url($release);
            $item->icons         = array();
            $item->banners       = array();
            $item->banners_rtl   = array();
            $item->tested        = '';
            $item->requires_php  = '';
            $item->compatibility = new stdClass();

            $transient->response[plugin_basename($this->plugin_file)] = $item;
        }

        return $transient;
    }

    /**
     * @return object|false|WP_Error
     */
    private function get_latest_release()
    {
        $cached = get_transient($this->transient_key);
        if (false !== $cached) {
            return $cached;
        }

        $response = wp_remote_get(
            "https://api.github.com/repos/{$this->github_repo}/releases/latest",
            array(
                'headers' => array(
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                ),
                'timeout' => 10,
            )
        );

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $release = json_decode($body);

        if (empty($release) || empty($release->tag_name)) {
            return false;
        }

        set_transient($this->transient_key, $release, 12 * HOUR_IN_SECONDS);

        return $release;
    }

    private function get_download_url(object $release): string
    {
        if (empty($release->assets) || ! is_array($release->assets)) {
            return '';
        }

        foreach ($release->assets as $asset) {
            if (! empty($asset->browser_download_url) && ! empty($asset->content_type) && 'application/zip' === $asset->content_type) {
                return $asset->browser_download_url;
            }
        }

        foreach ($release->assets as $asset) {
            if (! empty($asset->browser_download_url) && ! empty($asset->name) && '.zip' === substr($asset->name, -4)) {
                return $asset->browser_download_url;
            }
        }

        return '';
    }

    /**
     * @param object|false $false
     * @param string $action
     * @param object $args
     * @return object|false
     */
    public function plugin_info($false, string $action, object $args)
    {
        if ('plugin_information' !== $action || $args->slug !== $this->plugin_slug) {
            return $false;
        }

        $release = $this->get_latest_release();
        if (! $release || is_wp_error($release)) {
            return $false;
        }

        $info = new stdClass();
        $info->name           = 'AIditor';
        $info->slug           = $this->plugin_slug;
        $info->version        = ltrim($release->tag_name, 'v');
        $info->author         = '<a href="https://github.com/itandelin">Mr. T</a>';
        $info->homepage       = 'https://github.com/itandelin/AIditor';
        $info->requires       = '6.0';
        $info->requires_php   = '7.4';
        $info->downloaded     = 0;
        $info->last_updated   = $release->published_at;
        $info->sections       = array(
            'description' => 'AIditor 使用通用 AI 采集模板从外部列表页或详情页抽取内容，并写入 WordPress 文章。',
            'changelog'   => $this->format_changelog($release->body ?? ''),
        );
        $info->download_link = $this->get_download_url($release);

        return $info;
    }

    private function format_changelog(string $body): string
    {
        if ('' === $body) {
            return '暂无更新日志。';
        }

        $body = esc_html($body);
        $body = preg_replace('/^(#{1,6})\s+(.+)$/m', '<h4>$2</h4>', $body);
        $body = nl2br($body);

        return $body;
    }

    /**
     * @param object $upgrader
     * @param array<string, mixed> $options
     */
    public function purge_cache(object $upgrader, array $options): void
    {
        if ('update' !== ($options['action'] ?? '') || 'plugin' !== ($options['type'] ?? '')) {
            return;
        }

        $plugins = isset($options['plugins']) ? (array) $options['plugins'] : array();

        if (in_array(plugin_basename($this->plugin_file), $plugins, true)) {
            delete_transient($this->transient_key);
        }
    }
}
