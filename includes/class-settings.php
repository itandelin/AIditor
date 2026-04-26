<?php
declare(strict_types=1);

class AIditor_Settings
{
    public const OPTION_KEY = 'aiditor_settings';

    public static function defaults(): array
    {
        return array(
            'provider_type'         => 'openai-compatible',
            'base_url'              => '',
            'api_key'               => '',
            'model'                 => '',
            'temperature'           => 0.2,
            'max_tokens'            => 3200,
            'request_timeout'       => 60,
            'default_model_profile_id' => '',
            'model_profiles'        => array(),
            'queue_batch_size'      => 10,
            'queue_time_limit'      => 40,
            'queue_concurrency'     => 4,
            'queue_poll_interval'   => 3,
            'log_retention_days'    => 30,
            'default_category_slug' => 'skills',
            'default_post_status'   => 'draft',
            'default_article_style' => 'editorial-guide',
        );
    }

    public function maybe_initialize_defaults(): void
    {
        if (! function_exists('get_option') || ! function_exists('update_option')) {
            return;
        }

        $existing = get_option(self::OPTION_KEY, null);

        if (! is_array($existing)) {
            update_option(self::OPTION_KEY, self::defaults(), false);
        }
    }

    public function get(): array
    {
        if (! function_exists('get_option')) {
            return self::defaults();
        }

        $stored = get_option(self::OPTION_KEY, array());

        if (! is_array($stored)) {
            $stored = array();
        }

        $settings = array_replace(self::defaults(), $stored);

        if (! array_key_exists('model_profiles', $stored)) {
            unset($settings['model_profiles']);
        }

        return $this->normalize_settings($settings);
    }

    public function get_public_settings(): array
    {
        $settings                     = $this->get();
        $settings['api_key_masked']   = self::mask_api_key((string) $settings['api_key']);
        $settings['api_key_configured'] = '' !== trim((string) $settings['api_key']);
        $settings['api_key']          = '';
        $settings['model_profiles']   = $this->get_public_model_profiles((array) ($settings['model_profiles'] ?? array()));

        return $settings;
    }

    public function resolve_model_settings(string $profile_id = ''): array
    {
        $settings = $this->get();
        $profile  = $this->find_model_profile($profile_id, $settings);

        return array_replace(
            $settings,
            array(
                'provider_type'     => (string) ($profile['provider_type'] ?? 'openai-compatible'),
                'base_url'          => (string) ($profile['base_url'] ?? $settings['base_url']),
                'api_key'           => (string) ($profile['api_key'] ?? $settings['api_key']),
                'model'             => (string) ($profile['model'] ?? $settings['model']),
                'temperature'       => (float) ($profile['temperature'] ?? $settings['temperature']),
                'max_tokens'        => (int) ($profile['max_tokens'] ?? $settings['max_tokens']),
                'request_timeout'   => (int) ($profile['request_timeout'] ?? $settings['request_timeout']),
                'model_profile_id'  => (string) ($profile['profile_id'] ?? ''),
                'model_profile_name' => (string) ($profile['name'] ?? ''),
            )
        );
    }

    public function save(array $input): array
    {
        $existing = $this->get();
        $clean    = $this->sanitize($input, $existing);

        if (function_exists('update_option')) {
            update_option(self::OPTION_KEY, $clean, false);
        }

        return $this->normalize_settings(array_replace(self::defaults(), $clean));
    }

