<?php
declare(strict_types=1);

class AIditor_AI_Request_Exception extends RuntimeException
{
    protected int $status_code;

    protected bool $retryable;

    protected ?int $retry_after_seconds;

    public function __construct(string $message, int $status_code = 0, bool $retryable = false, ?int $retry_after_seconds = null)
    {
        parent::__construct($message, $status_code);

        $this->status_code          = $status_code;
        $this->retryable            = $retryable;
        $this->retry_after_seconds  = $retry_after_seconds;
    }

    public function get_status_code(): int
    {
        return $this->status_code;
    }

    public function is_retryable(): bool
    {
        return $this->retryable;
    }

    public function get_retry_after_seconds(): ?int
    {
        return $this->retry_after_seconds;
    }
}

class AIditor_AI_Rewriter
{
    protected const INLINE_RETRY_DELAYS = array(2, 5, 12);

    protected AIditor_Settings $settings;

    protected ?AIditor_Article_Style_Repository $style_repository;

    public function __construct(AIditor_Settings $settings, ?AIditor_Article_Style_Repository $style_repository = null)
    {
        $this->settings         = $settings;
        $this->style_repository = $style_repository;
    }

    public function rewrite(array $normalized_payload, array $research_packet = array()): array
    {
        $settings = $this->settings->get();

        if (! $this->has_usable_model_settings($settings)) {
            throw new RuntimeException('缺少可用 AI 模型配置，请先在插件设置中新增并保存模型。');
        }

        $fallback_excerpt = trim((string) ($normalized_payload['source_summary_zh'] ?? $normalized_payload['source_summary'] ?? ''));
        $endpoint         = $this->build_endpoint((string) $settings['base_url']);
        $headers          = array(
            'Authorization' => 'Bearer ' . trim((string) $settings['api_key']),
            'Content-Type'  => 'application/json',
        );
        $payload          = $this->build_completion_payload($normalized_payload, $settings);

        $article = null;
        $last_exception = null;

        $max_attempts = count(self::INLINE_RETRY_DELAYS) + 1;

        for ($attempt = 1; $attempt <= $max_attempts; ++$attempt) {
            try {
                $response = $this->post_json(
                    $endpoint,
                    $payload,
                    $headers,
                    (int) $settings['request_timeout']
                );

                $content = $this->extract_message_content($response);
                $article = $this->decode_article_payload(
                    $content,
                    (string) ($normalized_payload['source_title'] ?? ''),
                    $fallback_excerpt
                );
                break;
            } catch (Throwable $exception) {
                $last_exception = $this->normalize_runtime_exception($exception);

                if (! $this->is_retryable_exception($last_exception) || $attempt >= $max_attempts) {
                    throw $last_exception;
                }

                $this->pause_before_retry(
                    $this->calculate_inline_retry_delay_seconds($attempt, $last_exception)
                );
            }
        }

        if (! is_array($article)) {
            if ($last_exception instanceof RuntimeException) {
                throw $last_exception;
            }

            throw new RuntimeException('AI 重构失败，未获得可用响应。');
        }

        $article['ai_model']        = (string) $settings['model'];
        $article['ai_generated_at'] = gmdate('c');

        return $article;
    }

    public function rewrite_fields(array $fields, array $field_schema, array $rewrite_fields, string $instruction = '', array $runtime_settings = array()): array
    {
        $targets = $this->normalize_rewrite_field_targets($fields, $field_schema, $rewrite_fields);

        if (empty($targets)) {
            return $fields;
        }

        $settings = empty($runtime_settings)
            ? $this->settings->get()
            : array_replace($this->settings->get(), $runtime_settings);

        if (! $this->has_usable_model_settings($settings)) {
            throw new RuntimeException('缺少可用 AI 模型配置，请先在插件设置中新增并保存模型。');
        }

        $endpoint = $this->build_endpoint((string) $settings['base_url']);
        $headers  = array(
            'Authorization' => 'Bearer ' . trim((string) $settings['api_key']),
            'Content-Type'  => 'application/json',
        );
        $payload  = $this->build_field_rewrite_payload($fields, $field_schema, $targets, $instruction, $settings);

        $rewritten = null;
        $last_exception = null;
        $max_attempts = count(self::INLINE_RETRY_DELAYS) + 1;

        for ($attempt = 1; $attempt <= $max_attempts; ++$attempt) {
            try {
                $response = $this->post_json(
                    $endpoint,
                    $payload,
                    $headers,
                    (int) $settings['request_timeout']
                );

                $rewritten = $this->decode_rewritten_fields_payload($this->extract_message_content($response));
                break;
            } catch (Throwable $exception) {
                $last_exception = $this->normalize_runtime_exception($exception);

                if (! $this->is_retryable_exception($last_exception) || $attempt >= $max_attempts) {
                    throw $last_exception;
                }

                $this->pause_before_retry(
                    $this->calculate_inline_retry_delay_seconds($attempt, $last_exception)
                );
            }
        }

        if (! is_array($rewritten)) {
            if ($last_exception instanceof RuntimeException) {
                throw $last_exception;
            }

            throw new RuntimeException('AI 字段重写失败，未获得可用响应。');
        }

        return $this->merge_rewritten_fields($fields, $rewritten, $targets);
    }

