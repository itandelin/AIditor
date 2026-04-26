<?php
declare(strict_types=1);

class AIditor_Plugin
{
    public const CLEANUP_CRON_HOOK = 'aiditor_cleanup_logs';

    public const SCHEMA_OPTION_KEY = 'aiditor_schema_version';

    public const SCHEMA_VERSION = '20260426-aiditor-reset';

    protected AIditor_Settings $settings;

    protected AIditor_Run_Item_Repository $run_items;

    protected AIditor_Run_Repository $runs;

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

    protected AIditor_Admin_Page $admin_page;

    protected AIditor_REST_Controller $rest_controller;

    protected bool $hooks_registered = false;

    public function __construct()
    {
        $this->settings         = new AIditor_Settings();
        $this->run_items        = new AIditor_Run_Item_Repository();
        $this->runs             = new AIditor_Run_Repository($this->run_items);
        $this->templates        = new AIditor_Template_Repository();
        $this->article_styles   = new AIditor_Article_Style_Repository();
        $this->adapter_registry = new AIditor_Source_Adapter_Registry();
        $this->normalizer       = new AIditor_Content_Normalizer();
        $this->deduper          = new AIditor_Deduper();
        $this->researcher       = new AIditor_Source_Researcher();
        $this->page_fetcher     = new AIditor_Page_Fetcher();
        $this->taxonomy_browser = new AIditor_Taxonomy_Browser();
        $this->rewriter         = new AIditor_AI_Rewriter($this->settings, $this->article_styles);
        $this->ai_extractor     = new AIditor_AI_Extractor($this->settings, $this->rewriter);
        $this->draft_writer     = new AIditor_Draft_Writer($this->settings);
        $this->queue_worker     = new AIditor_Queue_Worker(
            $this->runs,
            $this->run_items,
            $this->templates,
            $this->adapter_registry,
            $this->normalizer,
            $this->deduper,
            $this->settings,
            $this->page_fetcher,
            $this->rewriter,
            $this->ai_extractor,
            $this->draft_writer
        );
        $this->admin_page       = new AIditor_Admin_Page($this->settings, $this->runs);
        $this->rest_controller  = new AIditor_REST_Controller(
            $this->settings,
            $this->runs,
            $this->run_items,
            $this->templates,
            $this->article_styles,
            $this->adapter_registry,
            $this->normalizer,
            $this->deduper,
            $this->researcher,
            $this->page_fetcher,
            $this->taxonomy_browser,
            $this->rewriter,
            $this->ai_extractor,
            $this->draft_writer,
            $this->queue_worker
        );
    }

    public function register_hooks(): void
    {
        if ($this->hooks_registered || ! function_exists('add_action')) {
            return;
        }

        $this->maybe_upgrade_schema();
        $this->article_styles->maybe_initialize_defaults();

        add_action('admin_menu', array($this->admin_page, 'register_page'));
        add_action('admin_enqueue_scripts', array($this->admin_page, 'enqueue_assets'));
        add_action('rest_api_init', array($this->rest_controller, 'register_routes'));
        add_action(self::CLEANUP_CRON_HOOK, array($this, 'cleanup_logs'));
        $this->queue_worker->register_hooks();
        $this->ensure_cleanup_schedule();

        $this->hooks_registered = true;
    }

    public function maybe_upgrade_schema(): void
    {
        if (! function_exists('get_option') || ! function_exists('update_option')) {
            return;
        }

        if ((string) get_option(self::SCHEMA_OPTION_KEY, '') === self::SCHEMA_VERSION) {
            return;
        }

        $this->run_items->maybe_initialize_defaults();
        $this->runs->maybe_initialize_defaults();
        $this->article_styles->maybe_initialize_defaults();
        update_option(self::SCHEMA_OPTION_KEY, self::SCHEMA_VERSION, false);
    }


    public function ensure_cleanup_schedule(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }

        $settings = $this->settings->get();
        if ((int) ($settings['log_retention_days'] ?? 30) <= 0) {
            return;
        }

        if (false === wp_next_scheduled(self::CLEANUP_CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_CRON_HOOK);
        }
    }

    public function cleanup_logs(): array
    {
        $settings = $this->settings->get();
        $retention_days = (int) ($settings['log_retention_days'] ?? 30);

        $runtime_options_deleted = $this->queue_worker->cleanup_runtime_options();

        if ($retention_days <= 0) {
            return array(
                'runs_deleted'             => 0,
                'items_deleted'            => 0,
                'runtime_options_deleted'  => $runtime_options_deleted,
            );
        }

        $result = $this->runs->cleanup_terminal_runs($retention_days, 100);
        $result['runtime_options_deleted'] = $runtime_options_deleted;

        return $result;
    }


    public static function activate(): void
    {
        $settings = new AIditor_Settings();
        $settings->maybe_initialize_defaults();

        $article_styles = new AIditor_Article_Style_Repository();
        $article_styles->maybe_initialize_defaults();

        $run_items = new AIditor_Run_Item_Repository();
        $run_items->maybe_initialize_defaults();

        $runs = new AIditor_Run_Repository($run_items);
        $runs->maybe_initialize_defaults();

        if (function_exists('update_option')) {
            update_option(self::SCHEMA_OPTION_KEY, self::SCHEMA_VERSION, false);
        }

        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event') && false === wp_next_scheduled(self::CLEANUP_CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_CRON_HOOK);
        }
    }
}