    public function sanitize(array $input, array $existing = array()): array
    {
        $defaults = self::defaults();
        $existing = array_replace($defaults, $existing);

        $base_url = isset($input['base_url']) ? trim((string) $input['base_url']) : $existing['base_url'];
        $base_url = '' !== $base_url ? rtrim($base_url, '/') : '';

        $api_key = isset($input['api_key']) ? trim((string) $input['api_key']) : '';
        if ('' === $api_key) {
            $api_key = (string) $existing['api_key'];
        }

        $model = isset($input['model']) ? trim((string) $input['model']) : (string) $existing['model'];
        $temperature = isset($input['temperature']) ? (float) $input['temperature'] : (float) $existing['temperature'];
        $temperature = max(0.0, min(2.0, $temperature));

        $max_tokens = isset($input['max_tokens']) ? (int) $input['max_tokens'] : (int) $existing['max_tokens'];
        $max_tokens = max(256, min(16384, $max_tokens));

        $timeout = isset($input['request_timeout']) ? (int) $input['request_timeout'] : (int) $existing['request_timeout'];
        $timeout = max(5, min(300, $timeout));

        $legacy_profile = null;
        $legacy_input = array(
            'base_url'        => $base_url,
            'api_key'         => $api_key,
            'model'           => $model,
            'temperature'     => $temperature,
            'max_tokens'      => $max_tokens,
            'request_timeout' => $timeout,
        );
        if ($this->has_legacy_model_configuration($legacy_input)) {
            $legacy_profile = $this->build_legacy_profile($legacy_input);
        }
        $model_profiles = $this->sanitize_model_profiles($input['model_profiles'] ?? ($existing['model_profiles'] ?? array()), $existing);

        if (empty($model_profiles) && is_array($legacy_profile)) {
            $model_profiles = array($legacy_profile);
        }

        $default_model_profile_id = $this->sanitize_identifier((string) ($input['default_model_profile_id'] ?? ($existing['default_model_profile_id'] ?? '')));
        if ('' === $default_model_profile_id && ! empty($model_profiles) && is_array($legacy_profile)) {
            $default_model_profile_id = (string) ($legacy_profile['profile_id'] ?? '');
        }
        if ('' !== $default_model_profile_id && ! $this->has_model_profile($model_profiles, $default_model_profile_id)) {
            $default_model_profile_id = '';
        }

        $default_profile = $this->find_model_profile_in_list($model_profiles, $default_model_profile_id);
        if (is_array($default_profile)) {
            $base_url    = (string) ($default_profile['base_url'] ?? '');
            $api_key     = (string) ($default_profile['api_key'] ?? '');
            $model       = (string) ($default_profile['model'] ?? '');
            $temperature = (float) ($default_profile['temperature'] ?? $defaults['temperature']);
            $max_tokens  = (int) ($default_profile['max_tokens'] ?? $defaults['max_tokens']);
            $timeout     = (int) ($default_profile['request_timeout'] ?? $defaults['request_timeout']);
        } else {
            $base_url    = '';
            $api_key     = '';
            $model       = '';
            $temperature = (float) $defaults['temperature'];
            $max_tokens  = (int) $defaults['max_tokens'];
            $timeout     = (int) $defaults['request_timeout'];
        }

        $queue_batch_size = isset($input['queue_batch_size']) ? (int) $input['queue_batch_size'] : (int) $existing['queue_batch_size'];
        $queue_batch_size = max(1, min(50, $queue_batch_size));

        $queue_time_limit = isset($input['queue_time_limit']) ? (int) $input['queue_time_limit'] : (int) $existing['queue_time_limit'];
        $queue_time_limit = max(5, min(180, $queue_time_limit));

        $queue_concurrency = isset($input['queue_concurrency']) ? (int) $input['queue_concurrency'] : (int) ($existing['queue_concurrency'] ?? $defaults['queue_concurrency']);
        $queue_concurrency = max(1, min(20, $queue_concurrency));

        $queue_poll_interval = isset($input['queue_poll_interval']) ? (int) $input['queue_poll_interval'] : (int) $existing['queue_poll_interval'];
        $queue_poll_interval = max(2, min(30, $queue_poll_interval));

        $log_retention_days = isset($input['log_retention_days']) ? (int) $input['log_retention_days'] : (int) ($existing['log_retention_days'] ?? $defaults['log_retention_days']);
        $log_retention_days = max(0, min(365, $log_retention_days));

        $category_slug = isset($input['default_category_slug']) ? trim((string) $input['default_category_slug']) : (string) $existing['default_category_slug'];
        if ('' === $category_slug) {
            $category_slug = $defaults['default_category_slug'];
        }
        if (function_exists('sanitize_title')) {
            $category_slug = sanitize_title($category_slug);
        }

        $status = isset($input['default_post_status']) ? trim((string) $input['default_post_status']) : (string) $existing['default_post_status'];
        if (! in_array($status, array('draft', 'pending', 'private', 'publish'), true)) {
            $status = $defaults['default_post_status'];
        }

        $style = isset($input['default_article_style']) ? sanitize_key((string) $input['default_article_style']) : (string) $existing['default_article_style'];
        if ('' === $style) {
            $style = $defaults['default_article_style'];
        }

        return array(
            'provider_type'         => 'openai-compatible',
            'base_url'              => $base_url,
            'api_key'               => $api_key,
            'model'                 => $model,
            'temperature'           => $temperature,
            'max_tokens'            => $max_tokens,
            'request_timeout'       => $timeout,
            'default_model_profile_id' => $default_model_profile_id,
            'model_profiles'        => $model_profiles,
            'queue_batch_size'      => $queue_batch_size,
            'queue_time_limit'      => $queue_time_limit,
            'queue_concurrency'     => $queue_concurrency,
            'queue_poll_interval'   => $queue_poll_interval,
            'log_retention_days'    => $log_retention_days,
            'default_category_slug' => $category_slug,
            'default_post_status'   => $status,
            'default_article_style' => $style,
        );
    }