    protected function build_completion_payload(array $normalized_payload, array $settings): array
    {
        $temperature = isset($settings['temperature']) ? (float) $settings['temperature'] : 0.2;
        $temperature = max(0.0, min($temperature, 0.35));

        return array(
            'model'       => (string) $settings['model'],
            'temperature' => $temperature,
            'max_tokens'  => max(256, (int) $settings['max_tokens']),
            'messages'    => array(
                array(
                    'role'    => 'system',
                    'content' => $this->build_system_prompt(
                        (string) ($settings['default_article_style'] ?? 'editorial-guide')
                    ),
                ),
                array(
                    'role'    => 'user',
                    'content' => $this->build_user_prompt(
                        $normalized_payload
                    ),
                ),
            ),
        );
    }

    protected function build_field_rewrite_payload(array $fields, array $field_schema, array $targets, string $instruction, array $settings): array
    {
        $temperature = isset($settings['temperature']) ? (float) $settings['temperature'] : 0.2;
        $temperature = max(0.0, min($temperature, 0.45));

        return array(
            'model'       => (string) $settings['model'],
            'temperature' => $temperature,
            'max_tokens'  => max(1200, (int) $settings['max_tokens']),
            'messages'    => array(
                array(
                    'role'    => 'system',
                    'content' => $this->build_field_rewrite_system_prompt(
                        (string) ($settings['default_article_style'] ?? 'editorial-guide')
                    ),
                ),
                array(
                    'role'    => 'user',
                    'content' => $this->build_field_rewrite_user_prompt($fields, $field_schema, $targets, $instruction),
                ),
            ),
        );
    }

    protected function has_usable_model_settings(array $settings): bool
    {
        return '' !== trim((string) ($settings['base_url'] ?? ''))
            && '' !== trim((string) ($settings['api_key'] ?? ''))
            && '' !== trim((string) ($settings['model'] ?? ''));
    }

    protected function build_field_rewrite_system_prompt(string $article_style): string
    {
        $style = $this->resolve_article_style_prompt($article_style);

        return implode(
            "\n",
            array(
                '你是专业中文内容编辑，负责把网页抽取字段改写成适合 WordPress 入库的中文内容。',
                '只允许重写用户指定的字段，不得新增未指定字段，不得改写 URL、图片、日期、数字等事实字段。',
                '必须保留事实含义，不得编造来源页面没有的信息，不得添加虚假功能、价格、机构、时间或链接。',
                '标题字段如果被指定，只做必要润色，保持专有名词、产品名和事实主体，不要标题党。',
                '摘要字段应写成自然、克制的信息摘要，不要机械翻译腔、营销腔或“本文介绍了”式模板句。',
                '正文字段应输出适合 Gutenberg 入库的 HTML 片段，可以包含 <p>、<h2>、<ol>、<ul>、<li>、<strong>、<em>，不要输出 H1。',
                '不要输出来源网址、跳转提示、“详情可查看”、免责声明、AI 生成痕迹或 Markdown 代码块。',
                '返回严格 JSON 对象，key 必须与被重写字段 key 一致。',
                '写作风格遵循：' . $style,
            )
        );
    }

