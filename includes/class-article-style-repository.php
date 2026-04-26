<?php
declare(strict_types=1);

class AIditor_Article_Style_Repository
{
    public const OPTION_KEY = 'aiditor_article_styles';

    public function maybe_initialize_defaults(): void
    {
        if (! function_exists('get_option') || ! function_exists('update_option')) {
            return;
        }

        $existing = get_option(self::OPTION_KEY, null);
        if (! is_array($existing)) {
            update_option(self::OPTION_KEY, $this->default_styles(), false);

            return;
        }

        $merged = $this->merge_missing_default_styles($existing);
        if ($merged !== $existing) {
            update_option(self::OPTION_KEY, $merged, false);
        }
    }

    public function list_styles(): array
    {
        $styles = $this->load_styles();

        usort(
            $styles,
            function (array $left, array $right): int {
                $left_builtin  = ! empty($left['is_builtin']) || $this->is_builtin_style((string) ($left['style_id'] ?? ''));
                $right_builtin = ! empty($right['is_builtin']) || $this->is_builtin_style((string) ($right['style_id'] ?? ''));

                if ($left_builtin !== $right_builtin) {
                    return $left_builtin ? -1 : 1;
                }

                $left_order  = (int) ($left['sort_order'] ?? $this->get_builtin_sort_order((string) ($left['style_id'] ?? '')));
                $right_order = (int) ($right['sort_order'] ?? $this->get_builtin_sort_order((string) ($right['style_id'] ?? '')));

                if ($left_order !== $right_order) {
                    return $left_order <=> $right_order;
                }

                return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            }
        );

        return $styles;
    }

    public function get(string $style_id): ?array
    {
        $style_id = trim($style_id);

        foreach ($this->load_styles() as $style) {
            if ($style_id === (string) ($style['style_id'] ?? '')) {
                return $style;
            }
        }

        return null;
    }

    public function save(array $input): array
    {
        $styles = $this->load_styles();
        $record = $this->sanitize_style($input);
        $found  = false;

        foreach ($styles as $index => $style) {
            if ((string) ($style['style_id'] ?? '') === (string) $record['style_id']) {
                $record['created_at'] = (string) ($style['created_at'] ?? $record['created_at']);
                $styles[$index]       = $record;
                $found                = true;
                break;
            }
        }

        if (! $found) {
            $styles[] = $record;
        }

        $this->store_styles($styles);

        return $record;
    }

    public function delete(string $style_id): bool
    {
        if ($this->is_builtin_style($style_id)) {
            return false;
        }

        $styles  = $this->load_styles();
        $kept    = array();
        $deleted = false;

        foreach ($styles as $style) {
            if ($style_id === (string) ($style['style_id'] ?? '')) {
                $deleted = true;
                continue;
            }

            $kept[] = $style;
        }

        if ($deleted) {
            $this->store_styles($kept);
        }

        return $deleted;
    }

    protected function load_styles(): array
    {
        if (! function_exists('get_option')) {
            return $this->default_styles();
        }

        $stored = get_option(self::OPTION_KEY, array());
        if (! is_array($stored) || empty($stored)) {
            return $this->default_styles();
        }

        return array_values(array_filter($stored, 'is_array'));
    }