    public static function mask_api_key(string $api_key): string
    {
        $api_key = trim($api_key);

        if ('' === $api_key) {
            return '';
        }

        $length = strlen($api_key);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($api_key, 0, 4) . str_repeat('*', $length - 8) . substr($api_key, -4);
    }

    protected function normalize_settings(array $settings): array
    {
        $defaults = self::defaults();
        $settings = array_replace($defaults, $settings);

        $legacy_profile = null;
        if ($this->has_legacy_model_configuration($settings)) {
            $legacy_profile = $this->build_legacy_profile($settings);
        }

        $settings['model_profiles'] = $this->sanitize_model_profiles($settings['model_profiles'] ?? array(), $settings);

        if (empty($settings['model_profiles']) && is_array($legacy_profile)) {
            $settings['model_profiles'] = array($legacy_profile);
        }

        $default_profile_id = $this->sanitize_identifier((string) ($settings['default_model_profile_id'] ?? ''));
        if ('' === $default_profile_id && ! empty($settings['model_profiles']) && is_array($legacy_profile)) {
            $default_profile_id = (string) ($legacy_profile['profile_id'] ?? '');
        }
        if ('' !== $default_profile_id && ! $this->has_model_profile($settings['model_profiles'], $default_profile_id)) {
            $default_profile_id = '';
        }

        $default_profile = $this->find_model_profile_in_list($settings['model_profiles'], $default_profile_id);

        $settings['default_model_profile_id'] = $default_profile_id;
        $settings['provider_type']            = 'openai-compatible';

        if (is_array($default_profile)) {
            $settings['base_url']        = (string) ($default_profile['base_url'] ?? '');
            $settings['api_key']         = (string) ($default_profile['api_key'] ?? '');
            $settings['model']           = (string) ($default_profile['model'] ?? '');
            $settings['temperature']     = (float) ($default_profile['temperature'] ?? $defaults['temperature']);
            $settings['max_tokens']      = (int) ($default_profile['max_tokens'] ?? $defaults['max_tokens']);
            $settings['request_timeout'] = (int) ($default_profile['request_timeout'] ?? $defaults['request_timeout']);
        } else {
            $settings['base_url']        = '';
            $settings['api_key']         = '';
            $settings['model']           = '';
            $settings['temperature']     = (float) $defaults['temperature'];
            $settings['max_tokens']      = (int) $defaults['max_tokens'];
            $settings['request_timeout'] = (int) $defaults['request_timeout'];
        }

        return $settings;
    }

