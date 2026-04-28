<?php
declare(strict_types=1);

class AIditor_REST_Controller
{
    protected const PREVIEW_LIMIT = 20;

    protected AIditor_Settings $settings;

    protected AIditor_Run_Repository $runs;

    protected AIditor_Run_Item_Repository $run_items;

    protected AIditor_Template_Repository $templates;

    protected AIditor_Article_Style_Repository $article_styles;

    protected AIditor_Source_Adapter_Registry $adapter_registry;

    protected AIditor_Content_Normalizer $normalizer;

    protected AIditor_Deduper $deduper;

    protected AIditor_Source_Researcher $researcher;

    protected AIditor_Page_Fetcher $page_fetcher;

    protected AIditor_Taxonomy_Browser $taxonomy_browser;

    protected AIditor_AI_Rewriter $rewriter;

    protected AIditor_AI_Extractor $ai_extractor;

    protected AIditor_Draft_Writer $draft_writer;

    protected AIditor_Queue_Worker $queue_worker;

    public function __construct(
        AIditor_Settings $settings,
        AIditor_Run_Repository $runs,
        AIditor_Run_Item_Repository $run_items,
        AIditor_Template_Repository $templates,
        AIditor_Article_Style_Repository $article_styles,
        AIditor_Source_Adapter_Registry $adapter_registry,
        AIditor_Content_Normalizer $normalizer,
        AIditor_Deduper $deduper,
        AIditor_Source_Researcher $researcher,
        AIditor_Page_Fetcher $page_fetcher,
        AIditor_Taxonomy_Browser $taxonomy_browser,
        AIditor_AI_Rewriter $rewriter,
        AIditor_AI_Extractor $ai_extractor,
        AIditor_Draft_Writer $draft_writer,
        AIditor_Queue_Worker $queue_worker
    ) {
        $this->settings         = $settings;
        $this->runs             = $runs;
        $this->run_items        = $run_items;
        $this->templates        = $templates;
        $this->article_styles   = $article_styles;
        $this->adapter_registry = $adapter_registry;
        $this->normalizer       = $normalizer;
        $this->deduper          = $deduper;
        $this->researcher       = $researcher;
        $this->page_fetcher     = $page_fetcher;
        $this->taxonomy_browser = $taxonomy_browser;
        $this->rewriter         = $rewriter;
        $this->ai_extractor     = $ai_extractor;
        $this->draft_writer     = $draft_writer;
        $this->queue_worker     = $queue_worker;
    }

