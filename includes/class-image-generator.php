<?php
declare(strict_types=1);

class AIditor_Image_Generator
{
    protected const RETRYABLE_HTTP_STATUSES = array(502, 503, 504, 524);

    protected const MAX_RETRY_ATTEMPTS = 1;

    protected const RETRY_DELAY_SECONDS = 1;

    protected const ASYNC_TASK_POLL_INTERVAL_SECONDS = 2;

    protected const ASYNC_TASK_POLL_MAX_SECONDS = 120;

    protected const ASYNC_TASK_REQUEST_TIMEOUT_SECONDS = 20;

    protected AIditor_Settings $settings;

    protected string $last_cover_task_id = '';

    protected bool $last_cover_task_pending = false;

    public function __construct(AIditor_Settings $settings)
    {
        $this->settings = $settings;
    }

    public function get_last_cover_task_id(): string
    {
        return $this->last_cover_task_id;
    }

    public function was_last_cover_task_pending(): bool
    {
        return $this->last_cover_task_pending;
    }

    public function generate_cover(array $context): array
    {
        $this->reset_cover_task_state();
        $settings = $this->get_image_settings();

        if (! $this->has_usable_settings($settings)) {
            throw new RuntimeException('请先在“生图设置”中启用并填写接口地址、API 密钥和模型名称。');
        }

        $payload = $this->build_generation_payload($context, $settings);
        $task_id = $this->resolve_cover_task_id($context, $payload);
        $retry_only = ! empty($context['retry_only']);

        if ('' !== $task_id) {
            $this->last_cover_task_id = $task_id;
        }

        $headers = array(
            'Authorization' => 'Bearer ' . trim((string) $settings['image_api_key']),
            'Content-Type'  => 'application/json',
        );

        if ($retry_only) {
            if ('' === $task_id) {
                throw new RuntimeException('缺少可恢复的封面任务编号，请先点击“一键生成封面图”。');
            }

            try {
                $response = $this->recover_cover_via_async_task($payload, $headers, $settings, $task_id, false);
            } catch (Throwable $retry_exception) {
                // “重试生成封面图”优先恢复既有任务；如果内部异步任务不存在或接口不稳定，
                // 回退到公开的同步生图接口，避免把可重试场景直接打成 500。
                $response = $this->generate_cover_via_sync_flow($payload, $headers, $settings, $task_id);
            }
        } else {
            $response = $this->generate_cover_via_sync_flow($payload, $headers, $settings, $task_id);
        }

        $image_url = $this->extract_image_url($response);

        if ('' === $image_url) {
            throw new RuntimeException('生图接口未返回可用图片地址。');
        }

        return array(
            'image_url' => $image_url,
            'prompt'    => (string) $payload['prompt'],
            'raw'       => $response,
        );
    }

    protected function reset_cover_task_state(): void
    {
        $this->last_cover_task_id = '';
        $this->last_cover_task_pending = false;
    }

    public function list_models(array $override_settings = array()): array
    {
        $settings = array_replace($this->get_image_settings(), $override_settings);
        $base_url = trim((string) ($settings['image_base_url'] ?? ''));
        $api_key = trim((string) ($settings['image_api_key'] ?? ''));
        $timeout = (int) ($settings['image_request_timeout'] ?? 60);

        if ('' === $base_url || '' === $api_key) {
            throw new RuntimeException('请先填写生图接口地址和 API 密钥。');
        }

        $response = $this->get_json(
            $this->build_models_endpoint($base_url),
            array(
                'Authorization' => 'Bearer ' . $api_key,
            ),
            $timeout
        );

        return $this->extract_models($response);
    }

    protected function get_image_settings(): array
    {
        return $this->settings->get();
    }

    protected function normalize_image_timeout($timeout): int
    {
        return max(60, (int) $timeout);
    }

