<?php
declare(strict_types=1);

class AIditor_AI_Extractor
{
    protected const INLINE_RETRY_DELAYS = array(2, 5, 12);

    protected AIditor_Settings $settings;

    protected AIditor_AI_Rewriter $rewriter;

    public function __construct(AIditor_Settings $settings, AIditor_AI_Rewriter $rewriter)
    {
        $this->settings = $settings;
        $this->rewriter = $rewriter;
    }

    public function discover_detail_urls(array $page, string $requirement, int $limit = 20, array $runtime_settings = array()): array
    {
        $limit = max(1, min(100, $limit));

        $schema = array(
            array('key' => 'items', 'label' => '详情页列表', 'type' => 'array', 'required' => true),
            array('key' => 'pagination', 'label' => '分页或加载方式', 'type' => 'array', 'required' => false),
        );

        $result = $this->extract_fields(
            $page,
            $schema,
            implode(
                "\n",
                array(
                    '请从列表页中识别真实详情页 URL。',
                    '如果页面包含分页、下一页、加载更多、接口线索，也请在 pagination 中说明。',
                    '用户需求：' . $requirement,
                    '最多返回 ' . $limit . ' 条 items。',
                    'items 每项字段：url、title、reason、confidence。',
                )
            ),
            $runtime_settings
        );

        $items = is_array($result['items'] ?? null) ? $result['items'] : array();
        $clean = array();

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = esc_url_raw((string) ($item['url'] ?? ''));
            if ('' === $url) {
                continue;
            }

            $clean[] = array(
                'url'        => $url,
                'title'      => trim((string) ($item['title'] ?? '')),
                'reason'     => trim((string) ($item['reason'] ?? '')),
                'confidence' => max(0, min(100, (int) ($item['confidence'] ?? 0))),
            );

            if (count($clean) >= $limit) {
                break;
            }
        }