    protected function build_field_rewrite_user_prompt(array $fields, array $field_schema, array $targets, string $instruction): string
    {
        $json_encode = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';
        $target_fields = array();
        $schema_map = $this->map_field_schema($field_schema);

        foreach ($targets as $key) {
            $target_fields[$key] = array(
                'field' => $schema_map[$key] ?? array('key' => $key, 'label' => $key, 'type' => 'text'),
                'value' => $fields[$key] ?? '',
            );
        }

        return implode(
            "\n\n",
            array(
                '采集需求：' . trim($instruction),
                '全部抽取字段上下文：' . (string) $json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                '需要重写的字段：' . (string) $json_encode($target_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                '字段输出要求：text 字段返回纯文本；textarea 字段返回纯文本自然段；html 字段返回可直接写入 WordPress 正文的 HTML 片段。',
                '只返回需要重写字段的 JSON，例如 {"summary":"...","content":"<p>...</p>"}。不要返回 notes、confidence 或解释文字。',
            )
        );
    }

    protected function build_endpoint(string $base_url): string
    {
        $base_url = rtrim(trim($base_url), '/');

        if (preg_match('#/chat/completions$#', $base_url)) {
            return $base_url;
        }

        return $base_url . '/chat/completions';
    }

    protected function build_system_prompt(string $article_style): string
    {
        $style = $this->resolve_article_style_prompt($article_style);

        return implode(
            "\n",
            array(
                'You are a professional Chinese tech editor writing readable introductions for developer tools.',
                'The title and section headings are fixed externally. Never output a title, H1, H2, or any section heading yourself.',
                'Use only the evidence package. Do not invent unsupported capabilities.',
                'Write natural Simplified Chinese for human readers, not marketing copy and not literal translation.',
                'Avoid template phrases, checklist tone, and generic filler.',
                'Do not output source links, external URLs, or any line like "详情可查看".',
                'Return JSON only. No markdown fences.',
                'JSON fields: overview_html, feature_items, scenarios_html.',
                'overview_html must contain 2 to 4 <p> paragraphs only, with no headings.',
                'feature_items must be an array of 3 to 6 concise strings for a numbered list.',
                'scenarios_html must contain 2 to 4 <p> paragraphs only, with no headings.',
                'The total Chinese article should read like a normal news-style introduction with about 1000 to 2000 Chinese characters.',
                'Avoid tables, code blocks, and long bullet lists inside the HTML fields.',
                'The writing style should follow this profile: ' . $style . '.',
            )
        );
    }

    protected function resolve_article_style_prompt(string $article_style): string
    {
        if ($this->style_repository instanceof AIditor_Article_Style_Repository) {
            $style = $this->style_repository->get($article_style);

            if (is_array($style) && '' !== trim((string) ($style['prompt'] ?? ''))) {
                return trim((string) $style['prompt']);
            }
        }

        return 'editorial-guide' === $article_style
            ? '像专业中文科技编辑一样写作：自然、克制、信息密度高，避免硬广、模板句和机械翻译腔。'
            : $article_style;
    }

    protected function build_user_prompt(array $normalized_payload): string
    {
        $evidence = array(
            'title'   => (string) ($normalized_payload['source_title'] ?? ''),
            'summary' => trim((string) ($normalized_payload['source_summary_zh'] ?? $normalized_payload['source_summary'] ?? '')),
            'content' => $this->condense_markdown((string) ($normalized_payload['source_markdown'] ?? '')),
        );

        $json_encode = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';

        $instructions = array(
            '请根据证据包生成固定结构文章的三个内容区块，目标是让读者快速知道这个工具是什么、核心功能特点是什么、适合什么场景。',
            '系统会把你的输出渲染成以下固定结构：开篇介绍正文、核心功能特点、适用场景。',
            '你只需要填写各区块内容，不要自己输出标题、分节标题、来源网址、跳转提示或“详情可查看”。',
            '不要写安装教程堆砌，不要写代码规范、函数设计建议，也不要逐段翻译原文。',
            '全文应写成一篇正常中文资讯介绍文章的长度，约 1000 到 2000 字，信息密度明显高于摘要。',
            'overview_html 和 scenarios_html 都应使用自然段展开，不要只写一两句空泛概述。',
            'feature_items 必须是 3 到 6 条可读性强的独立要点，适合放进 1. 2. 3. 的编号列表里。',
            '证据包：' . (string) $json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return implode(
            "\n\n",
            $instructions
        );
    }

    protected function post_json(string $url, array $payload, array $headers, int $timeout): array
    {
        $sslverify = $this->should_verify_ssl();
        $body = function_exists('wp_json_encode')
            ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($body)) {
            throw new RuntimeException('AI 请求体编码失败。');
        }

        if (function_exists('wp_remote_post')) {
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
                throw $this->create_request_exception($response->get_error_message());
            }

            $status       = (int) wp_remote_retrieve_response_code($response);
            $response_body = (string) wp_remote_retrieve_body($response);
            $retry_after   = $this->parse_retry_after(
                is_string(wp_remote_retrieve_header($response, 'retry-after'))
                    ? wp_remote_retrieve_header($response, 'retry-after')
                    : ''
            );

            if ($status < 200 || $status >= 300) {
                throw $this->create_request_exception(
                    sprintf('AI 重构请求失败，HTTP 状态码为 %d。', $status),
                    $status,
                    $retry_after
                );
            }
        } elseif (function_exists('curl_init')) {
            $curl = curl_init($url);
            $response_headers = array();
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
                    CURLOPT_HEADERFUNCTION => function ($curl_handle, string $header_line) use (&$response_headers): int {
                        $parts = explode(':', $header_line, 2);

                        if (2 === count($parts)) {
                            $response_headers[strtolower(trim((string) $parts[0]))] = trim((string) $parts[1]);
                        }

                        return strlen($header_line);
                    },
                )
            );