    protected function has_usable_settings(array $settings): bool
    {
        return ! empty($settings['image_generation_enabled'])
            && '' !== trim((string) ($settings['image_base_url'] ?? ''))
            && '' !== trim((string) ($settings['image_api_key'] ?? ''))
            && '' !== trim((string) ($settings['image_model'] ?? ''));
    }

    protected function build_generation_payload(array $context, array $settings): array
    {
        $payload = array(
            'model'           => (string) $settings['image_model'],
            'prompt'          => $this->build_prompt($context),
            'n'               => 1,
            'response_format' => 'b64_json',
        );

        $size = $this->map_ratio_to_size(trim((string) ($context['ratio'] ?? '')));
        if ('' !== $size) {
            $payload['size'] = $size;
        }

        return $payload;
    }

    protected function resolve_cover_task_id(array $context, array $payload): string
    {
        $context_task_id = $this->sanitize_cover_task_id((string) ($context['cover_task_id'] ?? ''));
        if ('' !== $context_task_id) {
            return $context_task_id;
        }

        $request_id = trim((string) ($context['cover_request_id'] ?? ''));
        if ('' !== $request_id) {
            $payload['request_id'] = $request_id;
        }

        return $this->build_cover_task_id($payload);
    }

    protected function sanitize_cover_task_id(string $task_id): string
    {
        $task_id = trim($task_id);

        if ('' === $task_id) {
            return '';
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,120}$/', $task_id)) {
            return '';
        }

