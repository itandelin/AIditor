<?php
declare(strict_types=1);

class AIditor_Template_Repository
{
    public const OPTION_KEY = 'aiditor_templates';

    public function list_templates(): array
    {
        $templates = $this->load_templates();

        usort(
            $templates,
            static function (array $left, array $right): int {
                return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
            }
        );

        return $templates;
    }

    public function get(string $template_id): ?array
    {
        $template_id = trim($template_id);

        if ('' === $template_id) {
            return null;
        }

        foreach ($this->load_templates() as $template) {
            if ($template_id === (string) ($template['template_id'] ?? '')) {
                return $template;
            }
        }

        return null;
    }

    public function save(array $input): array
    {
        $templates = $this->load_templates();
        $record    = $this->sanitize_template($input);
        $matched   = false;

        foreach ($templates as $index => $template) {
            if ((string) ($template['template_id'] ?? '') === (string) $record['template_id']) {
                $record['created_at'] = (string) ($template['created_at'] ?? $record['created_at']);
                $templates[$index]    = $record;
                $matched              = true;
                break;
            }
        }

        if (! $matched) {
            $templates[] = $record;
        }

        $this->store_templates($templates);

        return $record;
    }

    public function delete(string $template_id): bool
    {
        $template_id = trim($template_id);

        if ('' === $template_id) {
            return false;
        }

        $templates = $this->load_templates();
        $kept      = array();
        $deleted   = false;

        foreach ($templates as $template) {
            if ($template_id === (string) ($template['template_id'] ?? '')) {
                $deleted = true;
                continue;
            }

            $kept[] = $template;
        }

        if ($deleted) {
            $this->store_templates($kept);
        }

        return $deleted;
    }

    protected function load_templates(): array
    {
        if (! function_exists('get_option')) {
            return array();
        }

        $stored = get_option(self::OPTION_KEY, array());

        if (! is_array($stored)) {
            return array();
        }

        $templates = array();

        foreach ($stored as $template) {
            if (is_array($template) && ! empty($template['template_id'])) {
                $templates[] = $template;
            }
        }

        return $templates;
    }

    protected function store_templates(array $templates): void
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION_KEY, array_values($templates), false);
        }
    }

    protected function sanitize_template(array $input): array
    {
        $now         = gmdate('Y-m-d H:i:s');
        $template_id = trim((string) ($input['template_id'] ?? ''));

        if ('' === $template_id) {
            $template_id = $this->generate_template_id();
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ('' === $name) {
            $name = '未命名采集模板';
        }

        $field_schema = $this->sanitize_field_schema($input['field_schema'] ?? array());

        return array(
            'template_id'        => $template_id,
            'name'               => $name,
            'description'        => trim((string) ($input['description'] ?? '')),
            'source_url'         => esc_url_raw((string) ($input['source_url'] ?? '')),
            'source_mode'        => $this->sanitize_choice((string) ($input['source_mode'] ?? 'list'), array('list', 'detail'), 'list'),
            'discovery_strategy' => $this->sanitize_choice((string) ($input['discovery_strategy'] ?? 'ai'), array('ai', 'css', 'api'), 'ai'),
            'pagination'         => $this->sanitize_array($input['pagination'] ?? array()),
            'detail_url_rules'   => $this->sanitize_array($input['detail_url_rules'] ?? array()),
            'extraction_prompt'  => trim((string) ($input['extraction_prompt'] ?? '')),
            'field_schema'       => $field_schema,
            'rewrite_fields'     => $this->sanitize_rewrite_fields($input['rewrite_fields'] ?? array(), $field_schema),
            'field_mapping'      => $this->sanitize_array($input['field_mapping'] ?? array()),
            'target'             => $this->sanitize_array($input['target'] ?? array()),
            'sample'             => $this->sanitize_array($input['sample'] ?? array()),
            'status'             => $this->sanitize_choice((string) ($input['status'] ?? 'draft'), array('draft', 'active', 'archived'), 'draft'),
            'created_at'         => (string) ($input['created_at'] ?? $now),
            'updated_at'         => $now,
        );
    }

    protected function sanitize_field_schema($fields): array
    {
        if (! is_array($fields)) {
            return array();
        }

        $clean = array();

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            if ('' === $key) {
                continue;
            }

            $clean[] = array(
                'key'         => sanitize_key($key),
                'label'       => trim((string) ($field['label'] ?? $key)),
                'type'        => $this->sanitize_choice((string) ($field['type'] ?? 'text'), array('text', 'textarea', 'html', 'url', 'image', 'number', 'date', 'array'), 'text'),
                'required'    => ! empty($field['required']),
                'description' => trim((string) ($field['description'] ?? '')),
            );
        }

        return $clean;
    }

    protected function sanitize_rewrite_fields($fields, array $field_schema): array
    {
        if (! is_array($fields)) {
            return array();
        }

        $allowed = array();

        foreach ($field_schema as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ('' === $key || ! $this->is_rewrite_candidate_field($field)) {
                continue;
            }

            $allowed[$key] = true;
        }

        $clean = array();

        foreach ($fields as $field) {
            $key = sanitize_key((string) $field);

            if ('' !== $key && isset($allowed[$key])) {
                $clean[] = $key;
            }
        }

        return array_values(array_unique($clean));
    }

    protected function is_rewrite_candidate_field(array $field): bool
    {
        $key  = sanitize_key((string) ($field['key'] ?? ''));
        $type = (string) ($field['type'] ?? 'text');

        if ('' === $key || ! in_array($type, array('text', 'textarea', 'html'), true)) {
            return false;
        }

        if (preg_match('/(^|_)(url|link|links|image|img|photo|cover|homepage|website)(_|$)/i', $key)) {
            return false;
        }

        return true;
    }

    protected function sanitize_array($value): array
    {
        return is_array($value) ? $value : array();
    }

    protected function sanitize_choice(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    protected function generate_template_id(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}