    public function register_routes(): void
    {
        register_rest_route(
            'aiditor/v1',
            '/settings',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_settings'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'save_settings'),
                    'permission_callback' => array($this, 'can_manage'),
                    'args'                => $this->get_settings_route_args(),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/settings/model-test',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'test_model_settings'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/article-styles',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'list_article_styles'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'save_article_style'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/article-styles/generate',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'generate_article_style'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/article-styles/(?P<style_id>[a-zA-Z0-9\-\_]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array($this, 'delete_article_style'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/targets',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_targets'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/terms',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_terms'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/templates/(?P<template_id>[a-zA-Z0-9\-\_]+)/preview',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'preview_template'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/templates/(?P<template_id>[a-zA-Z0-9\-\_]+)/run',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'run_template'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/generic/fetch-page',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'generic_fetch_page'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/generic/discover-urls',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'generic_discover_urls'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/generic/extract-fields',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'generic_extract_fields'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/editing/extract',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'editing_extract_fields'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/editing/rewrite',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'editing_rewrite_fields'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/editing/publish',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'editing_publish_fields'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/creation/generate',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'creation_generate_article'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/creation/publish',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'creation_publish_article'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/templates',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'list_templates'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'save_template'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/templates/(?P<template_id>[a-zA-Z0-9\-\_]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_template'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array($this, 'delete_template'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/runs',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'list_runs'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/runs/(?P<run_id>[a-zA-Z0-9\.\-_]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_run'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array($this, 'delete_run'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/runs/(?P<run_id>[a-zA-Z0-9\.\-_]+)/process',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'process_run'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/runs/(?P<run_id>[a-zA-Z0-9\.\-_]+)/pause',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'pause_run'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/runs/(?P<run_id>[a-zA-Z0-9\.\-_]+)/resume',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'resume_run'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/runs/(?P<run_id>[a-zA-Z0-9\.\-_]+)/cancel',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'cancel_run'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'aiditor/v1',
            '/runs/(?P<run_id>[a-zA-Z0-9\.\-_]+)/retry-failed',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'retry_failed_items'),
                    'permission_callback' => array($this, 'can_manage'),
                ),
            )
        );
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }

    protected function get_settings_route_args(): array
    {
        return array(
            'provider_type' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'base_url' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'esc_url_raw',
            ),
            'api_key' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => array($this, 'sanitize_plain_setting_string'),
            ),
            'model' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'temperature' => array(
                'type'              => 'number',
                'required'          => false,
                'validate_callback' => array($this, 'validate_numeric_setting'),
            ),
            'max_tokens' => array(
                'type'              => 'integer',
                'required'          => false,
                'validate_callback' => array($this, 'validate_numeric_setting'),
            ),
            'request_timeout' => array(
                'type'              => 'integer',
                'required'          => false,
                'validate_callback' => array($this, 'validate_numeric_setting'),
            ),
            'default_model_profile_id' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_key',
            ),
            'model_profiles' => array(
                'required'          => false,
                'validate_callback' => array($this, 'validate_model_profiles_setting'),
            ),
            'queue_batch_size' => array(
                'type'              => 'integer',
                'required'          => false,
                'validate_callback' => array($this, 'validate_numeric_setting'),
            ),
            'queue_time_limit' => array(
                'type'              => 'integer',
                'required'          => false,
                'validate_callback' => array($this, 'validate_numeric_setting'),
            ),
            'queue_concurrency' => array(
                'type'              => 'integer',
                'required'          => false,
                'validate_callback' => array($this, 'validate_numeric_setting'),
            ),
            'queue_poll_interval' => array(
                'type'              => 'integer',
                'required'          => false,
                'validate_callback' => array($this, 'validate_numeric_setting'),
            ),
            'log_retention_days' => array(
                'type'              => 'integer',
                'required'          => false,
                'validate_callback' => array($this, 'validate_numeric_setting'),
            ),
            'default_category_slug' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_title',
            ),
            'default_post_status' => array(
                'type'              => 'string',
                'required'          => false,
                'validate_callback' => array($this, 'validate_post_status_setting'),
                'sanitize_callback' => 'sanitize_key',
            ),
            'default_article_style' => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_key',
            ),
        );
    }

    public function sanitize_plain_setting_string($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function validate_numeric_setting($value): bool
    {
        return is_numeric($value);
    }

    public function validate_model_profiles_setting($value): bool
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded);
        }

        return is_array($value);
    }

    public function validate_post_status_setting($value): bool
    {
        return in_array((string) $value, array('draft', 'pending', 'private', 'publish'), true);
    }

    public function get_settings(): WP_REST_Response
    {
        return rest_ensure_response(
            array(
                'settings' => $this->settings->get_public_settings(),
            )
        );
    }

    public function save_settings(WP_REST_Request $request): WP_REST_Response
    {
        $this->settings->save($this->get_request_payload($request));

        return rest_ensure_response(
            array(
                'message'  => '设置已保存。',
                'settings' => $this->settings->get_public_settings(),
            )
        );
    }

    public function test_model_settings(WP_REST_Request $request)
    {
        $payload = $this->get_request_payload($request);
        $profile_id = isset($payload['profile_id']) ? sanitize_key((string) $payload['profile_id']) : '';
        $saved_settings = '' !== $profile_id ? $this->settings->resolve_model_settings($profile_id) : array();
        $settings = array(
            'base_url'        => isset($payload['base_url']) ? trim((string) $payload['base_url']) : '',
            'api_key'         => isset($payload['api_key']) ? trim((string) $payload['api_key']) : '',
            'model'           => isset($payload['model']) ? trim((string) $payload['model']) : '',
            'temperature'     => isset($payload['temperature']) ? (float) $payload['temperature'] : 0.2,
            'max_tokens'      => min(128, max(16, isset($payload['max_tokens']) ? (int) $payload['max_tokens'] : 128)),
            'request_timeout' => max(5, min(60, isset($payload['request_timeout']) ? (int) $payload['request_timeout'] : 30)),
        );

        if ('' === $settings['api_key'] && is_array($saved_settings)) {
            $settings['api_key'] = trim((string) ($saved_settings['api_key'] ?? ''));
        }

        if ('' === $settings['base_url'] || '' === $settings['api_key'] || '' === $settings['model']) {
            return new WP_Error('aiditor_model_test_incomplete', '请先填写接口地址、API 密钥和模型名称。', array('status' => 400));
        }

        try {
            $response = $this->rewriter->complete_chat(
                array(
                    'model'       => $settings['model'],
                    'messages'    => array(
                        array(
                            'role'    => 'user',
                            'content' => 'Hi!',
                        ),
                    ),
                    'temperature' => 0,
                    'max_tokens'  => $settings['max_tokens'],
                ),
                $settings
            );

            $content = trim($this->rewriter->extract_completion_content($response));

            if ('' === $content) {
                return new WP_Error('aiditor_model_test_empty', '模型测试失败：服务未返回可用内容。', array('status' => 400));
            }

            return rest_ensure_response(
                array(
                    'message' => '模型测试成功。',
                    'content' => $content,
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_model_test_failed', '模型测试失败：' . $exception->getMessage(), array('status' => 400));
        }
    }

    public function list_article_styles(): WP_REST_Response
    {
        return rest_ensure_response(array('styles' => $this->article_styles->list_styles()));
    }

    public function save_article_style(WP_REST_Request $request)
    {
        try {
            $style = $this->article_styles->save($this->get_request_payload($request));

            return rest_ensure_response(
                array(
                    'style'   => $style,
                    'styles'  => $this->article_styles->list_styles(),
                    'message' => '文章风格已保存。',
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_article_style_save_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function generate_article_style(WP_REST_Request $request)
    {
        try {
            $payload = $this->get_request_payload($request);
            $name = trim((string) ($payload['name'] ?? ''));
            $description = trim((string) ($payload['description'] ?? ''));

            if ('' === $name && '' === $description) {
                return new WP_Error('aiditor_article_style_prompt_required', '请先填写风格名称或风格需求。', array('status' => 400));
            }

            $style_prompt = $this->generate_article_style_prompt($name, $description);

            return rest_ensure_response(
                array(
                    'name'        => '' !== $name ? $name : 'AI 生成文章风格',
                    'description' => $description,
                    'prompt'      => $style_prompt,
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_article_style_generate_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function delete_article_style(WP_REST_Request $request)
    {
        $deleted = $this->article_styles->delete((string) $request['style_id']);

        if (! $deleted) {
            return new WP_Error('aiditor_article_style_delete_failed', '文章风格不存在，或内置风格不允许删除。', array('status' => 400));
        }

        return rest_ensure_response(
            array(
                'styles'  => $this->article_styles->list_styles(),
                'message' => '文章风格已删除。',
            )
        );
    }

    public function get_targets(WP_REST_Request $request): WP_REST_Response
    {
        $post_type = trim((string) ($request->get_param('post_type') ?: 'post'));

        return rest_ensure_response(
            array(
                'post_type' => $post_type,
                'targets'   => $this->taxonomy_browser->get_target_configuration($post_type),
            )
        );
    }

    public function get_terms(WP_REST_Request $request): WP_REST_Response
    {
        $taxonomy = trim((string) $request->get_param('taxonomy'));
        $parent   = $request->get_param('parent');
        $parent   = null === $parent ? 0 : (int) $parent;

        if ('' === $taxonomy) {
            return new WP_Error('aiditor_taxonomy_required', '必须提供 taxonomy 参数。', array('status' => 400));
        }

        return rest_ensure_response(
            array(
                'taxonomy' => $taxonomy,
                'parent'   => $parent,
                'terms'    => $this->taxonomy_browser->get_terms($taxonomy, $parent),
            )
        );
    }

    public function preview_template(WP_REST_Request $request)
    {
        try {
            $template = $this->templates->get((string) $request['template_id']);

            if (! is_array($template)) {
                return new WP_Error('aiditor_template_not_found', '未找到对应采集模板。', array('status' => 404));
            }

            $payload = $this->get_request_payload($request);
            $source_url = esc_url_raw((string) ($payload['source_url'] ?? $template['source_url'] ?? ''));

            if ('' === $source_url) {
                return new WP_Error('aiditor_source_url_required', '必须提供来源 URL。', array('status' => 400));
            }

            $limit = max(1, min(self::PREVIEW_LIMIT, (int) ($payload['limit'] ?? self::PREVIEW_LIMIT)));
            $mode  = (string) ($template['source_mode'] ?? 'list');
            $model_settings = $this->resolve_request_model_settings($payload);

            if ('detail' === $mode) {
                $result = array(
                    'items' => array(
                        array(
                            'url'    => $source_url,
                            'title'  => (string) ($template['name'] ?? $source_url),
                            'reason' => '当前模板为详情页模式，将直接采集该 URL。',
                        ),
                    ),
                );
                $page = array(
                    'url'         => $source_url,
                    'title'       => (string) ($template['name'] ?? ''),
                    'description' => '',
                );
            } else {
                $page = $this->page_fetcher->fetch($source_url);
                $result = $this->ai_extractor->discover_detail_urls(
                    $page,
                    (string) ($template['extraction_prompt'] ?? '请识别列表页中的详情页 URL。'),
                    $limit,
                    $model_settings
                );
            }

            return rest_ensure_response(
                array(
                    'source_url'       => $source_url,
                    'template_id'      => (string) ($template['template_id'] ?? ''),
                    'template_name'    => (string) ($template['name'] ?? ''),
                    'requested_limit'  => max(1, (int) ($payload['limit'] ?? $limit)),
                    'preview_limit'    => $limit,
                    'page'             => array(
                        'url'         => $page['url'] ?? $source_url,
                        'title'       => $page['title'] ?? '',
                        'description' => $page['description'] ?? '',
                    ),
                    'result'           => $result,
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_template_preview_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function run_template(WP_REST_Request $request)
    {
        try {
            $template = $this->templates->get((string) $request['template_id']);

            if (! is_array($template)) {
                return new WP_Error('aiditor_template_not_found', '未找到对应采集模板。', array('status' => 404));
            }

            $payload = $this->get_request_payload($request);
            $source_url = esc_url_raw((string) ($payload['source_url'] ?? $template['source_url'] ?? ''));

            if ('' === $source_url) {
                return new WP_Error('aiditor_source_url_required', '必须提供来源 URL。', array('status' => 400));
            }

            $settings = $this->settings->get();
            $model_profile_id = sanitize_key((string) ($payload['model_profile_id'] ?? $settings['default_model_profile_id'] ?? ''));
            $model_settings = $this->settings->resolve_model_settings($model_profile_id);

            if ('' !== $model_profile_id && $model_profile_id !== (string) ($model_settings['model_profile_id'] ?? '')) {
                return new WP_Error('aiditor_model_profile_not_found', '未找到所选 AI 模型配置。', array('status' => 400));
            }

            if (
                '' === trim((string) ($model_settings['base_url'] ?? ''))
                || '' === trim((string) ($model_settings['api_key'] ?? ''))
                || '' === trim((string) ($model_settings['model'] ?? ''))
            ) {
                return new WP_Error('aiditor_model_profile_incomplete', '所选 AI 模型配置不完整，请补全接口地址、API 密钥和模型名称。', array('status' => 400));
            }

            $extra_tax_terms = is_array($payload['extra_tax_terms'] ?? null) ? $payload['extra_tax_terms'] : array();
            $extra_tax_terms['_aiditor_template_id'] = (string) ($template['template_id'] ?? '');
            $extra_tax_terms['_aiditor_model_profile_id'] = (string) ($model_settings['model_profile_id'] ?? $model_profile_id);
            $extra_tax_terms['_aiditor_model_profile_name'] = (string) ($model_settings['model_profile_name'] ?? '');
            $extra_tax_terms['_aiditor_model'] = (string) ($model_settings['model'] ?? '');

            $run = $this->runs->create(
                array(
                    'source_url'        => $source_url,
                    'source_type'       => 'generic_template',
                    'status'            => 'queued',
                    'requested_limit'   => max(1, min(2000, (int) ($payload['limit'] ?? 20))),
                    'post_type'         => sanitize_key((string) ($payload['post_type'] ?? 'post')),
                    'post_status'       => $this->get_current_default_post_status(),
                    'target_taxonomy'   => sanitize_key((string) ($payload['target_taxonomy'] ?? 'category')),
                    'target_term_id'    => (int) ($payload['target_term_id'] ?? 0),
                    'author_id'         => $this->get_current_admin_author_id(),
                    'extra_tax_terms'   => $extra_tax_terms,
                    'current_page'      => 1,
                    'initialized_items' => 0,
                    'source_exhausted'  => 0,
                    'last_error'        => '',
                    'worker_message'    => '通用采集模板任务已创建，等待进入批处理队列。',
                )
            );

            if (! is_array($run) || empty($run['run_id'])) {
                throw new RuntimeException('创建模板采集任务失败。');
            }

            $this->queue_worker->schedule_run((string) $run['run_id'], 0);

            return rest_ensure_response(
                array(
                    'run'      => $this->runs->get((string) $run['run_id']),
                    'template' => $template,
                    'message'  => '模板采集任务已创建并进入队列。',
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_template_run_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function generic_fetch_page(WP_REST_Request $request)
    {
        try {
            $payload = $this->get_request_payload($request);
            $url     = trim((string) ($payload['url'] ?? ''));
            $page    = $this->page_fetcher->fetch($url);

            return rest_ensure_response(array('page' => $page));
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_generic_fetch_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function generic_discover_urls(WP_REST_Request $request)
    {
        try {
            $payload     = $this->get_request_payload($request);
            $url         = trim((string) ($payload['url'] ?? ''));
            $requirement = trim((string) ($payload['requirement'] ?? '请识别列表页中的详情页 URL。'));
            $limit       = max(1, min(100, (int) ($payload['limit'] ?? 20)));
            $page        = $this->page_fetcher->fetch($url);
            $result      = $this->ai_extractor->discover_detail_urls($page, $requirement, $limit);

            return rest_ensure_response(
                array(
                    'source_url' => $url,
                    'page'       => array(
                        'url'         => $page['url'] ?? $url,
                        'title'       => $page['title'] ?? '',
                        'description' => $page['description'] ?? '',
                    ),
                    'result'     => $result,
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_generic_discover_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function generic_extract_fields(WP_REST_Request $request)
    {
        try {
            $payload      = $this->get_request_payload($request);
            $url          = trim((string) ($payload['url'] ?? ''));
            $instruction  = trim((string) ($payload['instruction'] ?? '请抽取页面标题、摘要、正文、主要网址和封面图。封面图返回文章主图或特色图的远程绝对 URL。'));
            $field_schema = is_array($payload['field_schema'] ?? null) ? $payload['field_schema'] : $this->get_default_generic_field_schema();
            $page         = $this->page_fetcher->fetch($url);
            $fields       = $this->ai_extractor->extract_fields($page, $field_schema, $instruction);

            return rest_ensure_response(
                array(
                    'source_url'   => $url,
                    'field_schema' => $field_schema,
                    'fields'       => $fields,
                    'page'         => array(
                        'url'         => $page['url'] ?? $url,
                        'title'       => $page['title'] ?? '',
                        'description' => $page['description'] ?? '',
                    ),
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_generic_extract_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function editing_extract_fields(WP_REST_Request $request)
    {
        try {
            $payload         = $this->get_request_payload($request);
            $url             = esc_url_raw(trim((string) ($payload['url'] ?? '')));
            $instruction     = trim((string) ($payload['instruction'] ?? '请提取标题、发布时间、关键词、摘要、正文与作者。正文只保留主体内容。'));
            $field_schema    = is_array($payload['field_schema'] ?? null) ? $payload['field_schema'] : $this->get_default_editing_field_schema();
            $runtime_settings = $this->resolve_request_model_settings($payload);
            $page            = $this->page_fetcher->fetch($url);
            $fields          = $this->ai_extractor->extract_fields($page, $field_schema, $instruction, $runtime_settings);
            $fields['source_url'] = (string) ($fields['source_url'] ?? $url);
            $fields = $this->sanitize_editing_preview_fields($fields, $field_schema);

            return rest_ensure_response(
                array(
                    'source_url'             => $url,
                    'page'                   => array(
                        'url'         => $page['url'] ?? $url,
                        'title'       => $page['title'] ?? '',
                        'description' => $page['description'] ?? '',
                    ),
                    'field_schema'           => $field_schema,
                    'fields'                 => $fields,
                    'default_rewrite_fields' => $this->get_default_rewrite_fields($field_schema),
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_editing_extract_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function editing_rewrite_fields(WP_REST_Request $request)
    {
        try {
            $payload          = $this->get_request_payload($request);
            $fields           = is_array($payload['fields'] ?? null) ? $payload['fields'] : array();
            $field_schema     = is_array($payload['field_schema'] ?? null) ? $payload['field_schema'] : $this->get_default_editing_field_schema();
            $rewrite_fields   = $this->sanitize_string_array($payload['rewrite_fields'] ?? array());
            $instruction      = trim((string) ($payload['instruction'] ?? ''));
            $style_id         = sanitize_key((string) ($payload['style_id'] ?? ''));
            $runtime_settings = $this->resolve_request_model_settings($payload);
            if ('' !== $style_id) {
                $runtime_settings['default_article_style'] = $style_id;
            }

            if (empty($rewrite_fields)) {
                return new WP_Error('aiditor_editing_rewrite_fields_required', '请至少选择一个要重写的字段。', array('status' => 400));
            }

            $rewrite_result   = $this->rewriter->rewrite_fields($fields, $field_schema, $rewrite_fields, $instruction, $runtime_settings);
            $merged_fields    = $this->sanitize_editing_preview_fields(is_array($rewrite_result['merged_fields'] ?? null) ? $rewrite_result['merged_fields'] : array(), $field_schema);
            $changed_fields   = $this->sanitize_editing_preview_fields(is_array($rewrite_result['changed_fields'] ?? null) ? $rewrite_result['changed_fields'] : array(), $field_schema);
            $changed_keys     = $this->sanitize_string_array($rewrite_result['changed_keys'] ?? array());
            $requested_keys   = $this->sanitize_string_array($rewrite_result['requested_keys'] ?? array());
            $unchanged_keys   = $this->sanitize_string_array($rewrite_result['unchanged_keys'] ?? array());

            return rest_ensure_response(
                array(
                    'field_schema'      => $field_schema,
                    'rewritten_fields'  => $merged_fields,
                    'changed_fields'    => $changed_fields,
                    'rewritten_keys'    => $changed_keys,
                    'requested_keys'    => $requested_keys,
                    'unchanged_keys'    => $unchanged_keys,
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_editing_rewrite_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function editing_publish_fields(WP_REST_Request $request)
    {
        try {
            $payload          = $this->get_request_payload($request);
            $field_schema     = is_array($payload['field_schema'] ?? null) ? $payload['field_schema'] : $this->get_default_editing_field_schema();
            $extracted_fields = is_array($payload['extracted_fields'] ?? null) ? $payload['extracted_fields'] : array();
            $rewritten_fields = is_array($payload['rewritten_fields'] ?? null) ? $payload['rewritten_fields'] : array();
            $publish_fields   = $this->sanitize_string_array($payload['publish_fields'] ?? array());
            $field_mapping    = is_array($payload['field_mapping'] ?? null) ? $payload['field_mapping'] : array();
            $source_url       = esc_url_raw(trim((string) ($payload['source_url'] ?? $extracted_fields['source_url'] ?? $rewritten_fields['source_url'] ?? '')));
            $page             = is_array($payload['page'] ?? null) ? $payload['page'] : array();
            $post_type        = sanitize_key((string) ($payload['post_type'] ?? 'post'));
            $post_status      = sanitize_key((string) ($payload['post_status'] ?? $this->get_current_default_post_status()));
            $target_taxonomy  = sanitize_key((string) ($payload['target_taxonomy'] ?? 'category'));
            $target_term_id   = (int) ($payload['target_term_id'] ?? 0);
            $extra_tax_terms  = is_array($payload['extra_tax_terms'] ?? null) ? $payload['extra_tax_terms'] : array();
            $author_id        = $this->get_current_admin_author_id();
            $final_fields     = $this->build_editing_publish_fields($field_schema, $extracted_fields, $rewritten_fields, $publish_fields);

            if (empty($final_fields)) {
                return new WP_Error('aiditor_editing_publish_fields_required', '请至少选择一个要发布的字段。', array('status' => 400));
            }

            $this->taxonomy_browser->validate_selection(
                array(
                    'post_type'       => $post_type,
                    'target_taxonomy' => $target_taxonomy,
                    'target_term_id'  => $target_term_id,
                    'extra_tax_terms' => $extra_tax_terms,
                )
            );

            $post_id = $this->draft_writer->write_mapped(
                array(
                    'field_schema' => $field_schema,
                    'fields'       => $final_fields,
                ),
                array(
                    'source_url'   => $source_url,
                    'source_title' => (string) ($final_fields['title'] ?? $page['title'] ?? ''),
                    'source_summary' => (string) ($final_fields['summary'] ?? ''),
                ),
                array(
                    'post_type'       => $post_type,
                    'post_status'     => $post_status,
                    'target_taxonomy' => $target_taxonomy,
                    'target_term_id'  => $target_term_id,
                    'extra_tax_terms' => $extra_tax_terms,
                    'author_id'       => $author_id,
                ),
                $field_mapping
            );

            return rest_ensure_response(
                array(
                    'post_id'    => $post_id,
                    'edit_link'  => function_exists('get_edit_post_link') ? (string) get_edit_post_link($post_id, 'raw') : '',
                    'message'    => '文章已创建。',
                    'fields'     => $final_fields,
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_editing_publish_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function creation_generate_article(WP_REST_Request $request)
    {
        try {
            $payload            = $this->get_request_payload($request);
            $prompt             = trim((string) ($payload['prompt'] ?? ''));
            $style_id           = sanitize_key((string) ($payload['style_id'] ?? ''));
            $style_instruction  = trim((string) ($payload['style_instruction'] ?? ''));
            $runtime_settings   = $this->resolve_request_model_settings($payload);
            $field_schema       = $this->get_default_creation_field_schema();
            $style_prompt       = '' !== $style_id
                ? $this->resolve_creation_style_prompt($style_id)
                : $this->resolve_creation_style_prompt('editorial-guide');
            $combined_style     = trim($style_prompt . ('' !== $style_instruction ? "\n补充风格要求：" . $style_instruction : ''));

            if ('' === $prompt) {
                return new WP_Error('aiditor_creation_prompt_required', '请填写创作说明。', array('status' => 400));
            }

            $response = $this->rewriter->complete_chat(
                array(
                    'model'       => (string) $runtime_settings['model'],
                    'temperature' => isset($runtime_settings['temperature']) ? (float) $runtime_settings['temperature'] : 0.5,
                    'max_tokens'  => isset($runtime_settings['max_tokens']) ? (int) $runtime_settings['max_tokens'] : 4000,
                    'messages'    => array(
                        array(
                            'role'    => 'system',
                            'content' => $this->build_creation_system_prompt($combined_style),
                        ),
                        array(
                            'role'    => 'user',
                            'content' => $this->build_creation_user_prompt($prompt),
                        ),
                    ),
                ),
                $runtime_settings
            );

            $article = $this->decode_creation_article($this->rewriter->extract_completion_content($response));
            $article = $this->sanitize_creation_article($article);

            return rest_ensure_response(
                array(
                    'field_schema' => $field_schema,
                    'article'      => $article,
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_creation_generate_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function creation_publish_article(WP_REST_Request $request)
    {
        try {
            $payload         = $this->get_request_payload($request);
            $generated       = is_array($payload['generated_fields'] ?? null) ? $this->sanitize_creation_article($payload['generated_fields']) : array();
            $field_schema    = is_array($payload['field_schema'] ?? null) ? $payload['field_schema'] : $this->get_default_creation_field_schema();
            $publish_fields  = $this->sanitize_string_array($payload['publish_fields'] ?? array());
            $field_mapping   = is_array($payload['field_mapping'] ?? null) ? $payload['field_mapping'] : array();
            $post_type       = sanitize_key((string) ($payload['post_type'] ?? 'post'));
            $post_status     = sanitize_key((string) ($payload['post_status'] ?? $this->get_current_default_post_status()));
            $target_taxonomy = sanitize_key((string) ($payload['target_taxonomy'] ?? 'category'));
            $target_term_id  = (int) ($payload['target_term_id'] ?? 0);
            $extra_tax_terms = is_array($payload['extra_tax_terms'] ?? null) ? $payload['extra_tax_terms'] : array();
            $author_id       = $this->get_current_admin_author_id();
            $final_fields    = $this->build_editing_publish_fields($field_schema, $generated, array(), $publish_fields);

            if (empty($final_fields)) {
                return new WP_Error('aiditor_creation_publish_fields_required', '请先完成创作并保留至少一个可发布字段。', array('status' => 400));
            }

            $this->taxonomy_browser->validate_selection(
                array(
                    'post_type'       => $post_type,
                    'target_taxonomy' => $target_taxonomy,
                    'target_term_id'  => $target_term_id,
                    'extra_tax_terms' => $extra_tax_terms,
                )
            );

            $post_id = $this->draft_writer->write_mapped(
                array(
                    'field_schema' => $field_schema,
                    'fields'       => $final_fields,
                    'context'      => 'creation',
                ),
                array(
                    'source_url'     => (string) ($final_fields['source_url'] ?? ''),
                    'source_title'   => (string) ($final_fields['title'] ?? ''),
                    'source_summary' => (string) ($final_fields['summary'] ?? ''),
                ),
                array(
                    'post_type'       => $post_type,
                    'post_status'     => $post_status,
                    'target_taxonomy' => $target_taxonomy,
                    'target_term_id'  => $target_term_id,
                    'extra_tax_terms' => $extra_tax_terms,
                    'author_id'       => $author_id,
                    'creation_media'  => true,
                    'cover_image_url' => (string) ($generated['cover_image_url'] ?? ''),
                ),
                $field_mapping
            );

            return rest_ensure_response(
                array(
                    'post_id'   => $post_id,
                    'edit_link' => function_exists('get_edit_post_link') ? (string) get_edit_post_link($post_id, 'raw') : '',
                    'message'   => $this->get_publish_success_message($post_status, '创作内容'),
                    'fields'    => $final_fields,
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_creation_publish_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function save_template(WP_REST_Request $request)
    {
        try {
            $template = $this->templates->save($this->get_request_payload($request));

            return rest_ensure_response(
                array(
                    'template' => $template,
                    'message'  => '采集模板已保存。',
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_template_save_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function get_template(WP_REST_Request $request)
    {
        $template = $this->templates->get((string) $request['template_id']);

        if (! is_array($template)) {
            return new WP_Error('aiditor_template_not_found', '未找到对应采集模板。', array('status' => 404));
        }

        return rest_ensure_response(array('template' => $template));
    }

    public function delete_template(WP_REST_Request $request)
    {
        $deleted = $this->templates->delete((string) $request['template_id']);

        if (! $deleted) {
            return new WP_Error('aiditor_template_not_found', '未找到对应采集模板。', array('status' => 404));
        }

        return rest_ensure_response(array('message' => '采集模板已删除。'));
    }

    public function list_runs(WP_REST_Request $request): WP_REST_Response
    {
        $limit = max(10, min(100, (int) ($request->get_param('limit') ?: 100)));

        return rest_ensure_response(
            array(
                'runs' => $this->runs->list_runs($limit),
                'limit' => $limit,
            )
        );
    }

    public function get_run(WP_REST_Request $request)
    {
        $run = $this->require_run((string) $request['run_id']);

        if ($run instanceof WP_Error) {
            return $run;
        }

        return rest_ensure_response(array('run' => $run));
    }

    public function delete_run(WP_REST_Request $request)
    {
        $run = $this->require_run((string) $request['run_id']);

        if ($run instanceof WP_Error) {
            return $run;
        }

        $status = (string) ($run['status'] ?? '');
        if (in_array($status, array('queued', 'running'), true)) {
            return new WP_Error('aiditor_run_delete_blocked', '执行中的任务不能直接删除，请先暂停或取消后再删除。', array('status' => 409));
        }

        $run_id = (string) ($run['run_id'] ?? '');
        $this->queue_worker->clear_run_runtime($run_id);
        $deleted = $this->runs->delete_run($run_id);

        return rest_ensure_response(
            array(
                'run_id'                  => $run_id,
                'deleted'                 => ! empty($deleted['run_deleted']),
                'deleted_run_count'       => (int) ($deleted['run_deleted'] ?? 0),
                'deleted_item_count'      => (int) ($deleted['items_deleted'] ?? 0),
                'message'                 => '任务记录已删除，已同步清理条目明细和运行时锁，不会影响已入库文章。',
            )
        );
    }

    public function process_run(WP_REST_Request $request)
    {
        $run_id = (string) $request['run_id'];
        $run    = $this->require_run($run_id);

        if ($run instanceof WP_Error) {
            return $run;
        }

        try {
            $processed = $this->queue_worker->dispatch_async_workers($run_id, false);

            return rest_ensure_response(
                array(
                    'run' => $processed,
                )
            );
        } catch (Throwable $exception) {
            return new WP_Error('aiditor_process_failed', $exception->getMessage(), array('status' => 400));
        }
    }

    public function pause_run(WP_REST_Request $request)
    {
        $run = $this->require_run((string) $request['run_id']);

        if ($run instanceof WP_Error) {
            return $run;
        }

        $this->queue_worker->request_stop((string) $run['run_id'], 'paused');

        $run = $this->runs->update(
            (string) $run['run_id'],
            array(
                'status'            => 'paused',
                'next_scheduled_at' => null,
                'worker_message'    => '任务已暂停，等待手动恢复。',
            )
        );

        if (is_array($run) && ! empty($run['run_id'])) {
            $this->queue_worker->clear_scheduled_run((string) $run['run_id']);
        }

        return rest_ensure_response(array('run' => $run));
    }

    public function resume_run(WP_REST_Request $request)
    {
        $run = $this->require_run((string) $request['run_id']);

        if ($run instanceof WP_Error) {
            return $run;
        }

        $this->queue_worker->clear_stop_request((string) $run['run_id']);

        $run = $this->runs->update(
            (string) $run['run_id'],
            array(
                'status'      => 'running',
                'worker_message' => '任务已恢复，准备继续执行批处理。',
            )
        );

        if (is_array($run) && ! empty($run['run_id'])) {
            $this->queue_worker->schedule_run((string) $run['run_id'], 0);
        }

        return rest_ensure_response(array('run' => $run));
    }

    public function cancel_run(WP_REST_Request $request)
    {
        $run = $this->require_run((string) $request['run_id']);

        if ($run instanceof WP_Error) {
            return $run;
        }

        $this->queue_worker->request_stop((string) $run['run_id'], 'cancelled');

        $run = $this->runs->update(
            (string) $run['run_id'],
            array(
                'status'      => 'cancelled',
                'finished_at' => gmdate('Y-m-d H:i:s'),
                'next_scheduled_at' => null,
                'worker_message' => '任务已取消，不会再继续处理。',
            )
        );

        if (is_array($run) && ! empty($run['run_id'])) {
            $this->queue_worker->clear_scheduled_run((string) $run['run_id']);
        }

        return rest_ensure_response(array('run' => $run));
    }

    public function retry_failed_items(WP_REST_Request $request)
    {
        $run = $this->require_run((string) $request['run_id']);

        if ($run instanceof WP_Error) {
            return $run;
        }

        $run_id = (string) $run['run_id'];
        $requeued = $this->run_items->requeue_failed($run_id);

        if ($requeued <= 0) {
            return rest_ensure_response(
                array(
                    'run'            => $this->runs->get($run_id),
                    'requeued_count' => 0,
                    'message'        => '当前任务没有可重新采集的失败条目。',
                )
            );
        }

        $this->queue_worker->clear_stop_request($run_id);

        $run = $this->runs->update(
            $run_id,
            array(
                'status'            => 'running',
                'finished_at'       => null,
                'next_scheduled_at' => null,
                'last_error'        => '',
                'worker_message'    => sprintf('已将 %d 条失败条目重新加入队列，准备重新采集。', $requeued),
            )
        );

        $this->queue_worker->schedule_run($run_id, 0);

        return rest_ensure_response(
            array(
                'run'            => is_array($run) ? $this->runs->get($run_id) : $run,
                'requeued_count' => $requeued,
                'message'        => sprintf('已将 %d 条失败条目重新加入队列。', $requeued),
            )
        );
    }

    protected function require_run(string $run_id)
    {
        $run = $this->runs->get($run_id);

        if (! is_array($run)) {
            return new WP_Error('aiditor_run_not_found', '未找到对应任务。', array('status' => 404));
        }

        return $run;
    }

    protected function get_current_admin_author_id(): int
    {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        if ($user_id > 0 && function_exists('user_can') && user_can($user_id, 'manage_options')) {
            return $user_id;
        }

        return $this->get_default_admin_author_id();
    }

    protected function get_current_default_post_status(): string
    {
        $settings = $this->settings->get();
        $status = (string) ($settings['default_post_status'] ?? 'draft');

        return in_array($status, array('draft', 'pending', 'private', 'publish'), true) ? $status : 'draft';
    }

    protected function generate_article_style_prompt(string $name, string $description): string
    {
        $settings = $this->settings->get();

        if (
            '' === trim((string) ($settings['base_url'] ?? ''))
            || '' === trim((string) ($settings['api_key'] ?? ''))
            || '' === trim((string) ($settings['model'] ?? ''))
        ) {
            throw new RuntimeException('缺少可用 AI 模型配置，请先在插件设置中新增并保存模型。');
        }

        $response = $this->rewriter->complete_chat(
            array(
                'model'       => (string) $settings['model'],
                'temperature' => 0.2,
                'max_tokens'  => 1200,
                'messages'    => array(
                    array(
                        'role'    => 'system',
                        'content' => '你是中文内容主编，请把用户给出的文章风格需求整理成可直接放入 AI 写作系统提示词的一段中文风格指令。只输出 JSON。',
                    ),
                    array(
                        'role'    => 'user',
                        'content' => '风格名称：' . $name . "\n风格需求：" . $description . "\n请返回 JSON：{\"prompt\":\"...\"}。要求 prompt 明确写作语气、信息密度、结构偏好、禁用表达和适用场景。",
                    ),
                ),
            ),
            $settings
        );

        $content = $this->rewriter->extract_completion_content($response);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || '' === trim((string) ($decoded['prompt'] ?? ''))) {
            throw new RuntimeException('AI 未返回可用的文章风格提示词。');
        }

        return trim((string) $decoded['prompt']);
    }

    protected function get_default_generic_field_schema(): array
    {
        return array(
            array(
                'key'         => 'title',
                'label'       => '标题',
                'type'        => 'text',
                'required'    => true,
                'description' => '页面或条目的主标题。',
            ),
            array(
                'key'         => 'summary',
                'label'       => '摘要或描述',
                'type'        => 'textarea',
                'required'    => false,
                'description' => '适合作为文章摘要或 SEO 元描述的简短介绍。',
            ),
            array(
                'key'         => 'url',
                'label'       => '主要网址',
                'type'        => 'url',
                'required'    => false,
                'description' => '详情页、官网或用户要求采集的主要链接。',
            ),
            array(
                'key'         => 'cover_image_url',
                'label'       => '封面图',
                'type'        => 'image',
                'required'    => false,
                'description' => '文章或详情页的主图、封面图、特色图远程 URL。',
            ),
            array(
                'key'         => 'content',
                'label'       => '正文',
                'type'        => 'html',
                'required'    => false,
                'description' => '页面主要正文内容。不要包含导航、栏目、面包屑、发布时间、阅读量、推荐阅读、评论区或页脚。',
            ),
        );
    }

    protected function get_default_creation_field_schema(): array
    {
        return array(
            array(
                'key'      => 'title',
                'label'    => '标题',
                'type'     => 'text',
                'required' => true,
            ),
            array(
                'key'      => 'summary',
                'label'    => '摘要',
                'type'     => 'textarea',
                'required' => false,
            ),
            array(
                'key'      => 'keywords',
                'label'    => '关键词',
                'type'     => 'text',
                'required' => false,
            ),
            array(
                'key'      => 'content',
                'label'    => '正文',
                'type'     => 'html',
                'required' => true,
            ),
            array(
                'key'      => 'cover_image_url',
                'label'    => '封面图链接',
                'type'     => 'url',
                'required' => false,
            ),
            array(
                'key'      => 'source_url',
                'label'    => '参考来源链接',
                'type'     => 'url',
                'required' => false,
            ),
        );
    }

    protected function build_creation_system_prompt(string $style_prompt): string
    {
        return implode(
            "\n",
            array(
                '你是中文网站内容主编，需要根据用户提供的创作说明输出一篇可直接发布到 WordPress 的原创文章。',
                '只输出 JSON，不要输出 markdown 代码块、额外解释或前后缀。',
                'JSON 字段必须包含：title、summary、keywords、content_html、cover_image_url、inline_images、source_url。',
                'title：简洁明确的中文标题。',
                'summary：120 字以内中文摘要。',
                'keywords：3 到 8 个关键词，使用数组返回。',
                'content_html：完整 HTML 正文，使用 <h2>、<p>、<ul>、<ol>、<blockquote>、<figure>、<img> 等安全标签组织内容。',
                '不要在 content_html 开头重复输出标题，不要再写与 title 字段相同或近似的主标题、<h1> 或首段标题。正文必须直接从导语或正文段落开始。',
                'cover_image_url：如果适合配图则返回一个远程图片 URL，否则返回空字符串。',
                'inline_images：返回数组，每项包含 url 和 caption；没有则返回空数组。',
                'source_url：如果文中明确引用某个公开来源则返回其 URL，否则返回空字符串。',
                '不要返回虚构统计数据、伪造引用或不存在的链接。无法确认时宁可留空。',
                '正文需具备自然小标题和完整段落，不要只写提纲。',
                '风格要求：' . $style_prompt,
            )
        );
    }

    protected function build_creation_user_prompt(string $prompt): string
    {
        return implode(
            "\n\n",
            array(
                '创作任务：' . $prompt,
                '请输出一篇适合中文网站发布的完整文章，并严格返回指定 JSON 结构。',
            )
        );
    }

    protected function resolve_creation_style_prompt(string $style_id): string
    {
        if ($this->article_styles instanceof AIditor_Article_Style_Repository) {
            $style = $this->article_styles->get($style_id);
            if (is_array($style) && '' !== trim((string) ($style['prompt'] ?? ''))) {
                return trim((string) ($style['prompt'] ?? ''));
            }
        }

        return '像专业中文编辑一样写作：自然、克制、结构清晰、信息密度高，避免营销腔和模板句。';
    }

    protected function decode_creation_article(string $content): array
    {
        $content = trim($content);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('AI 创作结果不是有效的 JSON 对象。');
    }

    protected function sanitize_creation_article(array $article): array
    {
        $title        = sanitize_text_field((string) ($article['title'] ?? ''));
        $content_html = function_exists('wp_kses_post') ? wp_kses_post((string) ($article['content_html'] ?? '')) : (string) ($article['content_html'] ?? '');
        $content_html = AIditor_Content_Normalizer::strip_leading_title_heading($content_html, $title);
        $content_html = trim($content_html);
        $keywords     = $article['keywords'] ?? array();
        $inline_images = $article['inline_images'] ?? array();

        if (! is_array($keywords)) {
            $keywords = $this->sanitize_string_array(preg_split('/[\r\n,，]+/u', (string) $keywords) ?: array());
        } else {
            $keywords = $this->sanitize_string_array($keywords);
        }

        if (! is_array($inline_images)) {
            $inline_images = array();
        }

        $inline_images = array_values(
            array_filter(
                array_map(
                    static function ($item): ?array {
                        if (is_string($item)) {
                            $url = esc_url_raw(trim($item));
                            return '' !== $url ? array('url' => $url, 'caption' => '') : null;
                        }

                        if (! is_array($item)) {
                            return null;
                        }

                        $url = esc_url_raw(trim((string) ($item['url'] ?? '')));
                        if ('' === $url) {
                            return null;
                        }

                        return array(
                            'url'     => $url,
                            'caption' => sanitize_text_field((string) ($item['caption'] ?? '')),
                        );
                    },
                    $inline_images
                )
            )
        );

        return array(
            'title'           => $title,
            'summary'         => sanitize_textarea_field((string) ($article['summary'] ?? '')),
            'keywords'        => $keywords,
            'content_html'    => $content_html,
            'content'         => $content_html,
            'cover_image_url' => esc_url_raw(trim((string) ($article['cover_image_url'] ?? ''))),
            'inline_images'   => $inline_images,
            'source_url'      => esc_url_raw(trim((string) ($article['source_url'] ?? ''))),
        );
    }

    protected function get_default_editing_field_schema(): array
    {
        return array(
            array(
                'key'      => 'title',
                'label'    => '标题',
                'type'     => 'text',
                'required' => true,
            ),
            array(
                'key'      => 'date',
                'label'    => '发布时间',
                'type'     => 'text',
                'required' => false,
            ),
            array(
                'key'      => 'keywords',
                'label'    => '关键词',
                'type'     => 'array',
                'required' => false,
            ),
            array(
                'key'      => 'summary',
                'label'    => '摘要',
                'type'     => 'textarea',
                'required' => false,
            ),
            array(
                'key'      => 'content',
                'label'    => '正文',
                'type'     => 'html',
                'required' => false,
            ),
            array(
                'key'      => 'author',
                'label'    => '作者',
                'type'     => 'text',
                'required' => false,
            ),
            array(
                'key'      => 'source_url',
                'label'    => '来源链接',
                'type'     => 'url',
                'required' => false,
            ),
        );
    }

    protected function get_default_rewrite_fields(array $field_schema): array
    {
        $allowed_types = array('text', 'textarea', 'html');
        $selected = array();

        foreach ($field_schema as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = sanitize_key((string) ($field['key'] ?? ''));
            $type = (string) ($field['type'] ?? 'text');

            if ('' === $key || ! in_array($type, $allowed_types, true)) {
                continue;
            }

            if ('source_url' === $key || 'date' === $key || 'author' === $key) {
                continue;
            }

            $selected[] = $key;
        }

        return array_values(array_unique($selected));
    }

    protected function sanitize_string_array($value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $items = array();

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $clean = sanitize_key((string) $item);
            if ('' === $clean) {
                continue;
            }

            $items[] = $clean;
        }

        return array_values(array_unique($items));
    }

    protected function resolve_request_model_settings(array $payload): array
    {
        $settings = $this->settings->get();
        $model_profile_id = sanitize_key((string) ($payload['model_profile_id'] ?? $settings['default_model_profile_id'] ?? ''));
        $model_settings = $this->settings->resolve_model_settings($model_profile_id);

        if ('' !== $model_profile_id && $model_profile_id !== (string) ($model_settings['model_profile_id'] ?? '')) {
            throw new RuntimeException('未找到所选 AI 模型配置。');
        }

        if (
            '' === trim((string) ($model_settings['base_url'] ?? ''))
            || '' === trim((string) ($model_settings['api_key'] ?? ''))
            || '' === trim((string) ($model_settings['model'] ?? ''))
        ) {
            throw new RuntimeException('所选 AI 模型配置不完整，请补全接口地址、API 密钥和模型名称。');
        }

        return $model_settings;
    }

    protected function sanitize_editing_preview_fields(array $fields, array $field_schema): array
    {
        $schema_map = array();

        foreach ($field_schema as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ('' !== $key) {
                $schema_map[$key] = (string) ($field['type'] ?? 'text');
            }
        }

        foreach ($fields as $key => $value) {
            $clean_key = sanitize_key((string) $key);
            if ('html' !== ($schema_map[$clean_key] ?? '')) {
                continue;
            }

            $fields[$key] = function_exists('wp_kses_post') ? wp_kses_post((string) $value) : (string) $value;
        }

        return $fields;
    }

    protected function get_publish_success_message(string $post_status, string $subject = '文章'): string
    {
        $labels = array(
            'draft'   => '草稿',
            'pending' => '待审核文章',
            'private' => '私密文章',
            'publish' => '已发布文章',
        );

        return $subject . '已发布为' . ($labels[$post_status] ?? '文章') . '。';
    }

    protected function build_editing_publish_fields(array $field_schema, array $extracted_fields, array $rewritten_fields, array $publish_fields): array
    {
        $allowed = array();

        foreach ($field_schema as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ('' !== $key) {
                $allowed[$key] = true;
            }
        }

        $allowed['source_url'] = true;
        $final_fields = array();

        foreach ($publish_fields as $key) {
            if (! isset($allowed[$key])) {
                continue;
            }

            if (array_key_exists($key, $rewritten_fields) && '' !== trim((string) (is_array($rewritten_fields[$key]) ? wp_json_encode($rewritten_fields[$key]) : $rewritten_fields[$key]))) {
                $final_fields[$key] = $rewritten_fields[$key];
                continue;
            }

            if (array_key_exists($key, $extracted_fields)) {
                $final_fields[$key] = $extracted_fields[$key];
            }
        }

        if (! isset($final_fields['source_url'])) {
            $source_url = (string) ($rewritten_fields['source_url'] ?? $extracted_fields['source_url'] ?? '');
            if ('' !== $source_url) {
                $final_fields['source_url'] = $source_url;
            }
        }

        return $final_fields;
    }

    protected function get_default_admin_author_id(): int
    {
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

    protected function get_request_payload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();

        if (is_array($payload)) {
            return $payload;
        }

        return $request->get_params();
    }
}