    protected function store_styles(array $styles): void
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION_KEY, array_values($styles), false);
        }
    }

    protected function merge_missing_default_styles(array $styles): array
    {
        $existing_ids = array();

        foreach ($styles as $style) {
            if (! is_array($style)) {
                continue;
            }

            $style_id = trim((string) ($style['style_id'] ?? ''));
            if ('' !== $style_id) {
                $existing_ids[$style_id] = true;
            }
        }

        foreach ($this->default_styles() as $default_style) {
            $default_id = (string) ($default_style['style_id'] ?? '');

            if ('' !== $default_id && ! isset($existing_ids[$default_id])) {
                $styles[] = $default_style;
            }
        }

        return array_values(array_filter($styles, 'is_array'));
    }

    protected function is_builtin_style(string $style_id): bool
    {
        $style_id = trim($style_id);

        foreach ($this->default_styles() as $style) {
            if ($style_id === (string) ($style['style_id'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    protected function get_builtin_sort_order(string $style_id): int
    {
        $style_id = trim($style_id);

        foreach ($this->default_styles() as $style) {
            if ($style_id === (string) ($style['style_id'] ?? '')) {
                return (int) ($style['sort_order'] ?? 999);
            }
        }

        return 999;
    }

    protected function sanitize_style(array $input): array
    {
        $now      = gmdate('Y-m-d H:i:s');
        $style_id = trim((string) ($input['style_id'] ?? ''));
        $name     = trim((string) ($input['name'] ?? ''));
        $prompt   = trim((string) ($input['prompt'] ?? ''));

        if ('' === $style_id) {
            $style_id = function_exists('sanitize_title') ? sanitize_title($name) : sanitize_key($name);
        }

        if ('' === $style_id) {
            $style_id = 'style-' . substr(md5($now . wp_rand()), 0, 10);
        }

        if ('' === $name) {
            $name = '未命名文章风格';
        }

        if ('' === $prompt) {
            $prompt = '自然、清晰、信息密度高，避免营销腔和机械 AI 口吻。';
        }

        return array(
            'style_id'    => sanitize_key($style_id),
            'name'        => $name,
            'description' => trim((string) ($input['description'] ?? '')),
            'prompt'      => $prompt,
            'is_builtin'  => false,
            'sort_order'  => 999,
            'created_at'  => (string) ($input['created_at'] ?? $now),
            'updated_at'  => $now,
        );
    }

    protected function default_styles(): array
    {
        $now = gmdate('Y-m-d H:i:s');

        return array(
            array(
                'style_id'    => 'editorial-guide',
                'name'        => '编辑导向',
                'description' => '适合工具介绍、资讯介绍和知识型内容。',
                'prompt'      => '像专业中文科技编辑一样写作：自然、克制、信息密度高，避免硬广、模板句和机械翻译腔。',
                'is_builtin'  => true,
                'sort_order'  => 10,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array(
                'style_id'    => 'news-brief',
                'name'        => '新闻快讯',
                'description' => '适合新品发布、融资动态、行业事件和短新闻。',
                'prompt'      => '用中文新闻稿的写法组织内容：先交代事件、主体和结果，再补充背景、影响和后续观察。句子要短，信息要准，少用形容词，不写夸张判断，不制造未经证实的结论。',
                'is_builtin'  => true,
                'sort_order'  => 20,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array(
                'style_id'    => 'deep-analysis',
                'name'        => '深度解读',
                'description' => '适合技术趋势、平台能力、商业模式和复杂产品介绍。',
                'prompt'      => '像资深科技媒体编辑写深度解读：先讲清楚问题背景，再拆解关键能力、约束条件和潜在影响。避免空泛赞美，多写因果关系、边界条件和读者真正需要理解的变化。',
                'is_builtin'  => true,
                'sort_order'  => 30,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array(
                'style_id'    => 'product-review',
                'name'        => '产品评测',
                'description' => '适合 AI 工具、SaaS、插件、应用和平台型产品。',
                'prompt'      => '像严谨的产品评测作者写作：先说明产品解决什么问题，再评价核心功能、使用门槛、优势、不足和适合人群。语气客观，不写广告词，不把功能清单机械翻译成文章。',
                'is_builtin'  => true,
                'sort_order'  => 40,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array(
                'style_id'    => 'practical-guide',
                'name'        => '实用指南',
                'description' => '适合教程、方法论、工具使用说明和落地场景文章。',
                'prompt'      => '用面向实践的中文指南风格写作：每段都要回答读者如何理解、如何使用、适合什么场景。语言清楚直接，少讲概念口号，多给判断依据和操作线索。',
                'is_builtin'  => true,
                'sort_order'  => 50,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array(
                'style_id'    => 'popular-science',
                'name'        => '知识科普',
                'description' => '适合向普通读者解释 AI、技术产品和专业概念。',
                'prompt'      => '用通俗但不幼稚的科普写法：先解释它是什么，再用类比或场景降低理解门槛。避免堆术语，专业词出现时要顺手解释，整体读起来像人写给人看的说明。',
                'is_builtin'  => true,
                'sort_order'  => 60,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array(
                'style_id'    => 'industry-observation',
                'name'        => '行业观察',
                'description' => '适合行业趋势、竞争格局、政策环境和市场变化分析。',
                'prompt'      => '用行业观察文章的写法：从事件或产品切入，延伸到产业链、用户需求、竞争格局和可能影响。判断要克制，观点要有依据，不写玄乎的大词和没有信息量的趋势套话。',
                'is_builtin'  => true,
                'sort_order'  => 70,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array(
                'style_id'    => 'seo-evergreen',
                'name'        => 'SEO 长文',
                'description' => '适合长期收录的工具介绍、产品介绍和百科型内容。',
                'prompt'      => '写成适合搜索收录的中文常青内容：关键词自然出现，不堆砌；段落结构清楚，信息覆盖完整；每段提供具体信息，避免空泛开场、机械总结和明显 AI 腔。',
                'is_builtin'  => true,
                'sort_order'  => 80,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array(
                'style_id'    => 'human-column',
                'name'        => '专栏口吻',
                'description' => '适合更自然、有判断力但不过度口语化的编辑专栏。',
                'prompt'      => '像一位熟悉行业的中文专栏作者写作：允许有清晰判断，但不要情绪化。句子有长短变化，少用模板转折，避免“值得一提的是”“总的来说”等机械连接词。',
                'is_builtin'  => true,
                'sort_order'  => 90,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
        );
    }
}