        return array(
            'items'      => $clean,
            'pagination' => is_array($result['pagination'] ?? null) ? $result['pagination'] : array(),
        );
    }

    public function extract_fields(array $page, array $field_schema, string $instruction, array $runtime_settings = array()): array
    {
        $settings = empty($runtime_settings)
            ? $this->settings->get()
            : array_replace($this->settings->get(), $runtime_settings);

        if (
            '' === trim((string) ($settings['base_url'] ?? ''))
            || '' === trim((string) ($settings['api_key'] ?? ''))
            || '' === trim((string) ($settings['model'] ?? ''))
        ) {
            throw new RuntimeException('缺少可用 AI 模型配置，请先在插件设置中新增并保存模型。');
        }

        $last_exception = null;
        $max_attempts = count(self::INLINE_RETRY_DELAYS) + 1;

        for ($attempt = 1; $attempt <= $max_attempts; ++$attempt) {
            try {
                $payload = $this->build_completion_payload($page, $field_schema, $instruction, $settings, $attempt);
                $response = $this->post_completion($payload, $settings);
                $content  = $this->extract_message_content($response);

                return $this->decode_json_object($content);
            } catch (Throwable $exception) {
                $last_exception = $exception;

                if (! $this->rewriter->is_retryable_exception($exception) || $attempt >= $max_attempts) {
                    throw $exception;
                }

                $this->pause_before_retry($attempt);
            }
        }

        if ($last_exception instanceof Throwable) {
            throw $last_exception;
        }

        throw new RuntimeException('AI 抽取失败，未获得可用响应。');
    }

    protected function build_completion_payload(array $page, array $field_schema, string $instruction, array $settings, int $attempt): array
    {
        $limits = $this->get_prompt_limits_for_attempt($page, $attempt);

        return array(
            'model'       => (string) $settings['model'],
            'temperature' => max(0.0, min(0.2, (float) ($settings['temperature'] ?? 0.1))),
            'max_tokens'  => max(1200, min(12000, (int) ($settings['max_tokens'] ?? 3200))),
            'messages'    => array(
                array(
                    'role'    => 'system',
                    'content' => $this->build_system_prompt(),
                ),
                array(
                    'role'    => 'user',
                    'content' => $this->build_user_prompt(
                        $page,
                        $field_schema,
                        $instruction,
                        $limits['main_text'],
                        $limits['candidate_text'],
                        $limits['links']
                    ),
                ),
            ),
        );
    }

    protected function get_prompt_limits_for_attempt(array $page, int $attempt): array
    {
        $page_text_length = $this->text_length((string) ($page['text'] ?? ''));

        if ($attempt <= 1) {
            if ($page_text_length > 40000) {
                return array('main_text' => 8000, 'candidate_text' => 1200, 'links' => 40);
            }

            if ($page_text_length > 20000) {
                return array('main_text' => 12000, 'candidate_text' => 1600, 'links' => 60);
            }

            return array('main_text' => 50000, 'candidate_text' => 6000, 'links' => 220);
        }

        if (2 === $attempt) {
            if ($page_text_length > 40000) {
                return array('main_text' => 4000, 'candidate_text' => 800, 'links' => 20);
            }

            if ($page_text_length > 20000) {
                return array('main_text' => 6000, 'candidate_text' => 1000, 'links' => 30);
            }

            return array('main_text' => 28000, 'candidate_text' => 3500, 'links' => 140);
        }

        return array('main_text' => 2000, 'candidate_text' => 500, 'links' => 10);
    }

    protected function build_system_prompt(): string
    {
        return implode(
            "\n",
            array(
                '你是一个网页信息抽取助手，任务是根据用户的日常语言需求，从网页文本和链接中提取结构化字段。',
                '只根据提供的页面证据抽取，不要编造页面不存在的信息。',
                '如果字段找不到，返回空字符串、空数组或 null，并在 confidence 中降低置信度。',
                '返回严格 JSON 对象，不要使用 Markdown 代码块，不要解释过程。',
                'URL 字段必须返回绝对 URL。',
                'image、cover_image_url、thumbnail 或 featured_image 字段必须返回远程图片绝对 URL，不要返回 HTML 或 Markdown。',
                '抽取 content 或 body 字段时，只能返回网页正文主体，不要包含站点导航、页眉、页脚、面包屑、栏目名、发布时间、阅读量、分享按钮、相关推荐、上一篇/下一篇、评论区或广告文本。',
                'content 字段不要重复标题；标题请只放在 title 字段。',
            )
        );
    }

    protected function build_user_prompt(array $page, array $field_schema, string $instruction, int $main_text_limit = 50000, int $candidate_text_limit = 6000, int $link_limit = 220): string
    {
        $json_encode = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';
        $content_candidates = array();
        foreach (array_slice(is_array($page['content_candidates'] ?? null) ? $page['content_candidates'] : array(), 0, 3) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $content_candidates[] = array(
                'source' => (string) ($candidate['source'] ?? ''),
                'score'  => (int) ($candidate['score'] ?? 0),
                'length' => (int) ($candidate['length'] ?? 0),
                'text'   => mb_substr((string) ($candidate['text'] ?? ''), 0, $candidate_text_limit, 'UTF-8'),
            );
        }

        $evidence    = array(
            'url'         => (string) ($page['url'] ?? ''),
            'title'       => (string) ($page['title'] ?? ''),
            'description' => (string) ($page['description'] ?? ''),
            'main_text'   => mb_substr((string) ($page['text'] ?? ''), 0, $main_text_limit, 'UTF-8'),
            'content_candidates' => $content_candidates,
            'links'       => array_slice(is_array($page['links'] ?? null) ? $page['links'] : array(), 0, $link_limit),
        );

        return implode(
            "\n\n",
            array(
                '用户采集需求：' . trim($instruction),
                '目标字段定义：' . (string) $json_encode($field_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                '页面证据：' . (string) $json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                '字段规则：title 只返回页面主标题；summary 只返回摘要；url 返回用户要求的主要网址；cover_image_url 返回文章主图、封面图、特色图或 og:image 的远程绝对 URL；content 只返回正文主体，优先依据 main_text 和 content_candidates，不得把菜单、导航、栏目、发布时间、阅读量等页面框架文本写入 content。',
                '请返回一个 JSON 对象。对象字段必须尽量与目标字段 key 对齐，可额外返回 confidence、notes、missing_fields。',
            )
        );
    }

    protected function post_completion(array $payload, array $settings): array
    {
        return $this->rewriter->complete_chat($payload, $settings);
    }

    protected function pause_before_retry(int $attempt): void
    {
        $index = min(count(self::INLINE_RETRY_DELAYS) - 1, max(0, $attempt - 1));
        $seconds = self::INLINE_RETRY_DELAYS[$index];

        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    protected function text_length(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }

        return strlen($text);
    }

    protected function extract_message_content(array $response): string
    {
        return $this->rewriter->extract_completion_content($response);
    }

    protected function decode_json_object(string $content): array
    {
        $content = trim($content);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $json = $this->extract_first_json_object($content);
        if ('' !== $json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('AI 抽取结果不是有效的 JSON 对象。');
    }

    protected function extract_first_json_object(string $content): string
    {
        $length = strlen($content);
        $start = strpos($content, '{');

        if (false === $start) {
            return '';
        }

        $depth = 0;
        $in_string = false;
        $escaped = false;

        for ($index = $start; $index < $length; $index++) {
            $char = $content[$index];

            if ($in_string) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ('\\' === $char) {
                    $escaped = true;
                    continue;
                }

                if ('"' === $char) {
                    $in_string = false;
                }

                continue;
            }

            if ('"' === $char) {
                $in_string = true;
                continue;
            }

            if ('{' === $char) {
                $depth++;
                continue;
            }

            if ('}' === $char) {
                $depth--;
                if (0 === $depth) {
                    return substr($content, $start, $index - $start + 1);
                }
            }
        }

        return '';
    }
}