            $response_body = curl_exec($curl);
            if (false === $response_body) {
                $error = curl_error($curl);
                curl_close($curl);
                throw $this->create_request_exception('AI 重构请求失败：' . $error);
            }

            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if ($status < 200 || $status >= 300) {
                throw $this->create_request_exception(
                    sprintf('AI 重构请求失败，HTTP 状态码为 %d。', $status),
                    $status,
                    $this->parse_retry_after((string) ($response_headers['retry-after'] ?? ''))
                );
            }

            $response_body = (string) $response_body;
        } else {
            throw new RuntimeException('当前环境没有可用的 HTTP 传输能力，无法执行 AI 重构请求。');
        }

        $decoded = json_decode($response_body, true);
        if (! is_array($decoded)) {
            throw $this->create_request_exception('AI 服务返回了无效的 JSON 数据。', 200, null, true);
        }

        return $decoded;
    }

    protected function extract_message_content(array $response): string
    {
        $message = $response['choices'][0]['message']['content'] ?? null;

        if (is_string($message) && '' !== trim($message)) {
            return trim($message);
        }

        if (is_array($message)) {
            $buffer = array();
            foreach ($message as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $buffer[] = $part['text'];
                }
            }

            $joined = trim(implode("\n", $buffer));
            if ('' !== $joined) {
                return $joined;
            }
        }

        throw $this->create_request_exception('AI 服务未返回可用的完成结果。', 200, null, true);
    }

    protected function decode_article_payload(string $content, string $source_title, string $fallback_excerpt): array
    {
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            $html = trim($content);
            if ('' === $html) {
                throw new RuntimeException('AI 返回结果中不包含有效的 JSON。');
            }

            if (function_exists('wp_kses_post')) {
                $html = wp_kses_post($html);
            }

            $html = AIditor_Content_Normalizer::strip_leading_title_heading($html, $source_title);

            return array(
                'article_excerpt' => $fallback_excerpt,
                'article_html'    => $html,
                'seo_title'       => trim($source_title),
                'seo_description' => trim($fallback_excerpt),
                'suggested_tags'  => array(),
            );
        }

        $excerpt = trim((string) ($decoded['article_excerpt'] ?? $fallback_excerpt));
        $overview_html = trim((string) ($decoded['overview_html'] ?? ''));
        $scenarios_html = trim((string) ($decoded['scenarios_html'] ?? ''));
        $feature_items = $this->normalize_feature_items($decoded['feature_items'] ?? array());
        $html    = '';

        if ('' !== $overview_html && '' !== $scenarios_html && ! empty($feature_items)) {
            $html = $this->build_structured_article_html($source_title, $overview_html, $feature_items, $scenarios_html);
        } else {
            $html = trim((string) ($decoded['article_html'] ?? $decoded['content'] ?? ''));
        }

        if ('' === $html) {
            throw new RuntimeException('AI 返回结果缺少必需的文章字段。');
        }

        if (function_exists('wp_kses_post')) {
            $html = wp_kses_post($html);
        }

        $html = AIditor_Content_Normalizer::strip_leading_title_heading($html, $source_title);

        return array(
            'article_excerpt'   => $excerpt,
            'article_html'      => $html,
            'seo_title'         => trim($source_title),
            'seo_description'   => trim($excerpt),
            'suggested_tags'    => array(),
        );
    }

    protected function decode_rewritten_fields_payload(string $content): array
    {
        $content = trim($content);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI 字段重写结果不是有效的 JSON 对象。');
        }

        return $decoded;
    }

    protected function normalize_rewrite_field_targets(array $fields, array $field_schema, array $rewrite_fields): array
    {
        $schema_map = $this->map_field_schema($field_schema);
        $targets = array();

        foreach ($rewrite_fields as $field) {
            $key = $this->sanitize_field_key((string) $field);

            if ('' === $key || ! array_key_exists($key, $fields)) {
                continue;
            }

            if (! $this->is_rewrite_candidate_field($schema_map[$key] ?? array('key' => $key, 'type' => 'text'))) {
                continue;
            }

            $targets[] = $key;
        }

        return array_values(array_unique($targets));
    }

    protected function map_field_schema(array $field_schema): array
    {
        $map = array();

        foreach ($field_schema as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = $this->sanitize_field_key((string) ($field['key'] ?? ''));
            if ('' !== $key) {
                $map[$key] = $field;
            }
        }

        return $map;
    }

    protected function is_rewrite_candidate_field(array $field): bool
    {
        $key  = $this->sanitize_field_key((string) ($field['key'] ?? ''));
        $type = (string) ($field['type'] ?? 'text');

        if ('' === $key || ! in_array($type, array('text', 'textarea', 'html'), true)) {
            return false;
        }

        if (preg_match('/(^|_)(url|link|links|image|img|photo|cover|homepage|website)(_|$)/i', $key)) {
            return false;
        }

        return true;
    }

    protected function sanitize_field_key(string $key): string
    {
        if (function_exists('sanitize_key')) {
            return sanitize_key($key);
        }

        $key = strtolower($key);

        return preg_replace('/[^a-z0-9_\-]/', '', $key) ?: '';
    }

    protected function merge_rewritten_fields(array $fields, array $rewritten, array $targets): array
    {
        foreach ($targets as $key) {
            if (! array_key_exists($key, $rewritten)) {
                continue;
            }

            $value = $rewritten[$key];

            if (is_array($value) || is_object($value)) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                if ('' === $value) {
                    continue;
                }
            }

            $fields[$key] = $value;
        }

        return $fields;
    }

    public function is_retryable_exception(Throwable $exception): bool
    {
        if ($exception instanceof AIditor_AI_Request_Exception) {
            return $exception->is_retryable();
        }

        $message = mb_strtolower($exception->getMessage(), 'UTF-8');
        $status  = $this->extract_http_status_from_message($message);

        if ($status > 0) {
            return $this->is_retryable_http_status($status);
        }

        $transient_needles = array(
            'timed out',
            'timeout',
            'connection reset',
            'connection refused',
            'empty reply from server',
            'temporarily unavailable',
            'could not resolve host',
            'name resolution',
            'ssl connect error',
            'tls',
            'gateway timeout',
            'http 524',
        );

        foreach ($transient_needles as $needle) {
            if (false !== strpos($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function get_retry_after_seconds(Throwable $exception): ?int
    {
        if ($exception instanceof AIditor_AI_Request_Exception) {
            return $exception->get_retry_after_seconds();
        }

        return null;
    }

    protected function build_structured_article_html(string $source_title, string $overview_html, array $feature_items, string $scenarios_html): string
    {
        $overview_html = $this->sanitize_section_html($overview_html);
        $scenarios_html = $this->sanitize_section_html($scenarios_html);

        $feature_list_items = array();

        foreach ($feature_items as $feature_item) {
            $feature_item = trim($feature_item);

            if ('' === $feature_item) {
                continue;
            }

            $feature_list_items[] = '<li>' . $this->sanitize_inline_html($feature_item) . '</li>';
        }

        if (empty($feature_list_items)) {
            throw new RuntimeException('AI 返回结果缺少可用的功能要点。');
        }

        return implode(
            "\n",
            array(
                $overview_html,
                '<h2>核心功能特点</h2>',
                '<ol>' . implode('', $feature_list_items) . '</ol>',
                '<h2>适用场景</h2>',
                $scenarios_html,
            )
        );
    }

    protected function normalize_feature_items($items): array
    {
        if (! is_array($items)) {
            return array();
        }

        $normalized = array();

        foreach ($items as $item) {
            if (is_string($item) && '' !== trim($item)) {
                $normalized[] = trim($item);
            }
        }

        return array_values(array_unique($normalized));
    }

    protected function normalize_tags($tags): array
    {
        if (! is_array($tags)) {
            return array();
        }

        $normalized = array();

        foreach ($tags as $tag) {
            if (is_string($tag) && '' !== trim($tag)) {
                $normalized[] = trim($tag);
            }
        }

        return array_values(array_unique($normalized));
    }

    protected function should_verify_ssl(): bool
    {
        if (function_exists('apply_filters')) {
            return (bool) apply_filters('aiditor_sslverify', false);
        }

        return false;
    }

    protected function headers_to_array(array $headers): array
    {
        $lines = array();

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    protected function sanitize_section_html(string $html): string
    {
        $html = trim($html);

        if ('' === $html) {
            return '';
        }

        if (function_exists('wp_kses_post')) {
            $html = wp_kses_post($html);
        }

        $html = preg_replace('/<\/?(h[1-6]|ol|ul|li)[^>]*>/i', '', $html);
        $html = trim((string) $html);

        if ('' === $html) {
            return '';
        }

        if (! preg_match('/<p[\s>]/i', $html)) {
            $html = '<p>' . $html . '</p>';
        }

        return $html;
    }

    protected function sanitize_inline_html(string $html): string
    {
        $html = trim($html);

        if ('' === $html) {
            return '';
        }

        if (function_exists('wp_kses')) {
            $html = wp_kses(
                $html,
                array(
                    'code'   => array(),
                    'strong' => array(),
                    'em'     => array(),
                    'b'      => array(),
                    'i'      => array(),
                )
            );
        }

        return trim($html);
    }

    protected function escape_html(string $text): string
    {
        if (function_exists('esc_html')) {
            return esc_html($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function condense_markdown(string $markdown): string
    {
        $markdown = str_replace(array("\r\n", "\r"), "\n", $markdown);
        $markdown = preg_replace('/```([^\n`]*)\n(.*?)```/s', "\n$2\n", $markdown);
        $markdown = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', (string) $markdown);
        $markdown = preg_replace('/^\s{0,3}>\s?/m', '', (string) $markdown);
        $markdown = preg_replace('/^\s*[-*+]\s+/m', '', (string) $markdown);
        $markdown = preg_replace('/[ \t]+/u', ' ', (string) $markdown);
        $markdown = preg_replace("/\n{3,}/u", "\n\n", (string) $markdown);

        return trim((string) $markdown);
    }

    protected function create_request_exception(string $message, int $status_code = 0, ?int $retry_after_seconds = null, ?bool $retryable = null): AIditor_AI_Request_Exception
    {
        if (null === $retryable) {
            $retryable = $status_code > 0
                ? $this->is_retryable_http_status($status_code)
                : $this->is_retryable_exception(new RuntimeException($message));
        }

        return new AIditor_AI_Request_Exception(
            $message,
            $status_code,
            $retryable,
            $retry_after_seconds
        );
    }

    protected function is_retryable_http_status(int $status_code): bool
    {
        return in_array($status_code, array(408, 409, 425, 429, 500, 502, 503, 504, 524), true);
    }

    protected function extract_http_status_from_message(string $message): int
    {
        if (preg_match('/http(?:\s+状态码为)?\s*(\d{3})/iu', $message, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    protected function parse_retry_after(string $value): ?int
    {
        $value = trim($value);

        if ('' === $value) {
            return null;
        }

        if (ctype_digit($value)) {
            return max(1, (int) $value);
        }

        $timestamp = strtotime($value);
        if (false === $timestamp) {
            return null;
        }

        return max(1, $timestamp - time());
    }

    protected function normalize_runtime_exception(Throwable $exception): RuntimeException
    {
        if ($exception instanceof RuntimeException) {
            return $exception;
        }

        return new RuntimeException($exception->getMessage(), (int) $exception->getCode(), $exception);
    }

    protected function calculate_inline_retry_delay_seconds(int $attempt, Throwable $exception): int
    {
        $retry_after = $this->get_retry_after_seconds($exception);
        if (null !== $retry_after) {
            return max(1, min(30, $retry_after));
        }

        $index = min(count(self::INLINE_RETRY_DELAYS) - 1, max(0, $attempt - 1));
        $base  = self::INLINE_RETRY_DELAYS[$index];

        return $base + random_int(0, 1);
    }

    protected function pause_before_retry(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        sleep($seconds);
    }
}
