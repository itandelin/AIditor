<?php
declare(strict_types=1);

class AIditor_Queue_Worker
{
    public const CRON_HOOK = 'aiditor_process_run';

    protected const DEFAULT_PROCESS_BATCH_SIZE = 10;

    protected const DEFAULT_PROCESS_TIME_LIMIT = 40;

    protected const DEFAULT_PROCESS_CONCURRENCY = 4;

    protected const INIT_PAGE_SIZE = 100;

    protected const INIT_BUFFER_THRESHOLD = 20;

    protected const MAX_NO_PROGRESS_PAGES = 5;

    protected const LOCK_TTL = 600;

    protected const INIT_LOCK_TTL = 120;

    protected const STALE_ITEM_TTL = 900;

    protected const MAX_ITEM_ATTEMPTS = 3;

    protected const MAX_AI_RETRY_ATTEMPTS = 5;

    protected const MAX_SOURCE_RETRY_ATTEMPTS = 5;

    protected const SOURCE_RETRY_DELAYS = array(60, 300, 900, 1800, 3600);

    protected const AI_RETRY_DELAYS = array(30, 120, 600, 1800, 7200);

    protected const SOURCE_LOCK_RETRY_DELAY = 15;

    protected const STOP_REQUEST_OPTION_PREFIX = 'aiditor_stop_';

    protected AIditor_Run_Repository $runs;

    protected AIditor_Run_Item_Repository $items;

    protected AIditor_Template_Repository $templates;

    protected AIditor_Source_Adapter_Registry $adapter_registry;

    protected AIditor_Page_Fetcher $page_fetcher;

    protected AIditor_Content_Normalizer $normalizer;

    protected AIditor_Deduper $deduper;

    protected AIditor_Settings $settings;

    protected AIditor_AI_Rewriter $rewriter;

    protected AIditor_AI_Extractor $ai_extractor;

    protected AIditor_Draft_Writer $draft_writer;

    public function __construct(
        AIditor_Run_Repository $runs,
        AIditor_Run_Item_Repository $items,
        ...$dependencies
    ) {
        $templates        = null;
        $adapter_registry = null;
        $normalizer       = null;
        $deduper          = null;
        $settings         = null;
        $page_fetcher     = null;
        $rewriter         = null;
        $ai_extractor     = null;
        $draft_writer     = null;

        foreach ($dependencies as $dependency) {
            if ($dependency instanceof AIditor_Template_Repository) {
                $templates = $dependency;
                continue;
            }

            if ($dependency instanceof AIditor_Source_Adapter_Registry) {
                $adapter_registry = $dependency;
                continue;
            }

            if ($dependency instanceof AIditor_Content_Normalizer) {
                $normalizer = $dependency;
                continue;
            }

            if ($dependency instanceof AIditor_Deduper) {
                $deduper = $dependency;
                continue;
            }

            if ($dependency instanceof AIditor_Settings) {
                $settings = $dependency;
                continue;
            }

            if ($dependency instanceof AIditor_Page_Fetcher) {
                $page_fetcher = $dependency;
                continue;
            }

            if ($dependency instanceof AIditor_AI_Rewriter) {
                $rewriter = $dependency;
                continue;
            }

            if ($dependency instanceof AIditor_AI_Extractor) {
                $ai_extractor = $dependency;
                continue;
            }

            if ($dependency instanceof AIditor_Draft_Writer) {
                $draft_writer = $dependency;
            }
        }

        $templates        = $templates instanceof AIditor_Template_Repository ? $templates : new AIditor_Template_Repository();
        $adapter_registry = $adapter_registry instanceof AIditor_Source_Adapter_Registry ? $adapter_registry : new AIditor_Source_Adapter_Registry();
        $normalizer       = $normalizer instanceof AIditor_Content_Normalizer ? $normalizer : new AIditor_Content_Normalizer();
        $deduper          = $deduper instanceof AIditor_Deduper ? $deduper : new AIditor_Deduper();
        $settings         = $settings instanceof AIditor_Settings ? $settings : new AIditor_Settings();
        $page_fetcher     = $page_fetcher instanceof AIditor_Page_Fetcher ? $page_fetcher : new AIditor_Page_Fetcher();
        $rewriter         = $rewriter instanceof AIditor_AI_Rewriter ? $rewriter : new AIditor_AI_Rewriter($settings);
        $ai_extractor     = $ai_extractor instanceof AIditor_AI_Extractor ? $ai_extractor : new AIditor_AI_Extractor($settings, $rewriter);
        $draft_writer     = $draft_writer instanceof AIditor_Draft_Writer ? $draft_writer : new AIditor_Draft_Writer($settings);

        $this->runs             = $runs;
        $this->items            = $items;
        $this->templates        = $templates;
        $this->adapter_registry = $adapter_registry;
        $this->normalizer       = $normalizer;
        $this->deduper          = $deduper;
        $this->settings         = $settings;
        $this->page_fetcher     = $page_fetcher;
        $this->rewriter         = $rewriter;
        $this->ai_extractor     = $ai_extractor;
        $this->draft_writer     = $draft_writer;
    }

    public function register_hooks(): void
    {
        if (function_exists('add_action')) {
            add_action(self::CRON_HOOK, array($this, 'handle_scheduled_run'), 10, 2);
            add_action('wp_ajax_aiditor_worker', array($this, 'handle_async_worker_request'));
            add_action('wp_ajax_nopriv_aiditor_worker', array($this, 'handle_async_worker_request'));
        }
    }

