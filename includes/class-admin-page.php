<?php
declare(strict_types=1);

class AIditor_Admin_Page
{
    protected AIditor_Settings $settings;

    protected AIditor_Run_Repository $runs;

    /**
     * @var array<string, string>
     */
    protected array $hook_suffixes = array();

    public function __construct(
        AIditor_Settings $settings,
        AIditor_Run_Repository $runs
    ) {
        $this->settings = $settings;
        $this->runs     = $runs;
    }

    public function register_page(): void
    {
        $parent_slug = 'aiditor';

        $this->hook_suffixes['collection'] = (string) add_menu_page(
            __('AIditor', 'aiditor'),
            __('AIditor', 'aiditor'),
            'manage_options',
            $parent_slug,
            array($this, 'render_collection_page'),
            'dashicons-edit-page',
            58
        );

        $this->hook_suffixes['collection_submenu'] = (string) add_submenu_page(
            $parent_slug,
            __('AI采集', 'aiditor'),
            __('AI采集', 'aiditor'),
            'manage_options',
            $parent_slug,
            array($this, 'render_collection_page')
        );

        $this->hook_suffixes['editing'] = (string) add_submenu_page(
            $parent_slug,
            __('AI采编', 'aiditor'),
            __('AI采编', 'aiditor'),
            'manage_options',
            'aiditor-editing',
            array($this, 'render_editing_page')
        );

        $this->hook_suffixes['settings'] = (string) add_submenu_page(
            $parent_slug,
            __('设置', 'aiditor'),
            __('设置', 'aiditor'),
            'manage_options',
            'aiditor-settings',
            array($this, 'render_settings_page')
        );
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (! in_array($hook_suffix, $this->hook_suffixes, true)) {
            return;
        }

        wp_enqueue_style(
            'aiditor-admin',
            AIDITOR_URL . 'assets/admin.css',
            array(),
            AIDITOR_VERSION
        );

        wp_enqueue_script(
            'aiditor-admin',
            AIDITOR_URL . 'assets/admin.js',
            array(),
            AIDITOR_VERSION,
            true
        );

        wp_localize_script(
            'aiditor-admin',
            'aiditorContentIngest',
            array(
                'restUrl'     => esc_url_raw(rest_url('aiditor/v1/')),
                'nonce'       => wp_create_nonce('wp_rest'),
                'settings'    => $this->settings->get_public_settings(),
                'runs'        => $this->runs->list_runs(100),
                'currentPage' => $this->get_current_page_key(),
            )
        );
    }

    protected function get_current_page_key(): string
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : 'aiditor';

        if ('aiditor-editing' === $page) {
            return 'editing';
        }

        if ('aiditor-settings' === $page) {
            return 'settings';
        }