        return $task_id;
    }

    protected function build_prompt(array $context): string
    {
        $title = trim((string) ($context['title'] ?? ''));
        $summary = trim((string) ($context['summary'] ?? ''));
        $keywords = $this->normalize_keywords($context['keywords'] ?? array());
        $content = $this->limit_plain_text((string) ($context['content'] ?? ''), 48);
        $prompt_override = trim((string) ($context['prompt_override'] ?? ''));

        if ('' !== $prompt_override) {
            return $prompt_override;
        }

        $parts = array(
            '请生成一张适合作为中文科技资讯文章封面图的专业插画或概念视觉，构图简洁、现代、干净，避免出现清晰可辨认的文字。',
        );

        $theme_fragments = array();

        if ('' !== $title) {
            $parts[] = '核心主题：' . $title;
            $theme_fragments[] = $title;
        }

        if ('' !== $summary && ! $this->is_redundant_prompt_fragment($summary, $theme_fragments)) {
            $parts[] = '关键信息：' . $summary;
            $theme_fragments[] = $summary;
        }

        if (! empty($keywords)) {
            $parts[] = '视觉关键词：' . implode('、', array_slice($keywords, 0, 5));
        }

        if ('' !== $content && ! $this->is_redundant_prompt_fragment($content, $theme_fragments)) {
            $parts[] = '补充背景：' . $content;
            $theme_fragments[] = $content;
        }

        $parts[] = '仅提炼适合封面图的视觉元素、主题氛围与主体对象，不要复述全文，不要输出段落、列表、链接、引述或敏感审查相关措辞。';

        return implode("\n", $parts);
    }

    protected function is_redundant_prompt_fragment(string $candidate, array $existing_fragments): bool
    {
        $normalized_candidate = $this->normalize_prompt_fragment($candidate);

        if ('' === $normalized_candidate) {
            return true;
        }

        foreach ($existing_fragments as $fragment) {
            $normalized_fragment = $this->normalize_prompt_fragment((string) $fragment);

            if ('' === $normalized_fragment) {
                continue;
            }

            if ($normalized_candidate === $normalized_fragment) {
                return true;
            }

            if (
                strlen($normalized_candidate) >= 12
                && strlen($normalized_fragment) >= 12
                && (
                    false !== strpos($normalized_candidate, $normalized_fragment)
                    || false !== strpos($normalized_fragment, $normalized_candidate)
                )
            ) {
                return true;
            }
        }

        return false;
    }

    protected function normalize_prompt_fragment(string $fragment): string
    {
        $fragment = trim($fragment);

        if ('' === $fragment) {
            return '';
        }

        if (function_exists('wp_strip_all_tags')) {
            $fragment = wp_strip_all_tags($fragment);
        } else {
            $fragment = strip_tags($fragment);
        }

        $fragment = function_exists('mb_strtolower')
            ? mb_strtolower($fragment, 'UTF-8')
            : strtolower($fragment);
        $fragment = preg_replace('/\s+/u', '', $fragment);
        $fragment = preg_replace('/[[:punct:]]+/u', '', (string) $fragment);

        return trim((string) $fragment);
    }

    protected function map_ratio_to_size(string $ratio): string
    {
        $ratio = strtolower(trim($ratio));

        if ('' === $ratio) {
            return '';
        }

        $map = array(
            '1:1'  => '1024x1024',
            '16:9' => '1536x1024',
            '9:16' => '1024x1536',
            '4:3'  => '1536x1152',
            '3:4'  => '1152x1536',
        );

        return (string) ($map[$ratio] ?? '');
    }

    protected function build_endpoint(string $base_url): string
    {
        $base_url = rtrim(trim($base_url), '/');

        if ('' === $base_url) {
            throw new RuntimeException('生图接口地址不能为空。');
        }

        if (preg_match('#/images(?:/generations)?$#', $base_url)) {
            return $base_url;
        }

        return $base_url . '/images/generations';
    }

    protected function build_models_endpoint(string $base_url): string
    {
        $base_url = rtrim(trim($base_url), '/');

        if ('' === $base_url) {
            throw new RuntimeException('生图接口地址不能为空。');
        }

        if (preg_match('#/images(?:/generations)?$#', $base_url)) {
            return preg_replace('#/images(?:/generations)?$#', '/models', $base_url) ?: $base_url . '/models';
        }

        if (preg_match('#/v1$#', $base_url)) {
            return $base_url . '/models';
        }

        return $base_url . '/models';
    }

    protected function normalize_keywords($keywords): array
    {
        if (is_string($keywords)) {
            $keywords = preg_split('/[,，\n]+/u', $keywords) ?: array();
        }

        if (! is_array($keywords)) {
            return array();
        }

        $normalized = array();

        foreach ($keywords as $keyword) {
            if (! is_scalar($keyword)) {
                continue;
            }

            $value = trim((string) $keyword);
            if ('' !== $value) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
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


    protected function post_json(string $url, array $payload, array $headers, int $timeout): array
    {
        $sslverify = (bool) apply_filters('aiditor_sslverify', true);
        $body = function_exists('wp_json_encode')
            ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($body)) {
            throw new RuntimeException('生图请求体编码失败。');
        }

        if (function_exists('wp_remote_post')) {
            return $this->post_json_with_retry(
                function () use ($url, $payload, $headers, $timeout, $sslverify): array {
                    return $this->post_json_via_wordpress($url, $payload, $headers, max(5, $timeout), $sslverify);
                }
            );
        }

        if (function_exists('curl_init')) {
            return $this->post_json_with_retry(
                function () use ($url, $body, $headers, $timeout, $sslverify): array {
                    return $this->post_json_via_curl($url, $body, $headers, max(5, $timeout), $sslverify);
                }
            );
        }

        throw new RuntimeException('当前环境没有可用的 HTTP 传输能力，无法执行生图请求。');
    }

    protected function post_json_via_wordpress(string $url, array $payload, array $headers, int $timeout, bool $sslverify): array
    {
        $body = function_exists('wp_json_encode')
            ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($body)) {
            throw new RuntimeException('生图请求体编码失败。');
        }

        $response = wp_remote_post(
            $url,
            array(
                'timeout'   => $timeout,
                'sslverify' => $sslverify,
                'headers'   => $headers,
                'body'      => $body,
            )
        );

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            throw $this->build_http_exception('生图请求失败', $status, $response_body);
        }

        $decoded = json_decode($response_body, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('生图接口返回了无效的 JSON 数据。');
        }

        return $decoded;
    }

    protected function post_json_via_curl(string $url, string $body, array $headers, int $timeout, bool $sslverify): array
    {
        $curl = curl_init($url);
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => $sslverify,
                CURLOPT_SSL_VERIFYHOST => $sslverify ? 2 : 0,
                CURLOPT_HTTPHEADER     => $this->headers_to_array($headers),
                CURLOPT_POSTFIELDS     => $body,
            )
        );

        $response_body = curl_exec($curl);
        if (false === $response_body) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('生图请求失败：' . $error);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status < 200 || $status >= 300) {
            throw $this->build_http_exception('生图请求失败', $status, (string) $response_body);
        }

        $decoded = json_decode((string) $response_body, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('生图接口返回了无效的 JSON 数据。');
        }

        return $decoded;
    }

    protected function post_json_with_retry(callable $requester): array
    {
        $attempt = 0;
        $last_exception = null;

        while ($attempt <= self::MAX_RETRY_ATTEMPTS) {
            try {
                return $requester();
            } catch (Throwable $exception) {
                $last_exception = $exception;
                $status = $this->extract_http_status_from_exception($exception);

                if (
                    ! $this->is_retryable_exception($exception)
                    || $attempt >= self::MAX_RETRY_ATTEMPTS
                    || in_array($status, array(504, 524), true)
                ) {
                    throw $exception;
                }

                $this->pause_before_retry(self::RETRY_DELAY_SECONDS);
                ++$attempt;
            }
        }

        if ($last_exception instanceof Throwable) {
            throw $last_exception;
        }

        throw new RuntimeException('生图请求失败：未知错误。');
    }

    protected function should_fallback_to_async_task(array $response): bool
    {
        $data = $response['data'] ?? null;

        if (is_array($data) && ! empty($data)) {
            return false;
        }

        $message = strtolower(trim((string) ($response['message'] ?? '')));

        if ('' === $message) {
            return false;
        }

        return false !== strpos($message, 'timeout')
            || false !== strpos($message, 'timed out')
            || false !== strpos($message, 'gateway timeout')
            || false !== strpos($message, 'status code 524')
            || false !== strpos($message, 'status code 504')
            || false !== strpos($message, '状态码为 524')
            || false !== strpos($message, '状态码为 504');
    }

    protected function generate_cover_via_sync_flow(
        array $payload,
        array $headers,
        array $settings,
        string $task_id = ''
    ): array {
        try {
            $response = $this->post_json(
                $this->build_endpoint((string) $settings['image_base_url']),
                $payload,
                $headers,
                $this->normalize_image_timeout($settings['image_request_timeout'] ?? 60)
            );
        } catch (Throwable $exception) {
            if (! $this->is_retryable_exception($exception)) {
                throw $exception;
            }

            $response = $this->recover_cover_after_gateway_error($exception, $payload, $headers, $settings, $task_id);
        }

        if (! $this->should_fallback_to_async_task($response)) {
            return $response;
        }

        try {
            return $this->recover_cover_via_async_task($payload, $headers, $settings, $task_id, true);
        } catch (Throwable $fallback_exception) {
            $this->last_cover_task_pending = true;
            $upstream_message = trim((string) ($response['message'] ?? ''));
            $message = '生图请求超时，自动补偿未成功，请稍后点击“重试生成封面图”。';

            if ('' !== $upstream_message) {
                $message .= ' 上游返回：' . $upstream_message;
            }

            throw new RuntimeException($message, 504);
        }
    }

    protected function recover_cover_after_gateway_error(
        Throwable $exception,
        array $payload,
        array $headers,
        array $settings,
        string $task_id = ''
    ): array {
        $status = $this->extract_http_status_from_exception($exception);

        if (! in_array($status, array(504, 524), true)) {
            throw $exception;
        }

        // 同步请求超时后，先等待几秒让上游完成处理，然后重试一次同步请求。
        // 上游可能已经生成了图片但响应在传输过程中超时（如用户描述的 524 场景）。
        $this->pause_before_retry(3);

        try {
            return $this->post_json(
                $this->build_endpoint((string) $settings['image_base_url']),
                $payload,
                $headers,
                $this->normalize_image_timeout($settings['image_request_timeout'] ?? 60)
            );
        } catch (Throwable $retry_exception) {
            // 重试仍然失败，走异步补偿路径。
        }

        try {
            return $this->recover_cover_via_async_task($payload, $headers, $settings, $task_id, true);
        } catch (Throwable $fallback_exception) {
            $this->last_cover_task_pending = true;
            throw $exception;
        }
    }

    protected function recover_cover_via_async_task(
        array $payload,
        array $headers,
        array $settings,
        string $task_id = '',
        bool $create_if_missing = true
    ): array
    {
        $base_url = trim((string) ($settings['image_base_url'] ?? ''));

        if ('' === $base_url) {
            throw new RuntimeException('生图接口地址不能为空。');
        }

        $task_id = $this->sanitize_cover_task_id($task_id);

        if ('' === $task_id) {
            $task_id = $this->build_cover_task_id($payload);
        }

        if ('' === $task_id) {
            throw new RuntimeException('无法生成封面图任务编号，请稍后重试。');
        }

        $this->last_cover_task_id = $task_id;

        $task_request_timeout = self::ASYNC_TASK_REQUEST_TIMEOUT_SECONDS;
        $task_created = false;
        $deadline = time() + self::ASYNC_TASK_POLL_MAX_SECONDS;
        $last_status = '';
        $last_error = '';
        $last_diagnostic = '';

        while (time() <= $deadline) {
            $result = $this->fetch_image_task_status($base_url, $task_id, $headers, $task_request_timeout);
            $item = $result['item'];
            $status = $result['status'];
            $last_status = $status;
            $diagnostic = trim((string) ($result['diagnostic'] ?? ''));
            if ('' !== $diagnostic) {
                $last_diagnostic = $diagnostic;
            }

            if ('success' === $status) {
                $data = is_array($item['data'] ?? null) ? $item['data'] : array();
                if (! empty($data)) {
                    return array(
                        'created' => time(),
                        'data'    => $data,
                    );
                }

                break;
            }

            if ('error' === $status) {
                $last_error = trim((string) ($item['error'] ?? ''));
                break;
            }

            if ('' === $status && $create_if_missing && ! $task_created) {
                $this->create_image_task($base_url, $payload, $headers, $task_request_timeout, $task_id);
                $task_created = true;
                $this->pause_before_retry(1);
                continue;
            }

            if ('' === $status && ! $create_if_missing) {
                $last_error = '未找到对应任务';
                break;
            }

            $this->pause_before_retry(self::ASYNC_TASK_POLL_INTERVAL_SECONDS);
        }

        $this->last_cover_task_pending = true;
        $detail = '';

        if ('' !== $last_status) {
            $detail .= '任务状态：' . $last_status . '。';
        }

        if ('' !== $last_error) {
            $detail .= ' 上游返回：' . $last_error;
        }

        if ('' !== $last_diagnostic) {
            $detail .= $last_diagnostic;
        }

        $detail .= ' 任务编号：' . $task_id . '。';

        throw new RuntimeException(
            '生图请求可能已超时，但异步补偿未在预期时间内返回结果。' . $detail . ' 请稍后点击“重试生成封面图”。',
            504
        );
    }

    protected function create_image_task(string $base_url, array $payload, array $headers, int $timeout, string $task_id): void
    {
        $task_endpoint = $this->build_image_task_generation_endpoint($base_url);
        $task_payload = array(
            'client_task_id' => $task_id,
            'prompt'         => (string) ($payload['prompt'] ?? ''),
            'model'          => (string) ($payload['model'] ?? 'gpt-image-2'),
        );

        if (! empty($payload['size']) && is_scalar($payload['size'])) {
            $task_payload['size'] = (string) $payload['size'];
        }

        $result = $this->post_json_no_retry($task_endpoint, $task_payload, $headers, $timeout);

        // 检查上游返回的任务状态，如果是 error 则提前抛出异常，避免后续空轮询。
        $task_status = strtolower(trim((string) ($result['status'] ?? '')));
        if ('error' === $task_status) {
            $error_message = trim((string) ($result['error'] ?? ''));
            throw new RuntimeException(
                '封面图任务创建失败：' . ('' !== $error_message ? $error_message : '上游返回错误状态。')
            );
        }
    }

    protected function build_image_task_generation_endpoint(string $base_url): string
    {
        $root = $this->build_api_root($base_url);

        if ('' === $root) {
            throw new RuntimeException('生图接口地址不能为空。');
        }

        return $root . '/api/image-tasks/generations';
    }

    protected function build_image_task_list_endpoint(string $base_url): string
    {
        $root = $this->build_api_root($base_url);

        if ('' === $root) {
            throw new RuntimeException('生图接口地址不能为空。');
        }

        return $root . '/api/image-tasks';
    }

    protected function build_api_root(string $base_url): string
    {
        $normalized = rtrim(trim($base_url), '/');

        if ('' === $normalized) {
            return '';
        }

        if (preg_match('#/v1/images(?:/generations)?$#', $normalized)) {
            return preg_replace('#/v1/images(?:/generations)?$#', '', $normalized) ?: '';
        }

        if (preg_match('#/v1$#', $normalized)) {
            return preg_replace('#/v1$#', '', $normalized) ?: '';
        }

        return $normalized;
    }

    protected function build_cover_task_id(array $payload): string
    {
        $seed = function_exists('wp_json_encode')
            ? wp_json_encode(
                array(
                    'prompt' => (string) ($payload['prompt'] ?? ''),
                    'model'  => (string) ($payload['model'] ?? ''),
                    'size'   => (string) ($payload['size'] ?? ''),
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
            : json_encode(
                array(
                    'prompt' => (string) ($payload['prompt'] ?? ''),
                    'model'  => (string) ($payload['model'] ?? ''),
                    'size'   => (string) ($payload['size'] ?? ''),
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

        if (! is_string($seed) || '' === $seed) {
            $seed = serialize($payload); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
        }

        $digest = function_exists('hash') ? hash('sha1', $seed) : md5($seed);

        return 'aiditor-cover-' . substr((string) $digest, 0, 24);
    }

    protected function fetch_image_task_status(string $base_url, string $task_id, array $headers, int $timeout): array
    {
        $endpoint = $this->append_query_args(
            $this->build_image_task_list_endpoint($base_url),
            array(
                'ids' => $task_id,
            )
        );
        $response = $this->get_json($endpoint, $headers, $timeout, '查询生图任务状态失败');
        $items = $response['items'] ?? null;

        if (! is_array($items) || empty($items) || ! is_array($items[0] ?? null)) {
            // 上游返回了 missing_ids 时，记录诊断信息便于排查。
            $missing_ids = $response['missing_ids'] ?? array();
            $diagnostic = '';
            if (is_array($missing_ids) && ! empty($missing_ids)) {
                $diagnostic = ' 上游标记缺失：' . implode(', ', $missing_ids);
            }

            return array(
                'status' => '',
                'item'   => array(),
                'diagnostic' => $diagnostic,
            );
        }

        return array(
            'status' => strtolower(trim((string) ($items[0]['status'] ?? ''))),
            'item'   => $items[0],
        );
    }

    protected function append_query_args(string $url, array $args): string
    {
        if (function_exists('add_query_arg')) {
            return add_query_arg($args, $url);
        }

        $query = http_build_query($args);
        if ('' === $query) {
            return $url;
        }

        return $url . (false === strpos($url, '?') ? '?' : '&') . $query;
    }

    protected function post_json_no_retry(string $url, array $payload, array $headers, int $timeout): array
    {
        $sslverify = (bool) apply_filters('aiditor_sslverify', true);
        $body = function_exists('wp_json_encode')
            ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($body)) {
            throw new RuntimeException('生图请求体编码失败。');
        }

        if (function_exists('wp_remote_post')) {
            return $this->post_json_via_wordpress($url, $payload, $headers, max(5, $timeout), $sslverify);
        }

        if (function_exists('curl_init')) {
            return $this->post_json_via_curl($url, $body, $headers, max(5, $timeout), $sslverify);
        }

        throw new RuntimeException('当前环境没有可用的 HTTP 传输能力，无法执行生图请求。');
    }

    protected function get_json(string $url, array $headers, int $timeout, string $error_prefix = '获取生图模型列表失败'): array
    {
        $sslverify = (bool) apply_filters('aiditor_sslverify', true);

        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get(
                $url,
                array(
                    'timeout'   => max(5, $timeout),
                    'sslverify' => $sslverify,
                    'headers'   => $headers,
                )
            );

            if (is_wp_error($response)) {
                throw new RuntimeException($response->get_error_message());
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $response_body = (string) wp_remote_retrieve_body($response);

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(sprintf('%s，HTTP 状态码为 %d。', $error_prefix, $status));
            }
        } elseif (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array(
                $curl,
                array(
                    CURLOPT_HTTPGET        => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => max(5, $timeout),
                    CURLOPT_SSL_VERIFYPEER => $sslverify,
                    CURLOPT_SSL_VERIFYHOST => $sslverify ? 2 : 0,
                    CURLOPT_HTTPHEADER     => $this->headers_to_array($headers),
                )
            );

            $response_body = curl_exec($curl);
            if (false === $response_body) {
                $error = curl_error($curl);
                curl_close($curl);
                throw new RuntimeException($error_prefix . '：' . $error);
            }

            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(sprintf('%s，HTTP 状态码为 %d。', $error_prefix, $status));
            }

            $response_body = (string) $response_body;
        } else {
            throw new RuntimeException($error_prefix . '：当前环境没有可用的 HTTP 传输能力。');
        }

        $decoded = json_decode($response_body, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('生图模型列表接口返回了无效的 JSON 数据。');
        }

        return $decoded;
    }

    protected function headers_to_array(array $headers): array
    {
        $lines = array();

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    protected function build_http_exception(string $prefix, int $status, string $response_body): RuntimeException
    {
        $message = sprintf('%s，HTTP 状态码为 %d。', $prefix, $status);
        $details = $this->extract_error_message($response_body);

        if ('' !== $details) {
            $message .= ' 上游返回：' . $details;
        }

        return new RuntimeException($message, $status);
    }

    protected function extract_error_message(string $response_body): string
    {
        $response_body = trim($response_body);

        if ('' === $response_body) {
            return '';
        }

        $decoded = json_decode($response_body, true);

        if (is_array($decoded)) {
            $candidates = array(
                $decoded['error']['message'] ?? null,
                $decoded['message'] ?? null,
                $decoded['error'] ?? null,
            );

            foreach ($candidates as $candidate) {
                if (is_scalar($candidate)) {
                    $value = trim((string) $candidate);
                    if ('' !== $value) {
                        return $value;
                    }
                }
            }
        }

        return $this->limit_plain_text($response_body, 300);
    }

    protected function is_retryable_exception(Throwable $exception): bool
    {
        $status = $this->extract_http_status_from_exception($exception);

        if ($status > 0) {
            return in_array($status, self::RETRYABLE_HTTP_STATUSES, true);
        }

        return false;
    }

    protected function extract_http_status_from_exception(Throwable $exception): int
    {
        $status = (int) $exception->getCode();

        if ($status >= 100 && $status <= 599) {
            return $status;
        }

        return $this->extract_http_status_from_message($exception->getMessage());
    }

    protected function extract_http_status_from_message(string $message): int
    {
        if (! function_exists('preg_match')) {
            return 0;
        }

        if (preg_match('/(?:http(?:\s+状态码为)?\s*|status(?:\s*code)?\s*[:=]?\s*)(\d{3})/iu', $message, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    protected function pause_before_retry(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        sleep($seconds);
    }

    protected function extract_image_url(array $response): string
    {
        $data = $response['data'] ?? null;

        if (is_array($data) && ! empty($data)) {
            $first = $data[0] ?? null;

            if (is_array($first)) {
                $url = trim((string) ($first['url'] ?? ''));

                if ('' !== $url) {
                    return $url;
                }

                $encoded = trim((string) ($first['b64_json'] ?? ''));

                if ('' !== $encoded) {
                    return $this->persist_base64_image($encoded);
                }
            }
        }

        $urls = $response['urls'] ?? null;

        if (is_array($urls) && ! empty($urls)) {
            $first_url = trim((string) ($urls[0] ?? ''));

            if ('' !== $first_url) {
                return $first_url;
            }
        }

        $url = trim((string) ($response['url'] ?? ''));

        if ('' !== $url) {
            return $url;
        }

        throw new RuntimeException('生图接口未返回可用图片地址。');
    }

    protected function extract_models(array $response): array
    {
        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            return array();
        }

        $models = array();

        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            if ('' === $id || ! $this->is_image_model_item($item, $id)) {
                continue;
            }

            $models[] = array(
                'id'    => $id,
                'label' => $id,
            );
        }

        usort(
            $models,
            static function (array $left, array $right): int {
                return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
            }
        );

        return $models;
    }

    protected function is_image_model_item(array $item, string $id): bool
    {
        $type = strtolower(trim((string) ($item['type'] ?? '')));
        if ('image' === $type) {
            return true;
        }

        $modality = strtolower(trim((string) ($item['modality'] ?? '')));
        if ('' !== $modality) {
            $segments = preg_split('/[^a-z0-9]+/i', $modality) ?: array();
            if (in_array('image', array_map('strtolower', $segments), true)) {
                return true;
            }
        }

        $id_lower = strtolower($id);
        $name_lower = strtolower(trim((string) ($item['name'] ?? '')));
        $owned_by_lower = strtolower(trim((string) ($item['owned_by'] ?? '')));
        $combined = $id_lower . ' ' . $name_lower . ' ' . $owned_by_lower;

        if (false !== strpos($id_lower, 'gpt-image') || false !== strpos($id_lower, 'codex-gpt-image')) {
            return true;
        }

        if (false !== strpos($name_lower, 'gpt-image') || false !== strpos($name_lower, 'codex-gpt-image')) {
            return true;
        }

        if (false !== strpos($owned_by_lower, 'gpt-image') || false !== strpos($owned_by_lower, 'codex-gpt-image')) {
            return true;
        }

        return false !== strpos($combined, 'gpt-image') || false !== strpos($combined, 'codex-gpt-image');
    }


    protected function persist_base64_image(string $encoded): string
    {
        $bytes = base64_decode($encoded, true);

        if (false === $bytes || '' === $bytes) {
            throw new RuntimeException('生图接口返回的 base64 图片无效。');
        }

        $upload = function_exists('wp_upload_bits')
            ? wp_upload_bits('aiditor-cover-' . time() . '.png', null, $bytes)
            : array('error' => '当前环境不支持写入上传目录。');

        if (! is_array($upload) || ! empty($upload['error'])) {
            throw new RuntimeException('保存 base64 封面图失败。');
        }

        return (string) ($upload['url'] ?? '');
    }
}