    public function schedule_run(string $run_id, int $delay = 0): void
    {
        $run_id = trim($run_id);

        if ('' === $run_id) {
            return;
        }

        $run = $this->runs->get($run_id);
        if ($this->should_stop_processing($run_id, $run) || ! $this->is_schedulable_run($run)) {
            $this->apply_stop_request_to_run($run_id, $run);
            $this->clear_scheduled_run($run_id);

            return;
        }

        if (! function_exists('wp_schedule_single_event')) {
            return;
        }

        $args             = array($run_id);
        $target_timestamp = time() + max(0, $delay);
        $existing         = function_exists('wp_next_scheduled')
            ? wp_next_scheduled(self::CRON_HOOK, $args)
            : false;
        $scheduled_at     = false === $existing
            ? $target_timestamp
            : min((int) $existing, $target_timestamp);

        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK, $args);
        }

        $run = $this->runs->get($run_id);
        if ($this->should_stop_processing($run_id, $run) || ! $this->is_schedulable_run($run)) {
            $this->apply_stop_request_to_run($run_id, $run);
            $this->clear_scheduled_run($run_id);

            return;
        }

        wp_schedule_single_event($scheduled_at, self::CRON_HOOK, $args);

        $this->runs->update(
            $run_id,
            array(
                'last_scheduled_at' => gmdate('Y-m-d H:i:s'),
                'next_scheduled_at' => gmdate('Y-m-d H:i:s', $scheduled_at),
            )
        );

        if (function_exists('spawn_cron') && ! $this->is_cli_runtime()) {
            @spawn_cron(time());
        }
    }

    public function clear_scheduled_run(string $run_id): void
    {
        $run_id = trim($run_id);

        if ('' === $run_id) {
            return;
        }

        $this->clear_all_scheduled_events($run_id);

        $this->runs->update(
            $run_id,
            array(
                'next_scheduled_at' => null,
            )
        );
    }

    protected function clear_all_scheduled_events(string $run_id): void
    {
        $args = array($run_id);

        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK, $args);
        }

        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_unschedule_event')) {
            return;
        }

        $guard = 0;
        while ($guard < 20) {
            $timestamp = wp_next_scheduled(self::CRON_HOOK, $args);

            if (false === $timestamp) {
                break;
            }

            wp_unschedule_event((int) $timestamp, self::CRON_HOOK, $args);
            ++$guard;
        }

        $this->purge_cron_array_entries(self::CRON_HOOK, $args);
    }

    protected function purge_cron_array_entries(string $hook, array $args): void
    {
        if (! function_exists('_get_cron_array') || ! function_exists('_set_cron_array')) {
            return;
        }

        $cron = _get_cron_array();
        if (! is_array($cron) || empty($cron)) {
            return;
        }

        $key     = md5(serialize($args));
        $changed = false;

        foreach ($cron as $timestamp => $hooks) {
            if (! is_array($hooks) || empty($hooks[$hook][$key])) {
                continue;
            }

            unset($cron[$timestamp][$hook][$key]);

            if (empty($cron[$timestamp][$hook])) {
                unset($cron[$timestamp][$hook]);
            }

            if (empty($cron[$timestamp])) {
                unset($cron[$timestamp]);
            }

            $changed = true;
        }

        if ($changed) {
            _set_cron_array($cron, true);
        }
    }

    public function request_stop(string $run_id, string $status): void
    {
        $run_id = trim($run_id);
        $status = trim($status);

        if ('' === $run_id || ! in_array($status, array('paused', 'cancelled'), true)) {
            return;
        }

        $payload = function_exists('wp_json_encode')
            ? wp_json_encode(
                array(
                    'status'       => $status,
                    'requested_at' => gmdate('Y-m-d H:i:s'),
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
            : json_encode(
                array(
                    'status'       => $status,
                    'requested_at' => gmdate('Y-m-d H:i:s'),
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

        $value = is_string($payload) ? $payload : $status;

        if (function_exists('add_option') && function_exists('update_option')) {
            if (! add_option($this->get_stop_request_key($run_id), $value, '', 'no')) {
                update_option($this->get_stop_request_key($run_id), $value, false);
            }

            return;
        }

        global $wpdb;

        if (! $wpdb instanceof wpdb) {
            return;
        }

        $wpdb->replace(
            $wpdb->options,
            array(
                'option_name'  => $this->get_stop_request_key($run_id),
                'option_value' => $value,
                'autoload'     => 'no',
            ),
            array('%s', '%s', '%s')
        );
    }

    public function clear_stop_request(string $run_id): void
    {
        $run_id = trim($run_id);

        if ('' === $run_id) {
            return;
        }

        if (function_exists('delete_option')) {
            delete_option($this->get_stop_request_key($run_id));

            return;
        }

        global $wpdb;

        if ($wpdb instanceof wpdb) {
            $wpdb->delete($wpdb->options, array('option_name' => $this->get_stop_request_key($run_id)), array('%s'));
        }
    }

    public function get_requested_stop_status(string $run_id): string
    {
        $run_id = trim($run_id);

        if ('' === $run_id) {
            return '';
        }

        global $wpdb;

        if ($wpdb instanceof wpdb) {
            $value = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                    $this->get_stop_request_key($run_id)
                )
            );
        } elseif (function_exists('get_option')) {
            $value = get_option($this->get_stop_request_key($run_id), '');
        } else {
            $value = '';
        }

        if (is_string($value) && '' !== trim($value)) {
            $decoded = json_decode($value, true);
            $status  = is_array($decoded) ? (string) ($decoded['status'] ?? '') : $value;

            return in_array($status, array('paused', 'cancelled'), true) ? $status : '';
        }

        return '';
    }

    public function handle_scheduled_run(string $run_id, int $worker_slot = 0): void
    {
        $this->dispatch_async_workers($run_id);
    }

    public function handle_async_worker_request(): void
    {
        $run_id = isset($_POST['run_id']) ? trim((string) wp_unslash($_POST['run_id'])) : '';
        $token  = isset($_POST['token']) ? trim((string) wp_unslash($_POST['token'])) : '';

        if (! $this->is_valid_async_worker_request($run_id, $token)) {
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error(array('message' => '后台 worker 请求校验失败。'), 403);
            }

            if (function_exists('status_header')) {
                status_header(403);
            }

            echo '后台 worker 请求校验失败。';
            wp_die();
        }

        $this->prepare_async_worker_runtime();
        $run = $this->process_run($run_id);

        if (function_exists('wp_send_json_success')) {
            wp_send_json_success(array('run' => $run));
        }

        echo wp_json_encode(array('success' => true, 'run' => $run), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        wp_die();
    }

    public function dispatch_async_workers(string $run_id, bool $allow_inline_fallback = true): ?array
    {
        $run = $this->runs->get($run_id);

        if (! is_array($run) || $this->should_stop_processing($run_id, $run) || ! $this->is_schedulable_run($run)) {
            $run = $this->apply_stop_request_to_run($run_id, $run);
            $this->clear_scheduled_run($run_id);

            return $run;
        }

        if ($this->should_initialize_more($run)) {
            $run = $this->initialize_run_items($run) ?? $run;
        }

        $run = $this->runs->get($run_id) ?? $run;
        if (! is_array($run) || $this->should_stop_processing($run_id, $run) || ! $this->is_schedulable_run($run)) {
            $run = $this->apply_stop_request_to_run($run_id, $run);
            $this->clear_scheduled_run($run_id);

            return $run;
        }

        $runtime       = $this->get_queue_runtime_settings();
        $summary       = is_array($run['summary'] ?? null) ? $run['summary'] : array();
        $ready_pending = (int) ($summary['ready_pending'] ?? 0);
        $running       = (int) ($summary['running'] ?? 0);
        $slots         = max(0, (int) ($runtime['concurrency'] ?? self::DEFAULT_PROCESS_CONCURRENCY) - $running);

        if ($ready_pending > 0) {
            $slots = min($slots, $ready_pending);
        }

        if ($slots <= 0) {
            return $run;
        }

        $launched = 0;
        for ($index = 0; $index < $slots; ++$index) {
            if ($this->launch_async_worker_request($run_id)) {
                ++$launched;
            }
        }

        if ($launched < 1 && ! $allow_inline_fallback) {
            $this->schedule_run($run_id, 1);

            return $this->runs->update(
                $run_id,
                array(
                    'worker_message' => '已收到手动推进请求，后台调度器将在下一轮继续派发 worker。',
                )
            );
        }

        if ($launched < $slots) {
            $this->schedule_run($run_id, 1);
        }

        if ($launched < 1) {
            return $this->process_run($run_id);
        }

        return $this->runs->update(
            $run_id,
            array(
                'worker_message' => sprintf(
                    $launched < $slots
                        ? '后台调度器已派发 %d 个异步 worker，目标并发 %d；未派发完成的 worker 将由下一轮调度补齐。'
                        : '后台调度器已派发 %d 个异步 worker，目标并发 %d。',
                    $launched,
                    (int) ($runtime['concurrency'] ?? self::DEFAULT_PROCESS_CONCURRENCY)
                ),
            )
        );
    }

    public function process_run(string $run_id): ?array
    {
        $run = $this->runs->get($run_id);
        $next_delay = null;
        $result     = $run;

        if (! is_array($run)) {
            return $run;
        }

        if ($this->should_stop_processing($run_id, $run)) {
            $run = $this->apply_stop_request_to_run($run_id, $run);
            $this->clear_scheduled_run($run_id);

            return $run;
        }

        $runtime     = $this->get_queue_runtime_settings();
        $worker_slot = $this->acquire_worker_slot($run_id, (int) $runtime['concurrency']);

        if (null === $worker_slot) {
            return $run;
        }

        try {
            $run = $this->runs->get($run_id) ?? $run;
            if ($this->should_stop_processing($run_id, $run)) {
                $run = $this->apply_stop_request_to_run($run_id, $run);
                $this->clear_scheduled_run($run_id);

                return $run;
            }

            if (empty($run['started_at'])) {
                $run = $this->runs->update(
                    $run_id,
                    array(
                        'status'     => 'running',
                        'started_at' => gmdate('Y-m-d H:i:s'),
                        'next_scheduled_at' => null,
                    )
                ) ?? $run;
            } elseif ('queued' === (string) ($run['status'] ?? '')) {
                $run = $this->runs->update(
                    $run_id,
                    array(
                        'status' => 'running',
                        'next_scheduled_at' => null,
                    )
                ) ?? $run;
            }

            $this->items->recover_stale_running_items(
                $run_id,
                self::STALE_ITEM_TTL,
                self::MAX_ITEM_ATTEMPTS
            );

            $processed        = 0;
            $started_at       = microtime(true);
            $batch_started_at = gmdate('Y-m-d H:i:s');
            $stop_reason      = 'queue_exhausted';

            $this->runs->update(
                $run_id,
                array(
                    'last_batch_count'       => 0,
                    'last_batch_started_at'  => $batch_started_at,
                    'last_batch_finished_at' => null,
                    'worker_message'         => sprintf(
                        '本轮批处理已开始，最多处理 %d 条，最长执行 %d 秒。',
                        $runtime['batch_size'],
                        $runtime['time_limit']
                    ),
                )
            );

            while ($processed < $runtime['batch_size']) {
                if ($processed > 0 && $this->has_exceeded_process_time_limit($started_at, $runtime['time_limit'])) {
                    $stop_reason = 'time_limit';
                    break;
                }

                $run = $this->runs->get($run_id) ?? $run;

                if ($this->should_stop_processing($run_id, $run)) {
                    $run = $this->apply_stop_request_to_run($run_id, $run);
                    $stop_reason = 'status_changed';
                    break;
                }

                if ($this->should_initialize_more($run)) {
                    $run = $this->initialize_run_items($run) ?? $run;

                    if ($this->should_stop_processing($run_id, $run)) {
                        $run = $this->apply_stop_request_to_run($run_id, $run);
                        $stop_reason = 'status_changed';
                        break;
                    }

                    if ($this->has_future_schedule($run)) {
                        $stop_reason = 'source_retry_waiting';
                        break;
                    }
                }

                $item = $this->items->claim_next_pending($run_id);
                if (! is_array($item)) {
                    $stop_reason = 'queue_waiting';
                    break;
                }

                $run = $this->runs->get($run_id) ?? $run;
                if ($this->should_stop_processing($run_id, $run)) {
                    $run = $this->apply_stop_request_to_run($run_id, $run);
                    $this->stop_current_item($run_id, $item, $this->get_effective_stop_status($run_id, $run));
                    $stop_reason = 'status_changed';
                    break;
                }

                $this->process_item($run, $item);
                ++$processed;
            }

            $this->runs->update(
                $run_id,
                array(
                    'last_batch_count'       => $processed,
                    'last_batch_started_at'  => $batch_started_at,
                    'last_batch_finished_at' => gmdate('Y-m-d H:i:s'),
                    'worker_message'         => $this->build_batch_message($processed, $stop_reason, $runtime),
                )
            );

            $run = $this->apply_stop_request_to_run($run_id, $this->runs->get($run_id));
            $run = $this->finalize_run_state($run_id) ?? $run;
            $result = $this->runs->get($run_id);
        } finally {
            $this->release_worker_slot($run_id, $worker_slot);
        }

        $result     = $this->runs->get($run_id) ?? $result;
        $next_delay = $this->get_reschedule_delay($result);

        if (null !== $next_delay) {
            $this->schedule_run($run_id, $next_delay);
            $result = $this->runs->get($run_id) ?? $result;

            if ($this->is_schedulable_run($result) && ! empty($result['next_scheduled_at'])) {
                $this->runs->update(
                    $run_id,
                    array(
                        'worker_message' => 0 === $next_delay
                            ? '本轮批处理结束，队列中仍有可立即执行的条目，已安排继续执行。'
                            : sprintf('本轮批处理结束，队列将在约 %d 秒后继续执行。', $next_delay),
                    )
                );
                $result = $this->runs->get($run_id) ?? $result;
            }
        } else {
            $this->clear_scheduled_run($run_id);
            $result = $this->runs->get($run_id) ?? $result;
        }

        return $result;
    }

    protected function should_initialize_more(array $run): bool
    {
        $requested = (int) ($run['requested_limit'] ?? $run['limit'] ?? 0);
        $summary   = is_array($run['summary'] ?? null) ? $run['summary'] : array();
        $pending   = (int) ($summary['pending'] ?? 0);
        $exhausted = ! empty($run['source_exhausted']) || ! empty($summary['source_exhausted']);
        $loaded    = (int) ($run['initialized_items'] ?? 0);

        if ($exhausted) {
            return false;
        }

        if ($loaded >= $requested) {
            return false;
        }

        return $pending < self::INIT_BUFFER_THRESHOLD;
    }

    protected function initialize_run_items(array $run): ?array
    {
        $source_url = trim((string) ($run['source_url'] ?? ''));
        $run_id     = trim((string) ($run['run_id'] ?? ''));
        $requested  = (int) ($run['requested_limit'] ?? $run['limit'] ?? 0);
        $loaded     = (int) ($run['initialized_items'] ?? 0);

        if ('' === $source_url || '' === $run_id || $loaded >= $requested) {
            return $this->runs->update($run_id, array('source_exhausted' => 1));
        }

        if ($this->should_stop_processing($run_id, $run)) {
            return $this->apply_stop_request_to_run($run_id, $run);
        }

        if (! $this->acquire_initialization_lock($run_id)) {
            return $run;
        }

        try {
            $fresh_run = $this->runs->get($run_id) ?? $run;
            $fresh_requested = (int) ($fresh_run['requested_limit'] ?? $fresh_run['limit'] ?? $requested);
            $fresh_loaded    = (int) ($fresh_run['initialized_items'] ?? $loaded);

            if ($this->should_stop_processing($run_id, $fresh_run)) {
                return $this->apply_stop_request_to_run($run_id, $fresh_run);
            }

            if ($fresh_loaded >= $fresh_requested || ! $this->should_initialize_more($fresh_run)) {
                return $fresh_run;
            }

            $page      = max(1, (int) ($fresh_run['current_page'] ?? 1));
            $page_size = min(self::INIT_PAGE_SIZE, max(1, $fresh_requested - $fresh_loaded));
            try {
                $items = $this->discover_generic_items($fresh_run, $source_url, $page_size);
            } catch (Throwable $exception) {
                if ($this->is_retryable_source_exception($exception)) {
                    return $this->defer_source_initialization($run_id, $exception);
                }

                throw $exception;
            }

            $this->items->insert_items($run_id, $items, $fresh_loaded);
            $discovered = $this->items->get_total_count($run_id);
            $made_progress = $discovered > $fresh_loaded;
            $no_progress_pages = $made_progress
                ? 0
                : ((int) ($fresh_run['no_progress_pages'] ?? 0)) + 1;
            $source_exhausted = true;
            $latest_run = $this->runs->get($run_id) ?? $fresh_run;

            if ($this->should_stop_processing($run_id, $latest_run)) {
                return $this->apply_stop_request_to_run($run_id, $latest_run);
            }

            return $this->runs->update(
                $run_id,
                array(
                    'current_page'      => $page + 1,
                    'initialized_items' => $discovered,
                    'no_progress_pages' => $no_progress_pages,
                    'source_exhausted'  => $source_exhausted ? 1 : 0,
                )
            );
        } finally {
            $this->release_initialization_lock($run_id);
        }
    }

    protected function discover_generic_items(array $run, string $source_url, int $limit): array
    {
        $template = $this->get_run_template($run);
        $mode     = (string) ($template['source_mode'] ?? 'list');

        if ('detail' === $mode) {
            return array($this->build_generic_item($source_url, (string) ($template['name'] ?? $source_url), $template));
        }

        $page = $this->page_fetcher->fetch($source_url);
        $model_settings = $this->get_run_model_settings($run);
        $result = $this->ai_extractor->discover_detail_urls(
            $page,
            (string) ($template['extraction_prompt'] ?? '请识别列表页中的详情页 URL。'),
            $limit,
            $model_settings
        );

        $items = array();

        foreach ((array) ($result['items'] ?? array()) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $url = esc_url_raw((string) ($entry['url'] ?? ''));
            if ('' === $url) {
                continue;
            }

            $items[] = $this->build_generic_item($url, (string) ($entry['title'] ?? $url), $template, $entry);
        }

        return $items;
    }

    protected function build_generic_item(string $url, string $title, array $template, array $extra = array()): array
    {
        $slug = md5((string) ($template['template_id'] ?? 'generic') . '|' . $url);

        return array(
            'source_site' => 'generic_ai',
            'slug'        => $slug,
            'title'       => '' !== trim($title) ? trim($title) : $url,
            'summary'     => (string) ($extra['reason'] ?? ''),
            'source_url'  => $url,
            'homepage'    => $url,
            'version'     => (string) ($template['updated_at'] ?? ''),
            'payload'     => array(
                'template_id' => (string) ($template['template_id'] ?? ''),
                'template'    => $template,
                'discovery'   => $extra,
            ),
        );
    }

    protected function process_item(array $run, array $item): ?array
    {
        $run_id      = (string) ($run['run_id'] ?? '');
        $source_site = (string) ($item['source_site'] ?? 'generic_ai');
        $slug        = trim((string) ($item['slug'] ?? ''));
        $title       = trim((string) ($item['title'] ?? $slug));

        if ('' !== $run_id && $this->should_stop_processing($run_id, $run)) {
            return $this->stop_current_item($run_id, $item, $this->get_effective_stop_status($run_id, $run));
        }

        if ('' === $slug) {
            return $this->items->update_item(
                (int) ($item['id'] ?? 0),
                array(
                    'status'       => 'failed',
                    'message'      => '缺少来源 slug。',
                    'processed_at' => gmdate('Y-m-d H:i:s'),
                )
            );
        }

        if (! $this->deduper->acquire_source_lock($source_site, $slug)) {
            if ('' !== $run_id && $this->should_stop_processing($run_id, $run)) {
                return $this->stop_current_item($run_id, $item, $this->get_effective_stop_status($run_id, $run));
            }

            return $this->defer_locked_source_item($item);
        }

        try {
            if ('' !== $run_id && $this->should_stop_processing($run_id, $run)) {
                return $this->stop_current_item($run_id, $item, $this->get_effective_stop_status($run_id, $run));
            }

            $context         = is_array($item['payload'] ?? null) ? $item['payload'] : array();
            $template        = is_array($context['template'] ?? null) ? $context['template'] : $this->get_run_template($run);
            $model_settings  = $this->get_run_model_settings($run);
            $detail_url      = trim((string) ($item['source_url'] ?? ''));
            $dedupe          = $this->deduper->inspect($source_site, $slug, array((string) ($run['post_type'] ?? 'post')));
            $existing_post_id = ! empty($dedupe['is_duplicate']) ? (int) ($dedupe['post_id'] ?? 0) : 0;

            if ('' === $detail_url) {
                throw new RuntimeException('缺少详情页 URL。');
            }

            $page = $this->page_fetcher->fetch($detail_url);

            if ('' !== $run_id && $this->should_stop_processing($run_id, $run)) {
                return $this->stop_current_item($run_id, $item, $this->get_effective_stop_status($run_id, $run));
            }

            $extracted = $this->ai_extractor->extract_fields(
                $page,
                is_array($template['field_schema'] ?? null) ? $template['field_schema'] : array(),
                (string) ($template['extraction_prompt'] ?? '请抽取页面标题、摘要、正文和主要网址。'),
                $model_settings
            );

            if ('' !== $run_id && $this->should_stop_processing($run_id, $run)) {
                return $this->stop_current_item($run_id, $item, $this->get_effective_stop_status($run_id, $run));
            }

            $extracted = $this->rewrite_extracted_fields($extracted, $template, $model_settings);

            if ('' !== $run_id && $this->should_stop_processing($run_id, $run)) {
                return $this->stop_current_item($run_id, $item, $this->get_effective_stop_status($run_id, $run));
            }

            $normalized = $this->build_generic_normalized_payload($item, $page, $extracted, $template);
            $source_hash = AIditor_Draft_Writer::build_source_hash($normalized);

            if ('' !== $run_id && $this->should_stop_processing($run_id, $run)) {
                return $this->stop_current_item($run_id, $item, $this->get_effective_stop_status($run_id, $run));
            }

            if ($existing_post_id > 0 && ! $this->deduper->has_aiditor_source_identity($existing_post_id, $source_site, $slug)) {
                return $this->items->update_item(
                    (int) ($item['id'] ?? 0),
                    array(
                        'status'       => 'skipped',
                        'post_id'      => $existing_post_id,
                        'message'      => '检测到可能重复的文章，但来源标识不完整，已跳过以避免覆盖已有内容。',
                        'processed_at' => gmdate('Y-m-d H:i:s'),
                    )
                );
            }

            if ($existing_post_id > 0 && $this->deduper->is_post_source_hash_current($existing_post_id, $source_hash)) {
                return $this->items->update_item(
                    (int) ($item['id'] ?? 0),
                    array(
                        'status'       => 'skipped',
                        'post_id'      => $existing_post_id,
                        'message'      => '已有文章的来源内容未变化，已自动跳过。',
                        'processed_at' => gmdate('Y-m-d H:i:s'),
                    )
                );
            }

            $article = $this->build_generic_article_payload($normalized, $extracted, $model_settings);

            if ('' !== $run_id && $this->should_stop_processing($run_id, $run)) {
                return $this->stop_current_item($run_id, $item, $this->get_effective_stop_status($run_id, $run));
            }

            $post_id    = $this->draft_writer->write(
                $article,
                $normalized,
                array(
                    'post_type'       => (string) ($run['post_type'] ?? 'post'),
                    'post_status'     => $this->get_run_post_status($run),
                    'target_taxonomy' => (string) ($run['target_taxonomy'] ?? ''),
                    'target_term_id'  => (int) ($run['target_term_id'] ?? 0),
                    'author_id'       => (int) ($run['author_id'] ?? 0),
                    'extra_tax_terms' => is_array($run['extra_tax_terms'] ?? null) ? $run['extra_tax_terms'] : array(),
                    'existing_post_id' => $existing_post_id,
                )
            );
            $status     = $existing_post_id > 0 ? 'updated' : 'created';
            $message    = $existing_post_id > 0 ? '已有文章已就地更新。' : $this->get_created_item_message($this->get_run_post_status($run));

            return $this->items->update_item(
                (int) ($item['id'] ?? 0),
                array(
                    'status'       => $status,
                    'post_id'      => $post_id,
                    'message'      => $message,
                    'processed_at' => gmdate('Y-m-d H:i:s'),
                )
            );
        } catch (Throwable $exception) {
            if ($exception instanceof AIditor_AI_Request_Exception) {
                return $this->handle_ai_failure($run, $item, $exception);
            }

            if ($this->is_retryable_source_exception($exception)) {
                return $this->handle_source_failure($run, $item, $exception);
            }

            $this->runs->update(
                (string) ($run['run_id'] ?? ''),
                array(
                    'last_error' => $exception->getMessage(),
                )
            );

            return $this->items->update_item(
                (int) ($item['id'] ?? 0),
                array(
                    'status'       => 'failed',
                    'message'      => $exception->getMessage(),
                    'processed_at' => gmdate('Y-m-d H:i:s'),
                )
            );
        } finally {
            $this->deduper->release_source_lock($source_site, $slug);
        }
    }

    protected function rewrite_extracted_fields(array $fields, array $template, array $model_settings): array
    {
        $rewrite_fields = is_array($template['rewrite_fields'] ?? null) ? $template['rewrite_fields'] : array();

        if (empty($rewrite_fields)) {
            return $fields;
        }

        return $this->rewriter->rewrite_fields(
            $fields,
            is_array($template['field_schema'] ?? null) ? $template['field_schema'] : array(),
            $rewrite_fields,
            (string) ($template['extraction_prompt'] ?? ''),
            $model_settings
        );
    }

    protected function get_run_model_settings(array $run): array
    {
        $extra = is_array($run['extra_tax_terms'] ?? null) ? $run['extra_tax_terms'] : array();
        $profile_id = trim((string) ($extra['_aiditor_model_profile_id'] ?? ''));
        $model_settings = $this->settings->resolve_model_settings($profile_id);

        if ('' !== $profile_id && $profile_id !== (string) ($model_settings['model_profile_id'] ?? '')) {
            throw new RuntimeException('当前任务选择的 AI 模型配置已不存在，请重新创建任务。');
        }

        return $model_settings;
    }

    protected function get_run_template(array $run): array
    {
        $extra = is_array($run['extra_tax_terms'] ?? null) ? $run['extra_tax_terms'] : array();
        $template_id = trim((string) ($extra['_aiditor_template_id'] ?? ''));

        if ('' === $template_id) {
            throw new RuntimeException('当前任务缺少采集模板 ID。');
        }

        $template = $this->templates->get($template_id);

        if (! is_array($template)) {
            throw new RuntimeException('未找到当前任务使用的采集模板。');
        }

        return $template;
    }

    protected function build_generic_normalized_payload(array $item, array $page, array $fields, array $template): array
    {
        $title = trim((string) ($fields['title'] ?? $page['title'] ?? $item['title'] ?? ''));
        $summary = trim((string) ($fields['summary'] ?? $fields['description'] ?? $page['description'] ?? $item['summary'] ?? ''));
        $content = trim((string) ($fields['content'] ?? $fields['body'] ?? $page['text'] ?? ''));
        $url = esc_url_raw((string) ($fields['url'] ?? $fields['homepage'] ?? $page['url'] ?? $item['source_url'] ?? ''));

        return array(
            'source_site'          => 'generic_ai',
            'source_slug'          => (string) ($item['source_slug'] ?? $item['slug'] ?? md5($url)),
            'source_version'       => (string) ($template['updated_at'] ?? ''),
            'source_url'           => (string) ($page['url'] ?? $item['source_url'] ?? ''),
            'source_homepage'      => $url,
            'source_reference_url' => $url,
            'source_title'         => '' !== $title ? $title : (string) ($item['title'] ?? '未命名内容'),
            'source_summary'       => $summary,
            'source_summary_zh'    => $summary,
            'source_markdown'      => $content,
            'generic_fields'       => $fields,
            'template_id'          => (string) ($template['template_id'] ?? ''),
        );
    }

    protected function build_generic_article_payload(array $normalized, array $fields, array $model_settings = array()): array
    {
        $content = trim((string) ($fields['content'] ?? $normalized['source_markdown'] ?? ''));

        if ('' === $content) {
            $content = trim((string) ($normalized['source_summary_zh'] ?? $normalized['source_summary'] ?? ''));
        }

        if ('' === $content) {
            $content = '该页面未抽取到可用正文内容。';
        }

        if (! preg_match('/<[a-z][\s\S]*>/i', $content)) {
            $paragraphs = preg_split('/\n{2,}/', $content);
            $html_parts = array();

            foreach ((array) $paragraphs as $paragraph) {
                $paragraph = trim((string) $paragraph);
                if ('' !== $paragraph) {
                    $html_parts[] = '<p>' . esc_html($paragraph) . '</p>';
                }
            }

            $content = implode("\n", $html_parts);
        }

        return array(
            'article_excerpt'   => (string) ($fields['summary'] ?? $fields['description'] ?? $normalized['source_summary_zh'] ?? ''),
            'article_html'      => $content,
            'seo_title'         => '',
            'seo_description'   => (string) ($fields['summary'] ?? $fields['description'] ?? $normalized['source_summary_zh'] ?? ''),
            'suggested_tags'    => array(),
            'ai_model'          => (string) ($model_settings['model'] ?? $this->settings->get()['model'] ?? ''),
            'ai_generated_at'   => gmdate('c'),
            'generic_fields'    => $fields,
        );
    }

    protected function defer_source_initialization(string $run_id, Throwable $exception): ?array
    {
        $delay = $this->calculate_source_retry_delay(1);
        $next_retry_at = gmdate('Y-m-d H:i:s', time() + $delay);
        $message = sprintf('来源站暂时无法访问，已暂停列表初始化，将在 %s 后重试。错误：%s', $next_retry_at, $exception->getMessage());

        return $this->runs->update(
            $run_id,
            array(
                'last_error'        => $exception->getMessage(),
                'next_scheduled_at' => $next_retry_at,
                'worker_message'    => $message,
            )
        );
    }

    protected function handle_source_failure(array $run, array $item, Throwable $exception): ?array
    {
        $run_id  = (string) ($run['run_id'] ?? '');
        $item_id = (int) ($item['id'] ?? 0);
        $message = $exception->getMessage();

        if ('' !== $run_id) {
            $this->runs->update(
                $run_id,
                array(
                    'last_error' => $message,
                )
            );
        }

        $retry_count = ((int) ($item['retry_count'] ?? 0)) + 1;

        if ($retry_count > self::MAX_SOURCE_RETRY_ATTEMPTS) {
            return $this->items->update_item(
                $item_id,
                array(
                    'status'           => 'failed',
                    'retry_count'      => $retry_count,
                    'last_retry_error' => $message,
                    'message'          => sprintf('来源站连续重试 %d 次后仍不可用，已标记为失败。最后错误：%s', $retry_count - 1, $message),
                    'processed_at'     => gmdate('Y-m-d H:i:s'),
                )
            );
        }

        $delay = $this->calculate_source_retry_delay($retry_count);
        $next_retry_at = gmdate('Y-m-d H:i:s', time() + $delay);

        return $this->items->update_item(
            $item_id,
            array(
                'status'           => 'pending',
                'retry_count'      => $retry_count,
                'next_retry_at'    => $next_retry_at,
                'last_retry_error' => $message,
                'message'          => sprintf('来源站暂时无法访问，已安排第 %d 次重试，将在 %s 重试。错误：%s', $retry_count, $next_retry_at, $message),
                'processed_at'     => null,
            )
        );
    }

    protected function is_retryable_source_exception(Throwable $exception): bool
    {
        if ($exception instanceof AIditor_Source_Request_Exception) {
            return $exception->is_retryable();
        }

        $message = strtolower($exception->getMessage());
        $needles = array(
            'could not resolve host',
            'resolving timed out',
            'operation timed out',
            'connection timed out',
            'connection refused',
            'connection reset',
            'failed to connect',
            'empty reply from server',
            'temporarily unavailable',
            'name resolution',
            'cURL error 6',
            'cURL error 7',
            'cURL error 28',
            'http 状态码为 429',
            'http 状态码为 500',
            'http 状态码为 502',
            'http 状态码为 503',
            'http 状态码为 504',
        );

        foreach ($needles as $needle) {
            if (false !== strpos($message, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    protected function calculate_source_retry_delay(int $retry_count): int
    {
        $retry_count = max(1, $retry_count);
        $index = min($retry_count - 1, count(self::SOURCE_RETRY_DELAYS) - 1);

        return (int) self::SOURCE_RETRY_DELAYS[$index];
    }

    protected function get_run_post_status(array $run): string
    {
        $status = trim((string) ($run['post_status'] ?? ''));

        if ('' === $status) {
            $settings = $this->settings->get();
            $status = trim((string) ($settings['default_post_status'] ?? 'draft'));
        }

        return in_array($status, array('draft', 'pending', 'private', 'publish'), true) ? $status : 'draft';
    }

    protected function get_created_item_message(string $post_status): string
    {
        switch ($post_status) {
            case 'publish':
                return '文章发布成功。';
            case 'pending':
                return '待审核文章创建成功。';
            case 'private':
                return '私密文章创建成功。';
            case 'draft':
            default:
                return '草稿创建成功。';
        }
    }

    protected function defer_locked_source_item(array $item): ?array
    {
        $next_retry_at = gmdate('Y-m-d H:i:s', time() + self::SOURCE_LOCK_RETRY_DELAY);

        return $this->items->update_item(
            (int) ($item['id'] ?? 0),
            array(
                'status'        => 'pending',
                'next_retry_at' => $next_retry_at,
                'message'       => sprintf('同一来源内容正在被其他任务处理，已回队等待，将在 %s 后重试。', $next_retry_at),
                'processed_at'  => null,
            )
        );
    }

    protected function stop_current_item(string $run_id, array $item, string $stop_status): ?array
    {
        $stop_status = in_array($stop_status, array('paused', 'cancelled'), true)
            ? $stop_status
            : $this->get_effective_stop_status($run_id, $this->runs->get($run_id));

        $this->apply_stop_request_to_run($run_id, $this->runs->get($run_id));

        if ('cancelled' === $stop_status) {
            return $this->items->update_item(
                (int) ($item['id'] ?? 0),
                array(
                    'status'       => 'cancelled',
                    'next_retry_at' => null,
                    'processed_at' => gmdate('Y-m-d H:i:s'),
                    'message'      => '任务已取消，当前条目已停止处理。',
                )
            );
        }

        return $this->items->update_item(
            (int) ($item['id'] ?? 0),
            array(
                'status'        => 'pending',
                'next_retry_at' => null,
                'processed_at'  => null,
                'message'       => '任务已暂停，当前条目已退回队列，恢复后可继续处理。',
            )
        );
    }

    protected function should_stop_processing(string $run_id, ?array $run = null): bool
    {
        if ('' !== $this->get_requested_stop_status($run_id)) {
            return true;
        }

        if (is_array($run) && $this->is_stopped_status((string) ($run['status'] ?? ''))) {
            return true;
        }

        $fresh_run = '' !== trim($run_id) ? $this->runs->get($run_id) : null;

        return is_array($fresh_run) && $this->is_stopped_status((string) ($fresh_run['status'] ?? ''));
    }

    protected function get_effective_stop_status(string $run_id, ?array $run = null): string
    {
        $requested_status = $this->get_requested_stop_status($run_id);

        if ('' !== $requested_status) {
            return $requested_status;
        }

        if (! is_array($run)) {
            $run = '' !== trim($run_id) ? $this->runs->get($run_id) : null;

            if (! is_array($run)) {
                return '';
            }
        }

        $status = (string) ($run['status'] ?? '');

        if ($this->is_stopped_status($status)) {
            return $status;
        }

        $fresh_run = '' !== trim($run_id) ? $this->runs->get($run_id) : null;

        if (! is_array($fresh_run)) {
            return '';
        }

        $fresh_status = (string) ($fresh_run['status'] ?? '');

        return $this->is_stopped_status($fresh_status) ? $fresh_status : '';
    }

    protected function apply_stop_request_to_run(string $run_id, ?array $run = null): ?array
    {
        $run_id = trim($run_id);

        if ('' === $run_id) {
            return $run;
        }

        $stop_status = $this->get_effective_stop_status($run_id, $run);

        if (! in_array($stop_status, array('paused', 'cancelled'), true)) {
            return $run;
        }

        $this->clear_all_scheduled_events($run_id);

        $current_status = is_array($run) ? (string) ($run['status'] ?? '') : '';
        $patch          = array(
            'status'            => $stop_status,
            'next_scheduled_at' => null,
            'worker_message'    => 'paused' === $stop_status
                ? '任务已暂停，后台 worker 会在最近的安全检查点退出。'
                : '任务已取消，后台 worker 会在最近的安全检查点退出。',
        );

        if ('cancelled' === $stop_status && (empty($run['finished_at']) || 'cancelled' !== $current_status)) {
            $patch['finished_at'] = gmdate('Y-m-d H:i:s');
        }

        if ($current_status === $stop_status && empty($run['next_scheduled_at']) && ! isset($patch['finished_at'])) {
            return $run;
        }

        return $this->runs->update($run_id, $patch);
    }

    protected function handle_ai_failure(array $run, array $item, Throwable $exception): ?array
    {
        $run_id  = (string) ($run['run_id'] ?? '');
        $item_id = (int) ($item['id'] ?? 0);
        $message = $exception->getMessage();

        if ('' !== $run_id) {
            $this->runs->update(
                $run_id,
                array(
                    'last_error' => $message,
                )
            );
        }

        if (! $this->rewriter->is_retryable_exception($exception)) {
            return $this->items->update_item(
                $item_id,
                array(
                    'status'       => 'failed',
                    'message'      => $message,
                    'processed_at' => gmdate('Y-m-d H:i:s'),
                )
            );
        }

        $retry_count = ((int) ($item['retry_count'] ?? 0)) + 1;

        if ($retry_count > self::MAX_AI_RETRY_ATTEMPTS) {
            return $this->items->update_item(
                $item_id,
                array(
                    'status'           => 'failed',
                    'retry_count'      => $retry_count,
                    'last_retry_error' => $message,
                    'message'          => sprintf('AI 服务连续重试 %d 次后仍未恢复，已标记为失败。最后错误：%s', $retry_count - 1, $message),
                    'processed_at'     => gmdate('Y-m-d H:i:s'),
                )
            );
        }

        $delay         = $this->calculate_ai_retry_delay($retry_count, $this->rewriter->get_retry_after_seconds($exception));
        $next_retry_at = gmdate('Y-m-d H:i:s', time() + $delay);

        return $this->items->update_item(
            $item_id,
            array(
                'status'           => 'pending',
                'retry_count'      => $retry_count,
                'next_retry_at'    => $next_retry_at,
                'last_retry_error' => $message,
                'message'          => sprintf('AI 服务暂时不可用，已安排第 %d 次重试，将在 %s 重试。', $retry_count, $next_retry_at),
                'processed_at'     => null,
            )
        );
    }

    protected function finalize_run_state(string $run_id): ?array
    {
        $run = $this->runs->get($run_id);

        if (! is_array($run)) {
            return null;
        }

        if ($this->should_stop_processing($run_id, $run)) {
            return $this->apply_stop_request_to_run($run_id, $run);
        }

        $status = (string) ($run['status'] ?? '');
        if ($this->is_paused_status($status) || 'cancelled' === $status) {
            return $run;
        }

        $summary = is_array($run['summary'] ?? null) ? $run['summary'] : array();
        $pending = (int) ($summary['pending'] ?? 0);
        $running = (int) ($summary['running'] ?? 0);
        $failed  = (int) ($summary['failed'] ?? 0);
        $exhausted = ! empty($run['source_exhausted']) || ! empty($summary['source_exhausted']);

        if ($exhausted && 0 === $pending && 0 === $running) {
            return $this->runs->update(
                $run_id,
                array(
                    'status'      => $failed > 0 ? 'completed_with_errors' : 'completed',
                    'finished_at' => gmdate('Y-m-d H:i:s'),
                    'next_scheduled_at' => null,
                    'worker_message' => $failed > 0
                        ? '任务已完成，但存在处理失败的条目。'
                        : '任务已全部处理完成。',
                )
            );
        }

        return $this->runs->update(
            $run_id,
            array(
                'status'      => 'running',
            )
        );
    }

    protected function get_reschedule_delay(?array $run): ?int
    {
        if (! is_array($run)) {
            return null;
        }

        $status = (string) ($run['status'] ?? '');
        $run_id  = trim((string) ($run['run_id'] ?? ''));

        if ('' !== $run_id && '' !== $this->get_requested_stop_status($run_id)) {
            return null;
        }

        if ($this->is_stopped_status($status)) {
            return null;
        }

        $summary = is_array($run['summary'] ?? null) ? $run['summary'] : array();
        $running = (int) ($summary['running'] ?? 0);

        if ($running > 0) {
            return 0;
        }

        if ('' !== $run_id && $this->items->has_ready_pending($run_id)) {
            return 0;
        }

        $next_scheduled_at = (string) ($run['next_scheduled_at'] ?? '');
        if ('' !== $next_scheduled_at) {
            $timestamp = strtotime($next_scheduled_at);
            if (false !== $timestamp && $timestamp > time()) {
                return max(1, $timestamp - time());
            }
        }

        if ($this->should_initialize_more($run)) {
            return 0;
        }

        if ('' !== $run_id) {
            $next_retry_at = $this->items->get_next_retry_at($run_id);

            if (is_string($next_retry_at) && '' !== $next_retry_at) {
                $timestamp = strtotime($next_retry_at);

                if (false !== $timestamp) {
                    return max(1, $timestamp - time());
                }
            }
        }

        return null;
    }

    protected function has_future_schedule(array $run): bool
    {
        $next_scheduled_at = (string) ($run['next_scheduled_at'] ?? '');
        if ('' === $next_scheduled_at) {
            return false;
        }

        $timestamp = strtotime($next_scheduled_at);

        return false !== $timestamp && $timestamp > time();
    }

    protected function has_exceeded_process_time_limit(float $started_at, int $time_limit): bool
    {
        return (microtime(true) - $started_at) >= $time_limit;
    }

    protected function get_queue_runtime_settings(): array
    {
        $settings = $this->settings->get();

        return array(
            'batch_size' => max(1, min(50, (int) ($settings['queue_batch_size'] ?? self::DEFAULT_PROCESS_BATCH_SIZE))),
            'time_limit' => max(5, min(180, (int) ($settings['queue_time_limit'] ?? self::DEFAULT_PROCESS_TIME_LIMIT))),
            'concurrency' => max(1, min(20, (int) ($settings['queue_concurrency'] ?? self::DEFAULT_PROCESS_CONCURRENCY))),
        );
    }

    protected function build_batch_message(int $processed, string $stop_reason, array $runtime): string
    {
        switch ($stop_reason) {
            case 'time_limit':
                return sprintf('本轮已处理 %d 条，达到 %d 秒时间片上限，准备继续下一轮。', $processed, (int) $runtime['time_limit']);
            case 'status_changed':
                return sprintf('本轮已处理 %d 条，任务状态发生变化，当前批处理已结束。', $processed);
            case 'queue_waiting':
                return sprintf('本轮已处理 %d 条，当前没有可立即执行的条目，正在等待下一次调度。', $processed);
            case 'source_retry_waiting':
                return sprintf('本轮已处理 %d 条，来源站暂时无法访问，已等待下一次自动重试。', $processed);
            default:
                return sprintf('本轮已处理 %d 条，达到本轮处理上限 %d 条。', $processed, (int) $runtime['batch_size']);
        }
    }

    public function cleanup_runtime_options(int $limit = 500): int
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb) {
            return 0;
        }

        $limit = max(1, min(5000, $limit));
        $now   = time();
        $keys  = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE (option_name LIKE %s OR option_name LIKE %s)
                   AND CAST(option_value AS UNSIGNED) > 0
                   AND CAST(option_value AS UNSIGNED) < %d
                 LIMIT %d",
                'aiditor_worker_slot_%',
                'aiditor_init_lock_%',
                $now,
                $limit
            )
        );

        if (! is_array($keys) || empty($keys)) {
            return 0;
        }

        $deleted = 0;
        foreach ($keys as $key) {
            if (function_exists('delete_option') && delete_option((string) $key)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    public function clear_run_runtime(string $run_id): int
    {
        $run_id = trim($run_id);

        if ('' === $run_id) {
            return 0;
        }

        $deleted = 0;

        $this->clear_scheduled_run($run_id);
        $this->clear_stop_request($run_id);

        if (function_exists('delete_option')) {
            for ($slot = 1; $slot <= 20; ++$slot) {
                if (delete_option($this->get_worker_slot_key($run_id, $slot))) {
                    ++$deleted;
                }
            }

            if (delete_option($this->get_initialization_lock_key($run_id))) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    protected function is_cli_runtime(): bool
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return in_array(PHP_SAPI, array('cli', 'phpdbg'), true);
    }


    protected function is_terminal_status(string $status): bool
    {
        return in_array($status, array('completed', 'completed_with_errors', 'failed'), true);
    }

    protected function is_stopped_status(string $status): bool
    {
        return $this->is_terminal_status($status) || $this->is_paused_status($status) || 'cancelled' === $status;
    }

    protected function is_paused_status(string $status): bool
    {
        return 'paused' === $status;
    }

    protected function is_schedulable_run(?array $run): bool
    {
        return is_array($run) && ! $this->is_stopped_status((string) ($run['status'] ?? ''));
    }

    protected function acquire_worker_slot(string $run_id, int $max_slots): ?int
    {
        if (! function_exists('add_option') || ! function_exists('get_option') || ! function_exists('delete_option')) {
            return 1;
        }

        $max_slots = max(1, min(20, $max_slots));
        $now       = time();

        for ($slot = 1; $slot <= $max_slots; ++$slot) {
            $key        = $this->get_worker_slot_key($run_id, $slot);
            $expires_at = $now + self::LOCK_TTL;

            if (add_option($key, (string) $expires_at, '', 'no')) {
                return $slot;
            }

            $current_expires_at = (int) get_option($key, 0);
            if ($current_expires_at > 0 && $current_expires_at < $now) {
                delete_option($key);

                if (add_option($key, (string) $expires_at, '', 'no')) {
                    return $slot;
                }
            }
        }

        return null;
    }

    protected function release_worker_slot(string $run_id, ?int $slot): void
    {
        if (null === $slot || $slot <= 0 || ! function_exists('delete_option')) {
            return;
        }

        delete_option($this->get_worker_slot_key($run_id, $slot));
    }

    protected function acquire_initialization_lock(string $run_id): bool
    {
        if (! function_exists('add_option') || ! function_exists('get_option') || ! function_exists('delete_option')) {
            return true;
        }

        $key        = $this->get_initialization_lock_key($run_id);
        $now        = time();
        $expires_at = $now + self::INIT_LOCK_TTL;

        if (add_option($key, (string) $expires_at, '', 'no')) {
            return true;
        }

        $current_expires_at = (int) get_option($key, 0);
        if ($current_expires_at > 0 && $current_expires_at < $now) {
            delete_option($key);

            return add_option($key, (string) $expires_at, '', 'no');
        }

        return false;
    }

    protected function release_initialization_lock(string $run_id): void
    {
        if (! function_exists('delete_option')) {
            return;
        }

        delete_option($this->get_initialization_lock_key($run_id));
    }

    protected function get_worker_slot_key(string $run_id, int $slot): string
    {
        return 'aiditor_worker_slot_' . md5($run_id) . '_' . $slot;
    }

    protected function get_initialization_lock_key(string $run_id): string
    {
        return 'aiditor_init_lock_' . md5($run_id);
    }

    protected function get_stop_request_key(string $run_id): string
    {
        return self::STOP_REQUEST_OPTION_PREFIX . md5($run_id);
    }

    protected function launch_async_worker_request(string $run_id): bool
    {
        if (! function_exists('wp_remote_post') || ! function_exists('admin_url')) {
            return false;
        }

        $response = wp_remote_post(
            admin_url('admin-ajax.php'),
            array(
                'blocking'  => false,
                'timeout'   => 2,
                'redirection' => 0,
                'sslverify' => $this->should_verify_ssl(),
                'body'      => array(
                    'action' => 'aiditor_worker',
                    'run_id' => $run_id,
                    'token'  => $this->create_async_worker_token($run_id, time()),
                ),
            )
        );

        if (is_wp_error($response)) {
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return 0 === $status || ($status >= 200 && $status < 400);
    }

    protected function create_async_worker_token(string $run_id, ?int $timestamp = null): string
    {
        $timestamp = null === $timestamp ? time() : $timestamp;
        $secret = function_exists('wp_salt') ? wp_salt('auth') : '';

        if ('' === trim($secret)) {
            $secret = defined('AUTH_KEY') ? (string) AUTH_KEY : __FILE__;
        }

        return $timestamp . ':' . hash_hmac('sha256', $run_id . '|' . $timestamp, $secret);
    }

    protected function is_valid_async_worker_request(string $run_id, string $token): bool
    {
        if ('' === $run_id || '' === $token || false === strpos($token, ':')) {
            return false;
        }

        list($timestamp_raw) = explode(':', $token, 2);
        if (! ctype_digit($timestamp_raw)) {
            return false;
        }

        $timestamp = (int) $timestamp_raw;
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        return hash_equals($this->create_async_worker_token($run_id, $timestamp), $token);
    }

    protected function prepare_async_worker_runtime(): void
    {
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        if (! function_exists('set_time_limit')) {
            return;
        }

        $settings = $this->settings->get();
        $seconds  = max(
            60,
            (int) ($settings['request_timeout'] ?? 60) + (int) ($settings['queue_time_limit'] ?? self::DEFAULT_PROCESS_TIME_LIMIT) + 30
        );

        @set_time_limit($seconds);
    }

    protected function should_verify_ssl(): bool
    {
        if (! function_exists('admin_url')) {
            return true;
        }

        return 0 === strpos(admin_url('admin-ajax.php'), 'https://');
    }

    protected function calculate_ai_retry_delay(int $retry_count, ?int $retry_after_seconds = null): int
    {
        if (null !== $retry_after_seconds && $retry_after_seconds > 0) {
            return max(1, $retry_after_seconds);
        }

        $index = min(count(self::AI_RETRY_DELAYS) - 1, max(0, $retry_count - 1));
        $base  = self::AI_RETRY_DELAYS[$index];

        return $base + random_int(0, 5);
    }
}