        return 'collection';
    }

    protected function render_page_header(string $title, string $description): void
    {
        ?>
        <h1><?php echo esc_html($title); ?></h1>
        <p class="description"><?php echo esc_html($description); ?></p>
        <?php
    }

    public function render_collection_page(): void
    {
        ?>
        <div class="wrap aiditor-admin">
            <?php $this->render_page_header(__('AIditor', 'aiditor'), __('1. 先在“通用采集模板”中填写列表页 URL 并保存模板；2. 再到“导入”中选择模板、模型和目录后创建队列任务；3. 最后在任务列表与任务详情中查看进度和结果。', 'aiditor')); ?>

            <nav class="nav-tab-wrapper aiditor-tabs" aria-label="<?php echo esc_attr__('内容采集标签页', 'aiditor'); ?>">
                <button type="button" class="nav-tab nav-tab-active" data-tab-target="import"><?php echo esc_html__('导入', 'aiditor'); ?></button>
                <button type="button" class="nav-tab" data-tab-target="generic"><?php echo esc_html__('通用采集模板', 'aiditor'); ?></button>
                <button type="button" class="nav-tab" data-tab-target="settings"><?php echo esc_html__('设置', 'aiditor'); ?></button>
                <button type="button" class="nav-tab" data-tab-target="guide"><?php echo esc_html__('使用方法', 'aiditor'); ?></button>
            </nav>

            <section class="aiditor-panel is-active" data-tab-panel="import">
                <div class="aiditor-console-shell">
                    <aside class="aiditor-console-sidebar" aria-label="<?php echo esc_attr__('采集控制台侧栏', 'aiditor'); ?>">
                        <details id="aiditor-import-drawer" class="aiditor-card aiditor-compact-card" open>
                            <summary>
                                <span><?php echo esc_html__('模板队列任务', 'aiditor'); ?></span>
                                <small><?php echo esc_html__('选择模板、来源、数量、目录', 'aiditor'); ?></small>
                            </summary>
                            <form id="aiditor-import-form" class="aiditor-compact-form">
                                <label for="aiditor-template-select"><?php echo esc_html__('采集模板', 'aiditor'); ?></label>
                                <select id="aiditor-template-select" name="template_id" required></select>

                                <label for="aiditor-run-model-profile"><?php echo esc_html__('AI 模型', 'aiditor'); ?></label>
                                <select id="aiditor-run-model-profile" name="model_profile_id" required></select>

                                <label for="aiditor-source-url"><?php echo esc_html__('来源 URL', 'aiditor'); ?></label>
                                <input id="aiditor-source-url" name="source_url" type="url" class="regular-text code" placeholder="https://example.com/list-or-detail" required />

                                <div class="aiditor-grid aiditor-grid-tight">
                                    <div>
                                        <label for="aiditor-limit"><?php echo esc_html__('采集数量', 'aiditor'); ?></label>
                                        <input id="aiditor-limit" name="limit" type="number" min="1" max="2000" value="20" required />
                                    </div>
                                    <div>
                                        <label for="aiditor-post-type"><?php echo esc_html__('文章类型', 'aiditor'); ?></label>
                                        <select id="aiditor-post-type" name="post_type"></select>
                                    </div>
                                </div>

                                <label for="aiditor-target-taxonomy"><?php echo esc_html__('目标分类法', 'aiditor'); ?></label>
                                <select id="aiditor-target-taxonomy" name="target_taxonomy"></select>

                                <div class="aiditor-stack">
                                    <label><?php echo esc_html__('目标目录', 'aiditor'); ?></label>
                                    <div id="aiditor-term-levels" class="aiditor-term-levels"></div>
                                </div>

                                <details class="aiditor-mini-disclosure">
                                    <summary><?php echo esc_html__('附加分类法项', 'aiditor'); ?></summary>
                                    <div id="aiditor-extra-taxonomy-fields"></div>
                                    <p class="description"><?php echo esc_html__('可选。可在这里附加标签等非层级分类项。', 'aiditor'); ?></p>
                                </details>

                                <div class="aiditor-actions aiditor-actions-sticky">
                                    <button type="button" class="button button-secondary" id="aiditor-preview-button"><?php echo esc_html__('预览', 'aiditor'); ?></button>
                                    <button type="button" class="button button-primary" id="aiditor-run-button"><?php echo esc_html__('创建队列任务', 'aiditor'); ?></button>
                                </div>
                            </form>
                            <div id="aiditor-import-notice" class="aiditor-notice" aria-live="polite"></div>
                        </details>

                        <details id="aiditor-preview-drawer" class="aiditor-card aiditor-compact-card">
                            <summary>
                                <span><?php echo esc_html__('预览结果', 'aiditor'); ?></span>
                                <small><?php echo esc_html__('默认收起，按需查看', 'aiditor'); ?></small>
                            </summary>
                            <div id="aiditor-preview-results" class="aiditor-results aiditor-scroll-region aiditor-preview-region">
                                <p class="description"><?php echo esc_html__('请先执行预览，确认候选内容与去重状态后再开始导入。', 'aiditor'); ?></p>
                            </div>
                        </details>

                        <div class="aiditor-card aiditor-compact-card aiditor-task-list-card">
                            <div class="aiditor-card-heading-row">
                                <div>
                                    <h2><?php echo esc_html__('任务列表', 'aiditor'); ?></h2>
                                    <p class="description"><?php echo esc_html__('显示最近 100 个任务；终态任务可安全删除，日志保留天数可在设置中控制。', 'aiditor'); ?></p>
                                </div>
                                <button type="button" class="button button-small" id="aiditor-refresh-runs"><?php echo esc_html__('刷新', 'aiditor'); ?></button>
                            </div>
                            <div id="aiditor-run-results" class="aiditor-results aiditor-scroll-region aiditor-run-list-region">
                                <p class="description"><?php echo esc_html__('这里会显示队列任务、执行进度以及可用操作。', 'aiditor'); ?></p>
                            </div>
                        </div>
                    </aside>

                    <main class="aiditor-console-main">
                        <div class="aiditor-card aiditor-task-detail-card">
                            <div class="aiditor-card-heading-row">
                                <div>
                                    <h2><?php echo esc_html__('任务详情', 'aiditor'); ?></h2>
                                    <p class="description"><?php echo esc_html__('主工作区只保留当前任务的进度、调度、失败条目和最近明细。', 'aiditor'); ?></p>
                                </div>
                            </div>
                            <div id="aiditor-run-detail" class="aiditor-run-detail">
                                <p class="description"><?php echo esc_html__('选择一个任务后，这里会显示批处理进度、调度状态以及最近条目明细。', 'aiditor'); ?></p>
                            </div>
                        </div>
                    </main>
                </div>
            </section>

            <section class="aiditor-panel" data-tab-panel="generic" hidden>
                <div class="aiditor-generic-studio">
                    <div class="aiditor-card aiditor-studio-control">
                        <div class="aiditor-section-header">
                            <div>
                                <h2><?php echo esc_html__('创建通用采集模板', 'aiditor'); ?></h2>
                                <p class="description"><?php echo esc_html__('填写列表页 URL 后，插件会抓取页面、用 AI 发现详情页、抽取字段，并保存为可复用模板。', 'aiditor'); ?></p>
                            </div>
                        </div>

                        <form id="aiditor-generic-form" class="aiditor-compact-form aiditor-template-form">
                            <div class="aiditor-field">
                                <label for="aiditor-generic-url"><?php echo esc_html__('列表页 URL', 'aiditor'); ?></label>
                                <input id="aiditor-generic-url" name="url" type="url" class="regular-text code" placeholder="https://example.com/list" required />
                            </div>

                            <div class="aiditor-field">
                                <label for="aiditor-generic-template-name"><?php echo esc_html__('模板名称', 'aiditor'); ?></label>
                                <input id="aiditor-generic-template-name" name="name" type="text" placeholder="<?php echo esc_attr__('可选，例如：某站工具目录采集', 'aiditor'); ?>" />
                            </div>

                            <div class="aiditor-field">
                                <label for="aiditor-generic-requirement"><?php echo esc_html__('采集需求', 'aiditor'); ?></label>
                                <textarea id="aiditor-generic-requirement" name="requirement" rows="4" placeholder="<?php echo esc_attr__('例如：这是一个工具列表页。请发现每个工具的详情页；进入详情页后采集标题、官网网址、摘要和正文介绍。', 'aiditor'); ?>"></textarea>
                            </div>

                            <div class="aiditor-stepper" aria-label="<?php echo esc_attr__('采集模板创建步骤', 'aiditor'); ?>">
                                <div class="aiditor-stepper-item is-current">
                                    <span class="aiditor-step-number">1</span>
                                    <div class="aiditor-stepper-copy">
                                        <strong><?php echo esc_html__('分析列表页并发现详情页', 'aiditor'); ?></strong>
                                        <p class="description"><?php echo esc_html__('先抓取列表页，再让 AI 自动识别可采集的详情页 URL。', 'aiditor'); ?></p>
                                    </div>
                                    <button type="button" class="button button-primary" id="aiditor-generic-start-button"><?php echo esc_html__('开始分析', 'aiditor'); ?></button>
                                </div>
                                <div class="aiditor-stepper-item">
                                    <span class="aiditor-step-number">2</span>
                                    <div class="aiditor-stepper-copy">
                                        <strong><?php echo esc_html__('抽取首个详情页样例', 'aiditor'); ?></strong>
                                        <p class="description"><?php echo esc_html__('用第一个详情页验证标题、网址、摘要、正文等字段是否正确。', 'aiditor'); ?></p>
                                    </div>
                                    <button type="button" class="button" id="aiditor-generic-extract-button"><?php echo esc_html__('抽取样例字段', 'aiditor'); ?></button>
                                </div>
                                <div class="aiditor-stepper-item aiditor-stepper-item-rewrite">
                                    <span class="aiditor-step-number">3</span>
                                    <div class="aiditor-stepper-copy">
                                        <strong><?php echo esc_html__('选择需要 AI 重写的字段', 'aiditor'); ?></strong>
                                        <p class="description"><?php echo esc_html__('勾选要润色的文本字段；网址、图片、日期等保持原值。', 'aiditor'); ?></p>
                                        <div id="aiditor-generic-rewrite-fields" class="aiditor-rewrite-fields"></div>
                                    </div>
                                </div>
                                <div class="aiditor-stepper-item">
                                    <span class="aiditor-step-number">4</span>
                                    <div class="aiditor-stepper-copy">
                                        <strong><?php echo esc_html__('保存为队列模板', 'aiditor'); ?></strong>
                                        <p class="description"><?php echo esc_html__('确认右侧结果后保存，之后可在“导入”页反复创建队列任务。', 'aiditor'); ?></p>
                                    </div>
                                    <button type="button" class="button button-primary" id="aiditor-generic-save-button"><?php echo esc_html__('保存模板', 'aiditor'); ?></button>
                                </div>
                            </div>

                            <details class="aiditor-mini-disclosure">
                                <summary><?php echo esc_html__('高级字段设置', 'aiditor'); ?></summary>
                                <div class="aiditor-field">
                                    <label for="aiditor-generic-fields"><?php echo esc_html__('目标字段 JSON', 'aiditor'); ?></label>
                                    <textarea id="aiditor-generic-fields" name="field_schema" rows="8" class="code">[
  {"key":"title","label":"标题","type":"text","required":true},
  {"key":"summary","label":"摘要或描述","type":"textarea","required":false},
  {"key":"url","label":"主要网址","type":"url","required":false},
  {"key":"cover_image_url","label":"封面图","type":"image","required":false,"description":"文章或详情页的主图、封面图、特色图远程 URL。"},
  {"key":"content","label":"正文","type":"html","required":false,"description":"只采集正文主体，不要包含导航、栏目、发布时间、阅读量、推荐阅读、评论区或页脚。"}
]</textarea>
                                </div>
                                <p class="description"><?php echo esc_html__('默认字段已经覆盖标题、摘要、网址和正文；只有需要采集价格、作者、发布日期等额外字段时才需要修改。', 'aiditor'); ?></p>
                            </details>

                            <input id="aiditor-generic-mode" name="source_mode" type="hidden" value="list" />

                            <details class="aiditor-mini-disclosure aiditor-debug-actions">
                                <summary><?php echo esc_html__('高级调试操作', 'aiditor'); ?></summary>
                                <p class="description"><?php echo esc_html__('正常使用不需要点击这些按钮。只有排查页面抓取或详情页识别问题时使用。', 'aiditor'); ?></p>
                                <div class="aiditor-actions">
                                    <button type="button" class="button" id="aiditor-generic-fetch-button"><?php echo esc_html__('仅抓取页面', 'aiditor'); ?></button>
                                    <button type="button" class="button" id="aiditor-generic-discover-button"><?php echo esc_html__('仅发现详情页', 'aiditor'); ?></button>
                                </div>
                            </details>
                        </form>
                    </div>

                    <div class="aiditor-card aiditor-studio-preview">
                        <div class="aiditor-section-header aiditor-section-header-compact">
                            <div>
                                <h2><?php echo esc_html__('分析过程', 'aiditor'); ?></h2>
                                <p class="description"><?php echo esc_html__('每一步的页面内容、详情页 URL、抽取字段都会在这里可视化展示。', 'aiditor'); ?></p>
                            </div>
                            <div id="aiditor-generic-notice" class="aiditor-notice" aria-live="polite"></div>
                        </div>
                        <div class="aiditor-process-tabs" role="tablist" aria-label="<?php echo esc_attr__('采集分析过程', 'aiditor'); ?>">
                            <button type="button" class="button button-small is-active" data-generic-result-tab="page"><?php echo esc_html__('页面内容', 'aiditor'); ?></button>
                            <button type="button" class="button button-small" data-generic-result-tab="urls"><?php echo esc_html__('详情页 URL', 'aiditor'); ?></button>
                            <button type="button" class="button button-small" data-generic-result-tab="fields"><?php echo esc_html__('抽取字段', 'aiditor'); ?></button>
                            <button type="button" class="button button-small" data-generic-result-tab="raw"><?php echo esc_html__('原始 JSON', 'aiditor'); ?></button>
                        </div>
                        <div id="aiditor-generic-result" class="aiditor-generic-result"></div>
                    </div>
                </div>

                <div class="aiditor-card aiditor-template-library">
                    <div class="aiditor-card-heading-row">
                        <div>
                            <h2><?php echo esc_html__('已保存模板', 'aiditor'); ?></h2>
                            <p class="description"><?php echo esc_html__('模板保存后可作为后续队列任务的采集配置。', 'aiditor'); ?></p>
                        </div>
                        <button type="button" class="button button-small" id="aiditor-refresh-templates"><?php echo esc_html__('刷新模板', 'aiditor'); ?></button>
                    </div>
                    <div id="aiditor-template-list" class="aiditor-template-list">
                        <p class="description"><?php echo esc_html__('暂无模板。', 'aiditor'); ?></p>
                    </div>
                </div>
            </section>

            <section class="aiditor-panel" data-tab-panel="settings" hidden>
                <div class="aiditor-card aiditor-settings-shell">
                    <div class="aiditor-section-header aiditor-settings-header">
                        <div>
                            <h2><?php echo esc_html__('插件设置', 'aiditor'); ?></h2>
                            <p class="description"><?php echo esc_html__('如需调整默认文章状态、并发数、轮询间隔，可在“AI采集”中的队列设置里配置；文章风格在左侧“设置”子菜单中维护。', 'aiditor'); ?></p>
                        </div>
                        <div class="aiditor-subtabs" aria-label="<?php echo esc_attr__('设置分类', 'aiditor'); ?>">
                            <button type="button" class="button button-small is-active" data-settings-tab="queue"><?php echo esc_html__('队列设置', 'aiditor'); ?></button>
                        </div>
                    </div>

                    <form id="aiditor-settings-form" class="aiditor-settings-form">
                        <input id="aiditor-model-profiles-json" name="model_profiles" type="hidden" />

                        <div class="aiditor-settings-pane is-active" data-settings-panel="queue">
                            <div class="aiditor-setting-block">
                                <h3><?php echo esc_html__('worker 执行策略', 'aiditor'); ?></h3>
                                <div class="aiditor-form-grid aiditor-form-grid-four">
                                    <div class="aiditor-field"><label for="aiditor-queue-batch-size"><?php echo esc_html__('单轮处理条数', 'aiditor'); ?></label><input id="aiditor-queue-batch-size" name="queue_batch_size" type="number" min="1" max="50" step="1" required /><p class="description"><?php echo esc_html__('单个 worker 一次最多处理的条目数。', 'aiditor'); ?></p></div>
                                    <div class="aiditor-field"><label for="aiditor-queue-time-limit"><?php echo esc_html__('单轮执行秒数', 'aiditor'); ?></label><input id="aiditor-queue-time-limit" name="queue_time_limit" type="number" min="5" max="180" step="1" required /></div>
                                    <div class="aiditor-field"><label for="aiditor-queue-concurrency"><?php echo esc_html__('并发处理数', 'aiditor'); ?></label><input id="aiditor-queue-concurrency" name="queue_concurrency" type="number" min="1" max="20" step="1" required /></div>
                                    <div class="aiditor-field"><label for="aiditor-queue-poll-interval"><?php echo esc_html__('后台轮询间隔', 'aiditor'); ?></label><input id="aiditor-queue-poll-interval" name="queue_poll_interval" type="number" min="2" max="30" step="1" required /></div>
                                </div>
                            </div>
                            <div class="aiditor-setting-block">
                                <h3><?php echo esc_html__('入库默认值', 'aiditor'); ?></h3>
                                <div class="aiditor-form-grid aiditor-form-grid-three">
                                    <div class="aiditor-field"><label for="aiditor-log-retention-days"><?php echo esc_html__('采集日志保留天数', 'aiditor'); ?></label><input id="aiditor-log-retention-days" name="log_retention_days" type="number" min="0" max="365" step="1" required /></div>
                                    <div class="aiditor-field"><label for="aiditor-default-status"><?php echo esc_html__('默认文章状态', 'aiditor'); ?></label><select id="aiditor-default-status" name="default_post_status"><option value="draft"><?php echo esc_html__('草稿', 'aiditor'); ?></option><option value="pending"><?php echo esc_html__('待审核', 'aiditor'); ?></option><option value="private"><?php echo esc_html__('私密', 'aiditor'); ?></option><option value="publish"><?php echo esc_html__('已发布', 'aiditor'); ?></option></select></div>
                                    <div class="aiditor-field"><label for="aiditor-default-style"><?php echo esc_html__('默认文章风格', 'aiditor'); ?></label><select id="aiditor-default-style" name="default_article_style"></select><p class="description"><?php echo esc_html__('选项来自左侧“设置”子菜单中的“文章风格”标签页。', 'aiditor'); ?></p></div>
                                </div>
                            </div>
                        </div>

                        <div class="aiditor-actions aiditor-settings-save-row">
                            <button type="submit" class="button button-primary"><?php echo esc_html__('保存设置', 'aiditor'); ?></button>
                        </div>
                    </form>
                    <div id="aiditor-collection-settings-notice" class="aiditor-notice" aria-live="polite"></div>
                </div>
            </section>

            <section class="aiditor-panel" data-tab-panel="guide" hidden>
                <div class="aiditor-card aiditor-settings-shell aiditor-guide-shell">
                    <div class="aiditor-section-header aiditor-settings-header">
                        <div>
                            <h2><?php echo esc_html__('使用方法', 'aiditor'); ?></h2>
                            <p class="description"><?php echo esc_html__('按下面顺序操作即可完成一次完整采集。首次建议先用 5 到 20 条做小批量测试。', 'aiditor'); ?></p>
                        </div>
                        <span class="aiditor-pill is-muted"><?php echo esc_html__('按顺序操作', 'aiditor'); ?></span>
                    </div>

                    <div class="aiditor-guide-layout">
                        <div class="aiditor-setting-block">
                            <h3><?php echo esc_html__('开始前', 'aiditor'); ?></h3>
                            <ol class="aiditor-guide-list">
                                <li><?php echo esc_html__('先到“设置”中的“AI 设置”里新增至少一个可用模型，并点击“保存模型配置”。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('如需调整默认文章状态、并发数和轮询间隔，可在“AI采集”的队列设置里配置；如需维护文章风格，可到左侧“设置”子菜单的“文章风格”标签页。', 'aiditor'); ?></li>
                            </ol>
                        </div>

                        <div class="aiditor-setting-block">
                            <h3><?php echo esc_html__('第 1 步：创建采集模板', 'aiditor'); ?></h3>
                            <ol class="aiditor-guide-list">
                                <li><?php echo esc_html__('打开“通用采集模板”，填写列表页 URL、模板名称和采集需求。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('点击“开始分析”，确认右侧“页面内容”和“详情页 URL”是否正确。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('点击“抽取样例字段”，检查标题、摘要、网址、正文是否抽取正确。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('按需勾选需要 AI 重写的字段，再点击“保存模板”。', 'aiditor'); ?></li>
                            </ol>
                        </div>

                        <div class="aiditor-setting-block">
                            <h3><?php echo esc_html__('第 2 步：创建队列任务', 'aiditor'); ?></h3>
                            <ol class="aiditor-guide-list">
                                <li><?php echo esc_html__('回到“导入”，选择采集模板、AI 模型、来源 URL、采集数量、文章类型、目标分类法和目录。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('如果还需要附加标签等非层级分类项，可在“附加分类法项”中补充。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('建议先点击“预览”，确认候选内容和去重状态，再点击“创建队列任务”。', 'aiditor'); ?></li>
                            </ol>
                        </div>

                        <div class="aiditor-setting-block">
                            <h3><?php echo esc_html__('第 3 步：查看进度与结果', 'aiditor'); ?></h3>
                            <ol class="aiditor-guide-list">
                                <li><?php echo esc_html__('任务创建后，会出现在左侧“任务列表”中。点击某个任务，可在右侧查看详细进度。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('“任务详情”里可查看批处理进度、调度状态、失败条目列表和最近条目明细。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('已成功入库的文章会按你的文章状态写入对应文章类型和分类法。', 'aiditor'); ?></li>
                            </ol>
                        </div>

                        <div class="aiditor-setting-block aiditor-guide-span-full">
                            <h3><?php echo esc_html__('常见操作', 'aiditor'); ?></h3>
                            <ol class="aiditor-guide-list">
                                <li><?php echo esc_html__('任务执行中可使用“立即处理”“暂停”“取消”；终态任务可“删除记录”。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('若有失败条目，可在任务卡片或任务详情中点击“重新采集失败条目”。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('如果某个网站的抽取结果不理想，先回到“通用采集模板”调整采集需求、字段定义或重写字段，再重新保存模板。', 'aiditor'); ?></li>
                                <li><?php echo esc_html__('如果只想验证流程是否正确，先用少量条目测试通过，再放大采集数量。', 'aiditor'); ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <?php
    }

    public function render_editing_page(): void
    {
        $default_extract_instruction = '请提取标题、发布时间、关键词、摘要、正文与作者。正文只保留主体内容。';
        $default_rewrite_instruction = '请在保留事实准确的前提下，用更流畅、更适合中文资讯文章的表达方式重写。';
        $default_creation_prompt      = '请围绕一个明确主题创作一篇适合中文网站发布的原创文章，要求标题明确、结构完整、信息准确、语言自然。';
        $default_creation_style       = '语言自然专业，面向中文互联网读者，避免空泛套话，段落结构清晰。';
        ?>
        <div class="wrap aiditor-admin">
            <?php $this->render_page_header(__('AIditor · AI采编', 'aiditor'), __('在同一工作台中完成 AI采编 与 AI创作；前者用于详情页抽取与重写，后者用于根据提示词直接创作并发布草稿。', 'aiditor')); ?>

            <nav class="nav-tab-wrapper aiditor-tabs" aria-label="<?php echo esc_attr__('AI采编工作台标签页', 'aiditor'); ?>">
                <button type="button" class="nav-tab nav-tab-active" data-tab-target="editing"><?php echo esc_html__('AI采编', 'aiditor'); ?></button>
                <button type="button" class="nav-tab" data-tab-target="creation"><?php echo esc_html__('AI创作', 'aiditor'); ?></button>
            </nav>

            <section class="aiditor-panel is-active" data-tab-panel="editing">
                <div class="aiditor-card aiditor-settings-shell">
                    <div class="aiditor-section-header aiditor-settings-header">
                        <div>
                            <h2><?php echo esc_html__('AI采编工作台', 'aiditor'); ?></h2>
                            <p class="description"><?php echo esc_html__('先选择模型并抓取详情页，再重写所选字段，最后配置发布目标与字段映射。', 'aiditor'); ?></p>
                        </div>
                        <span class="aiditor-pill"><?php echo esc_html__('MVP', 'aiditor'); ?></span>
                    </div>

                    <form id="aiditor-editing-form" class="aiditor-settings-form">
                        <div class="aiditor-setting-block">
                            <div class="aiditor-form-grid aiditor-form-grid-two">
                                <div class="aiditor-field aiditor-field-wide">
                                    <label for="aiditor-editing-url"><?php echo esc_html__('详情页 URL', 'aiditor'); ?></label>
                                    <input id="aiditor-editing-url" type="url" class="regular-text code" placeholder="https://example.com/article-detail" />
                                </div>
                                <div class="aiditor-field">
                                    <label for="aiditor-editing-model-profile"><?php echo esc_html__('AI 模型', 'aiditor'); ?></label>
                                    <select id="aiditor-editing-model-profile"></select>
                                </div>
                                <div class="aiditor-field">
                                    <label for="aiditor-editing-style-preset"><?php echo esc_html__('文章风格', 'aiditor'); ?></label>
                                    <select id="aiditor-editing-style-preset">
                                        <option value=""><?php echo esc_html__('默认风格', 'aiditor'); ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="aiditor-form-grid aiditor-form-grid-two">
                                <div class="aiditor-field">
                                    <label for="aiditor-editing-extract-instruction"><?php echo esc_html__('抽取说明', 'aiditor'); ?></label>
                                    <textarea id="aiditor-editing-extract-instruction" rows="4" placeholder="<?php echo esc_attr__('例如：请提取标题、发布时间、关键词、摘要、正文与作者。正文只保留主体内容。', 'aiditor'); ?>"><?php echo esc_textarea($default_extract_instruction); ?></textarea>
                                </div>
                                <div class="aiditor-field">
                                    <label for="aiditor-editing-rewrite-instruction"><?php echo esc_html__('重写说明', 'aiditor'); ?></label>
                                    <textarea id="aiditor-editing-rewrite-instruction" rows="4" placeholder="<?php echo esc_attr__('例如：请在保留事实准确的前提下，用更流畅、更适合中文资讯文章的表达方式重写。', 'aiditor'); ?>"><?php echo esc_textarea($default_rewrite_instruction); ?></textarea>
                                </div>
                            </div>

                            <div class="aiditor-actions aiditor-editing-workflow-actions">
                                <button type="button" class="button button-primary" id="aiditor-editing-extract-button"><?php echo esc_html__('开始采集', 'aiditor'); ?></button>
                                <span class="aiditor-editing-workflow-step"><?php echo esc_html__('→ 勾选重写项 →', 'aiditor'); ?></span>
                                <button type="button" class="button button-primary" id="aiditor-editing-rewrite-button"><?php echo esc_html__('开始重写', 'aiditor'); ?></button>
                                <span class="aiditor-editing-workflow-step"><?php echo esc_html__('→ 勾选发布项', 'aiditor'); ?></span>
                            </div>
                            <div id="aiditor-editing-notice" class="aiditor-notice" aria-live="polite"></div>
                        </div>
                    </form>
                </div>

                <div class="aiditor-editing-shell">
                    <section class="aiditor-card aiditor-editing-column">
                        <div class="aiditor-card-heading-row">
                            <div>
                                <h2><?php echo esc_html__('采集内容', 'aiditor'); ?></h2>
                                <p class="description"><?php echo esc_html__('勾选左侧字段后，这些内容会参与 AI 重写。', 'aiditor'); ?></p>
                            </div>
                        </div>
                        <div id="aiditor-editing-source-fields" class="aiditor-editing-field-list">
                            <p class="description"><?php echo esc_html__('采集完成后，这里会显示标题、发布时间、关键词、摘要、正文等字段。', 'aiditor'); ?></p>
                        </div>
                    </section>

                    <section class="aiditor-card aiditor-editing-column">
                        <div class="aiditor-card-heading-row">
                            <div>
                                <h2><?php echo esc_html__('重写结果', 'aiditor'); ?></h2>
                                <p class="description"><?php echo esc_html__('勾选右侧字段后，这些内容会参与发布。未勾选的字段不会写入文章。', 'aiditor'); ?></p>
                            </div>
                        </div>
                        <div id="aiditor-editing-rewritten-fields" class="aiditor-editing-field-list">
                            <p class="description"><?php echo esc_html__('完成重写后，这里会显示 AI 输出的字段结果。', 'aiditor'); ?></p>
                        </div>
                    </section>
                </div>

                <div class="aiditor-card aiditor-settings-shell aiditor-editing-publish-shell">
                    <div class="aiditor-section-header aiditor-settings-header">
                        <div>
                            <h2><?php echo esc_html__('发布设置', 'aiditor'); ?></h2>
                            <p class="description"><?php echo esc_html__('选择文章类型、分类与状态，并配置每个字段发布到哪个 WordPress 字段或分类法。', 'aiditor'); ?></p>
                        </div>
                    </div>

                    <form id="aiditor-editing-publish-form" class="aiditor-settings-form">
                        <div class="aiditor-setting-block">
                            <div class="aiditor-form-grid aiditor-form-grid-three">
                                <div class="aiditor-field">
                                    <label for="aiditor-editing-post-type"><?php echo esc_html__('文章类型', 'aiditor'); ?></label>
                                    <select id="aiditor-editing-post-type" name="post_type"></select>
                                </div>
                                <div class="aiditor-field">
                                    <label for="aiditor-editing-target-taxonomy"><?php echo esc_html__('主分类法', 'aiditor'); ?></label>
                                    <select id="aiditor-editing-target-taxonomy" name="target_taxonomy"></select>
                                </div>
                                <div class="aiditor-field">
                                    <label for="aiditor-editing-post-status"><?php echo esc_html__('发布状态', 'aiditor'); ?></label>
                                    <select id="aiditor-editing-post-status" name="post_status">
                                        <option value="draft"><?php echo esc_html__('草稿', 'aiditor'); ?></option>
                                        <option value="pending"><?php echo esc_html__('待审核', 'aiditor'); ?></option>
                                        <option value="private"><?php echo esc_html__('私密', 'aiditor'); ?></option>
                                        <option value="publish"><?php echo esc_html__('已发布', 'aiditor'); ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="aiditor-stack">
                                <label><?php echo esc_html__('主分类目录', 'aiditor'); ?></label>
                                <div id="aiditor-editing-term-levels" class="aiditor-term-levels"></div>
                            </div>

                            <details class="aiditor-mini-disclosure">
                                <summary><?php echo esc_html__('附加分类法项', 'aiditor'); ?></summary>
                                <div id="aiditor-editing-extra-taxonomy-fields"></div>
                                <p class="description"><?php echo esc_html__('可选。可在这里附加标签等非层级分类项。', 'aiditor'); ?></p>
                            </details>
                        </div>

                        <div class="aiditor-setting-block">
                            <div class="aiditor-card-heading-row">
                                <div>
                                    <h3><?php echo esc_html__('字段映射', 'aiditor'); ?></h3>
                                    <p class="description"><?php echo esc_html__('将采集/重写字段映射到文章标题、摘要、正文、Meta 或分类法。', 'aiditor'); ?></p>
                                </div>
                            </div>
                            <div id="aiditor-editing-field-mapping" class="aiditor-editing-mapping-list">
                                <p class="description"><?php echo esc_html__('采集完成后，这里会自动生成字段映射表。', 'aiditor'); ?></p>
                            </div>
                        </div>

                        <div class="aiditor-actions aiditor-settings-save-row">
                            <button type="button" class="button button-primary" id="aiditor-editing-publish-button"><?php echo esc_html__('发布选中内容', 'aiditor'); ?></button>
                        </div>
                    </form>
                    <div id="aiditor-editing-publish-notice" class="aiditor-notice" aria-live="polite"></div>
                </div>
            </section>

            <section class="aiditor-panel" data-tab-panel="creation" hidden>
                <div class="aiditor-console-shell aiditor-creation-shell">
                    <aside class="aiditor-console-sidebar" aria-label="<?php echo esc_attr__('AI创作控制台侧栏', 'aiditor'); ?>">
                        <div class="aiditor-card aiditor-creation-sidebar-card">
                            <div class="aiditor-section-header aiditor-section-header-compact">
                                <div>
                                    <h2><?php echo esc_html__('AI创作', 'aiditor'); ?></h2>
                                    <p class="description"><?php echo esc_html__('选择模型与风格，输入创作说明后直接生成文章草稿。', 'aiditor'); ?></p>
                                </div>
                            </div>

                            <form id="aiditor-creation-form" class="aiditor-settings-form">
                                <div class="aiditor-field">
                                    <label for="aiditor-creation-model-profile"><?php echo esc_html__('AI 模型', 'aiditor'); ?></label>
                                    <select id="aiditor-creation-model-profile"></select>
                                </div>

                                <div class="aiditor-field">
                                    <label for="aiditor-creation-prompt"><?php echo esc_html__('创作说明', 'aiditor'); ?></label>
                                    <textarea id="aiditor-creation-prompt" rows="7" placeholder="<?php echo esc_attr__('例如：围绕某个产品、行业趋势、教程主题创作一篇适合中文网站发布的文章。', 'aiditor'); ?>"><?php echo esc_textarea($default_creation_prompt); ?></textarea>
                                </div>

                                <div class="aiditor-field">
                                    <label for="aiditor-creation-style-preset"><?php echo esc_html__('预设风格', 'aiditor'); ?></label>
                                    <select id="aiditor-creation-style-preset">
                                        <option value=""><?php echo esc_html__('默认风格', 'aiditor'); ?></option>
                                    </select>
                                </div>

                                <div class="aiditor-field">
                                    <label for="aiditor-creation-style"><?php echo esc_html__('自定义风格', 'aiditor'); ?></label>
                                    <textarea id="aiditor-creation-style" rows="5" placeholder="<?php echo esc_attr__('例如：专业但不生硬，结构清楚，适当加入案例和可执行建议。', 'aiditor'); ?>"><?php echo esc_textarea($default_creation_style); ?></textarea>
                                </div>

                                <div class="aiditor-actions aiditor-actions-sticky">
                                    <button type="button" class="button button-primary" id="aiditor-creation-generate-button"><?php echo esc_html__('开始创作', 'aiditor'); ?></button>
                                </div>
                            </form>
                            <div id="aiditor-creation-notice" class="aiditor-notice" aria-live="polite"></div>
                        </div>
                    </aside>

                    <main class="aiditor-console-main">
                        <div class="aiditor-card aiditor-creation-preview-card">
                            <div class="aiditor-card-heading-row">
                                <div>
                                    <h2><?php echo esc_html__('创作结果', 'aiditor'); ?></h2>
                                    <p class="description"><?php echo esc_html__('支持预览标题、摘要、正文以及生成结果中包含的网络图片引用。', 'aiditor'); ?></p>
                                </div>
                            </div>
                            <div id="aiditor-creation-result" class="aiditor-creation-result">
                                <p class="description"><?php echo esc_html__('完成创作后，这里会显示标题、摘要、关键词、正文与图片。', 'aiditor'); ?></p>
                            </div>
                        </div>

                        <div class="aiditor-card aiditor-settings-shell aiditor-creation-publish-shell">
                            <div class="aiditor-section-header aiditor-settings-header">
                                <div>
                                    <h2><?php echo esc_html__('发布设置', 'aiditor'); ?></h2>
                                    <p class="description"><?php echo esc_html__('选择文章类型、分类与字段映射，发布时会尝试把正文中的远程图片转存到本地媒体库。', 'aiditor'); ?></p>
                                </div>
                            </div>

                            <form id="aiditor-creation-publish-form" class="aiditor-settings-form">
                                <div class="aiditor-setting-block">
                                    <div class="aiditor-form-grid aiditor-form-grid-three">
                                        <div class="aiditor-field">
                                            <label for="aiditor-creation-post-type"><?php echo esc_html__('文章类型', 'aiditor'); ?></label>
                                            <select id="aiditor-creation-post-type" name="post_type"></select>
                                        </div>
                                        <div class="aiditor-field">
                                            <label for="aiditor-creation-target-taxonomy"><?php echo esc_html__('主分类法', 'aiditor'); ?></label>
                                            <select id="aiditor-creation-target-taxonomy" name="target_taxonomy"></select>
                                        </div>
                                        <div class="aiditor-field">
                                            <label for="aiditor-creation-post-status"><?php echo esc_html__('发布状态', 'aiditor'); ?></label>
                                            <select id="aiditor-creation-post-status" name="post_status">
                                                <option value="draft"><?php echo esc_html__('草稿', 'aiditor'); ?></option>
                                                <option value="pending"><?php echo esc_html__('待审核', 'aiditor'); ?></option>
                                                <option value="private"><?php echo esc_html__('私密', 'aiditor'); ?></option>
                                                <option value="publish"><?php echo esc_html__('已发布', 'aiditor'); ?></option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="aiditor-stack">
                                        <label><?php echo esc_html__('主分类目录', 'aiditor'); ?></label>
                                        <div id="aiditor-creation-term-levels" class="aiditor-term-levels"></div>
                                    </div>

                                    <details class="aiditor-mini-disclosure">
                                        <summary><?php echo esc_html__('附加分类法项', 'aiditor'); ?></summary>
                                        <div id="aiditor-creation-extra-taxonomy-fields"></div>
                                        <p class="description"><?php echo esc_html__('可选。可在这里附加标签等非层级分类项。', 'aiditor'); ?></p>
                                    </details>
                                </div>

                                <div class="aiditor-setting-block">
                                    <div class="aiditor-card-heading-row">
                                        <div>
                                            <h3><?php echo esc_html__('字段映射', 'aiditor'); ?></h3>
                                            <p class="description"><?php echo esc_html__('将 AI 创作结果映射到文章标题、摘要、正文、Meta 或分类法。', 'aiditor'); ?></p>
                                        </div>
                                    </div>
                                    <div id="aiditor-creation-field-mapping" class="aiditor-editing-mapping-list">
                                        <p class="description"><?php echo esc_html__('完成创作后，这里会自动生成字段映射表。', 'aiditor'); ?></p>
                                    </div>
                                </div>

                                <div class="aiditor-actions aiditor-settings-save-row">
                                    <button type="button" class="button button-primary" id="aiditor-creation-publish-button"><?php echo esc_html__('发布创作内容', 'aiditor'); ?></button>
                                </div>
                            </form>
                            <div id="aiditor-creation-publish-notice" class="aiditor-notice" aria-live="polite"></div>
                        </div>
                    </main>
                </div>
            </section>
        </div>
        <?php
    }

    public function render_settings_page(): void
    {
        ?>
        <div class="wrap aiditor-admin">
            <?php $this->render_page_header(__('AIditor · 设置', 'aiditor'), __('集中管理 AI 模型连接配置与公共文章风格，供 AI采集、AI采编 和 AI创作共用。', 'aiditor')); ?>

            <nav class="nav-tab-wrapper aiditor-tabs" aria-label="<?php echo esc_attr__('设置标签页', 'aiditor'); ?>">
                <button type="button" class="nav-tab nav-tab-active" data-tab-target="ai-settings"><?php echo esc_html__('AI 设置', 'aiditor'); ?></button>
                <button type="button" class="nav-tab" data-tab-target="article-styles"><?php echo esc_html__('文章风格', 'aiditor'); ?></button>
            </nav>

            <section class="aiditor-panel is-active" data-tab-panel="ai-settings">
                <div class="aiditor-card aiditor-settings-shell">
                    <div class="aiditor-section-header aiditor-settings-header">
                        <div>
                            <h2><?php echo esc_html__('AI 设置', 'aiditor'); ?></h2>
                            <p class="description"><?php echo esc_html__('插件不会预置任何模型、接口地址或密钥；请在这里自行新增并保存多个 OpenAI 兼容模型。', 'aiditor'); ?></p>
                        </div>
                    </div>

                    <form id="aiditor-settings-form" class="aiditor-settings-form">
                    <input id="aiditor-model-profiles-json" name="model_profiles" type="hidden" />
                    <div class="aiditor-model-workbench">
                        <div class="aiditor-setting-block aiditor-model-form">
                            <h3><?php echo esc_html__('新增或编辑模型', 'aiditor'); ?></h3>
                            <p class="description"><?php echo esc_html__('插件不会预置任何模型、接口地址或密钥；请在这里自行新增并保存多个 OpenAI 兼容模型。', 'aiditor'); ?></p>
                            <input id="aiditor-model-profile-id" type="hidden" />
                            <div class="aiditor-form-grid aiditor-form-grid-two">
                                <div class="aiditor-field"><label for="aiditor-model-profile-name"><?php echo esc_html__('配置名称', 'aiditor'); ?></label><input id="aiditor-model-profile-name" type="text" placeholder="<?php echo esc_attr__('例如：GPT-4.1 正式写作', 'aiditor'); ?>" /></div>
                                <div class="aiditor-field"><label for="aiditor-model-profile-model"><?php echo esc_html__('模型名称', 'aiditor'); ?></label><input id="aiditor-model-profile-model" type="text" class="regular-text code" placeholder="your-model-name" /></div>
                                <div class="aiditor-field aiditor-field-wide"><label for="aiditor-model-profile-base-url"><?php echo esc_html__('接口地址', 'aiditor'); ?></label><input id="aiditor-model-profile-base-url" type="url" class="regular-text code" placeholder="https://your-ai-endpoint.example/v1" /></div>
                                <div class="aiditor-field aiditor-field-wide"><label for="aiditor-model-profile-api-key"><?php echo esc_html__('API 密钥', 'aiditor'); ?></label><input id="aiditor-model-profile-api-key" type="password" class="regular-text code" autocomplete="off" /><p id="aiditor-model-profile-key-hint" class="description"></p></div>
                            </div>
                            <div class="aiditor-form-grid aiditor-form-grid-three">
                                <div class="aiditor-field"><label for="aiditor-model-profile-temperature"><?php echo esc_html__('温度', 'aiditor'); ?></label><input id="aiditor-model-profile-temperature" type="number" min="0" max="2" step="0.1" /><p class="description"><?php echo esc_html__('控制输出风格的发散程度。值越低越稳定，值越高越灵活，常用 0.2 到 0.7。', 'aiditor'); ?></p></div>
                                <div class="aiditor-field"><label for="aiditor-model-profile-max-tokens"><?php echo esc_html__('最大 Tokens', 'aiditor'); ?></label><input id="aiditor-model-profile-max-tokens" type="number" min="256" max="16384" step="1" /><p class="description"><?php echo esc_html__('限制单次返回内容的最大长度。值越大，越适合长文生成，但耗时和成本通常也会更高。', 'aiditor'); ?></p></div>
                                <div class="aiditor-field"><label for="aiditor-model-profile-timeout"><?php echo esc_html__('请求超时', 'aiditor'); ?></label><input id="aiditor-model-profile-timeout" type="number" min="5" max="300" step="1" /><p class="description"><?php echo esc_html__('等待模型响应的最长秒数。模型较慢、网络较差或生成长文时，可以适当调大。', 'aiditor'); ?></p></div>
                            </div>
                            <div class="aiditor-actions">
                                <button type="button" class="button button-primary" id="aiditor-save-model-profile"><?php echo esc_html__('保存模型配置', 'aiditor'); ?></button>
                                <button type="button" class="button" id="aiditor-test-model-profile"><?php echo esc_html__('测试', 'aiditor'); ?></button>
                                <button type="button" class="button" id="aiditor-clear-model-profile"><?php echo esc_html__('清空表单', 'aiditor'); ?></button>
                            </div>
                        </div>
                        <div class="aiditor-setting-block aiditor-model-library">
                            <div class="aiditor-card-heading-row">
                                <div>
                                    <h3><?php echo esc_html__('已保存模型', 'aiditor'); ?></h3>
                                    <p class="description"><?php echo esc_html__('默认模型仅用于模板分析，可留空；创建队列任务时仍可另选具体模型。', 'aiditor'); ?></p>
                                </div>
                            </div>
                            <div class="aiditor-field">
                                <label for="aiditor-default-model-profile"><?php echo esc_html__('默认模型', 'aiditor'); ?></label>
                                <select id="aiditor-default-model-profile" name="default_model_profile_id"></select>
                            </div>
                            <div id="aiditor-model-profile-list" class="aiditor-model-profile-list"></div>
                        </div>
                    </div>

                    </form>
                    <div id="aiditor-settings-notice" class="aiditor-notice" aria-live="polite"></div>
                </div>
            </section>

            <section class="aiditor-panel" data-tab-panel="article-styles" hidden>
                <div class="aiditor-card aiditor-settings-shell">
                    <div class="aiditor-section-header aiditor-settings-header">
                        <div>
                            <h2><?php echo esc_html__('文章风格', 'aiditor'); ?></h2>
                            <p class="description"><?php echo esc_html__('在这里维护插件公共文章风格，可用于 AI采集 队列、AI采编重写和 AI创作生成。', 'aiditor'); ?></p>
                        </div>
                    </div>

                    <div class="aiditor-settings-pane is-active" data-settings-panel="styles">
                        <div class="aiditor-style-editor">
                            <div class="aiditor-setting-block aiditor-style-form">
                                <h3><?php echo esc_html__('创建文章风格', 'aiditor'); ?></h3>
                                <p class="description"><?php echo esc_html__('先填写风格名称和需求，可调用 AI 生成提示词，再保存为全局可选风格。', 'aiditor'); ?></p>
                                <input id="aiditor-style-id" type="hidden" />
                                <div class="aiditor-field">
                                    <label for="aiditor-style-name"><?php echo esc_html__('风格名称', 'aiditor'); ?></label>
                                    <input id="aiditor-style-name" type="text" placeholder="<?php echo esc_attr__('例如：深度评测风格', 'aiditor'); ?>" />
                                </div>
                                <div class="aiditor-field">
                                    <label for="aiditor-style-description"><?php echo esc_html__('风格需求', 'aiditor'); ?></label>
                                    <textarea id="aiditor-style-description" rows="4" placeholder="<?php echo esc_attr__('例如：像资深产品评测编辑一样写，先讲清楚背景，再讲优缺点，避免营销腔。', 'aiditor'); ?>"></textarea>
                                </div>
                                <div class="aiditor-field">
                                    <label for="aiditor-style-prompt"><?php echo esc_html__('AI 重写/创作风格提示词', 'aiditor'); ?></label>
                                    <textarea id="aiditor-style-prompt" rows="7"></textarea>
                                </div>
                                <div class="aiditor-actions"><button type="button" class="button" id="aiditor-generate-style"><?php echo esc_html__('调用 AI 生成风格', 'aiditor'); ?></button><button type="button" class="button button-primary" id="aiditor-save-style"><?php echo esc_html__('保存文章风格', 'aiditor'); ?></button></div>
                            </div>
                            <div class="aiditor-setting-block aiditor-style-library">
                                <div class="aiditor-card-heading-row">
                                    <div>
                                        <h3><?php echo esc_html__('已有文章风格', 'aiditor'); ?></h3>
                                        <p class="description"><?php echo esc_html__('这些风格会出现在 AI采集、AI采编 和 AI创作 的文章风格下拉框中。', 'aiditor'); ?></p>
                                    </div>
                                </div>
                                <div id="aiditor-style-list" class="aiditor-style-list"></div>
                            </div>
                        </div>
                    </div>
                    <div id="aiditor-style-notice" class="aiditor-notice" aria-live="polite"></div>
                </div>
            </section>
        </div>
        <?php
    }
}