    protected function sanitize_model_profiles($profiles, array $existing): array
    {
        $defaults = self::defaults();

        if (is_string($profiles)) {
            $decoded = json_decode($profiles, true);
            $profiles = is_array($decoded) ? $decoded : array();
        }

        if (! is_array($profiles)) {
            $profiles = array();
        }

        $existing_profiles = array();
        if (isset($existing['model_profiles'])) {
            $existing_profiles = is_array($existing['model_profiles'])
                ? $existing['model_profiles']
                : array();
        }

        $existing_by_id = array();
        foreach ($existing_profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profile_id = $this->sanitize_identifier((string) ($profile['profile_id'] ?? ''));
            if ('' !== $profile_id) {
                $existing_by_id[$profile_id] = $profile;
            }
        }

        $clean = array();

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profile_id = $this->sanitize_identifier((string) ($profile['profile_id'] ?? ''));
            if ('' === $profile_id) {
                $random = function_exists('wp_rand') ? (string) wp_rand() : (string) random_int(1, PHP_INT_MAX);
                $profile_id = 'model-' . substr(md5((string) microtime(true) . $random), 0, 10);
            }

            $previous = is_array($existing_by_id[$profile_id] ?? null) ? $existing_by_id[$profile_id] : array();
            $api_key  = trim((string) ($profile['api_key'] ?? ''));
            if ('' === $api_key) {
                $api_key = (string) ($previous['api_key'] ?? '');
            }

            $name = trim((string) ($profile['name'] ?? ''));
            if ('' === $name) {
                $name = '未命名模型';
            }

            $base_url = trim((string) ($profile['base_url'] ?? ($previous['base_url'] ?? '')));
            $base_url = '' !== $base_url ? rtrim($base_url, '/') : '';

            $model = trim((string) ($profile['model'] ?? ($previous['model'] ?? '')));
            if ('' === $model) {
                continue;
            }

            if ('' === $base_url) {
                continue;
            }

            $temperature = isset($profile['temperature']) ? (float) $profile['temperature'] : (float) ($previous['temperature'] ?? $defaults['temperature']);
            $temperature = max(0.0, min(2.0, $temperature));

            $max_tokens = isset($profile['max_tokens']) ? (int) $profile['max_tokens'] : (int) ($previous['max_tokens'] ?? $defaults['max_tokens']);
            $max_tokens = max(256, min(16384, $max_tokens));

            $timeout = isset($profile['request_timeout']) ? (int) $profile['request_timeout'] : (int) ($previous['request_timeout'] ?? $defaults['request_timeout']);
            $timeout = max(5, min(300, $timeout));

            $clean[] = array(
                'profile_id'      => $profile_id,
                'name'            => $name,
                'provider_type'   => 'openai-compatible',
                'base_url'        => $base_url,
                'api_key'         => $api_key,
                'model'           => $model,
                'temperature'     => $temperature,
                'max_tokens'      => $max_tokens,
                'request_timeout' => $timeout,
            );
        }

        $deduped = array();
        foreach ($clean as $profile) {
            $deduped[(string) $profile['profile_id']] = $profile;
        }

        return array_values($deduped);
    }

    protected function get_public_model_profiles(array $profiles): array
    {
        return array_map(
            static function (array $profile): array {
                $api_key = (string) ($profile['api_key'] ?? '');
                $profile['api_key_masked'] = self::mask_api_key($api_key);
                $profile['api_key_configured'] = '' !== trim($api_key);
                $profile['api_key'] = '';

                return $profile;
            },
            $profiles
        );
    }

    protected function find_model_profile(string $profile_id, array $settings): array
    {
        $profile_id = $this->sanitize_identifier($profile_id);
        $profiles   = is_array($settings['model_profiles'] ?? null) ? $settings['model_profiles'] : array();

        if ('' !== $profile_id) {
            return $this->find_model_profile_in_list($profiles, $profile_id) ?? array();
        }

        $default_profile_id = $this->sanitize_identifier((string) ($settings['default_model_profile_id'] ?? ''));
        if ('' === $default_profile_id) {
            return array();
        }

        return $this->find_model_profile_in_list($profiles, $default_profile_id) ?? array();
    }

    protected function find_model_profile_in_list(array $profiles, string $profile_id): ?array
    {
        $profile_id = $this->sanitize_identifier($profile_id);

        foreach ($profiles as $profile) {
            if (is_array($profile) && $profile_id === (string) ($profile['profile_id'] ?? '')) {
                return $profile;
            }
        }

        return null;
    }

    protected function has_model_profile(array $profiles, string $profile_id): bool
    {
        return is_array($this->find_model_profile_in_list($profiles, $profile_id));
    }

    protected function has_legacy_model_configuration(array $settings): bool
    {
        return '' !== trim((string) ($settings['api_key'] ?? ''));
    }

    protected function build_legacy_profile(array $settings): array
    {
        $defaults = self::defaults();

        return array(
            'profile_id'      => 'legacy-default',
            'name'            => '已迁移模型',
            'provider_type'   => 'openai-compatible',
            'base_url'        => rtrim(trim((string) ($settings['base_url'] ?? '')), '/'),
            'api_key'         => trim((string) ($settings['api_key'] ?? '')),
            'model'           => trim((string) ($settings['model'] ?? '')),
            'temperature'     => max(0.0, min(2.0, (float) ($settings['temperature'] ?? $defaults['temperature']))),
            'max_tokens'      => max(256, min(16384, (int) ($settings['max_tokens'] ?? $defaults['max_tokens']))),
            'request_timeout' => max(5, min(300, (int) ($settings['request_timeout'] ?? $defaults['request_timeout']))),
        );
    }

    protected function sanitize_identifier(string $value): string
    {
        if (function_exists('sanitize_key')) {
            return sanitize_key($value);
        }

        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?: '';
    }
}
