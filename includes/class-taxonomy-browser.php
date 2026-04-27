<?php
declare(strict_types=1);

class AIditor_Taxonomy_Browser
{
    public function get_post_type_options(): array
    {
        if (! function_exists('get_post_types')) {
            return array(
                array(
                    'name'  => 'post',
                    'label' => '文章',
                ),
            );
        }

        $post_types = get_post_types(
            array(
                'show_ui' => true,
            ),
            'objects'
        );

        $options = array();

        foreach ($post_types as $post_type) {
            if (! $post_type instanceof WP_Post_Type) {
                continue;
            }

            $options[] = array(
                'name'  => $post_type->name,
                'label' => $post_type->labels->singular_name ?: $post_type->label,
            );
        }

        usort(
            $options,
            static function (array $left, array $right): int {
                return strcmp($left['label'], $right['label']);
            }
        );

        return $options;
    }

    public function get_target_configuration(string $post_type): array
    {
        $taxonomies = $this->get_taxonomies_for_post_type($post_type);
        $targets    = array();
        $extras     = array();

        foreach ($taxonomies as $taxonomy) {
            if (! $taxonomy instanceof WP_Taxonomy) {
                continue;
            }

            $row = array(
                'name'         => $taxonomy->name,
                'label'        => $taxonomy->labels->singular_name ?: $taxonomy->label,
                'hierarchical' => (bool) $taxonomy->hierarchical,
            );

            if ($taxonomy->hierarchical) {
                $row['root_terms'] = $this->get_terms($taxonomy->name, 0);
                $targets[]         = $row;
            } else {
                $row['terms'] = $this->get_terms($taxonomy->name, null);
                $extras[]     = $row;
            }
        }

        return array(
            'post_types'         => $this->get_post_type_options(),
            'target_taxonomies'  => $targets,
            'extra_taxonomies'   => $extras,
            'meta_fields'        => $this->get_safe_meta_fields($post_type),
        );
    }

    protected function get_safe_meta_fields(string $post_type): array
    {
        $fields = array();

        foreach ($this->get_registered_post_meta_fields($post_type) as $field) {
            $fields[$field['key']] = $field;
        }

        foreach ($this->get_acf_meta_fields($post_type) as $field) {
            $fields[$field['key']] = $field;
        }

        uasort(
            $fields,
            static function (array $left, array $right): int {
                return strcmp($left['label'], $right['label']);
            }
        );

        return array_values($fields);
    }

    protected function get_registered_post_meta_fields(string $post_type): array
    {
        if (! function_exists('get_registered_meta_keys')) {
            return array();
        }

        $registered = get_registered_meta_keys('post', $post_type);
        if (! is_array($registered)) {
            return array();
        }

        $fields = array();
        foreach ($registered as $key => $args) {
            $key = sanitize_key((string) $key);
            if (! $this->is_safe_meta_key($key)) {
                continue;
            }

            $fields[] = array(
                'key'    => $key,
                'label'  => $this->get_meta_field_label($key, is_array($args) ? $args : array()),
                'source' => 'registered',
            );
        }

        return $fields;
    }

    protected function get_acf_meta_fields(string $post_type): array
    {
        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
            return array();
        }

        $groups = acf_get_field_groups(array('post_type' => $post_type));
        if (! is_array($groups)) {
            return array();
        }

        $fields = array();
        foreach ($groups as $group) {
            $group_fields = acf_get_fields($group);
            if (! is_array($group_fields)) {
                continue;
            }

            foreach ($group_fields as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $key = sanitize_key((string) ($field['name'] ?? ''));
                if (! $this->is_safe_meta_key($key)) {
                    continue;
                }

                $fields[] = array(
                    'key'    => $key,
                    'label'  => trim((string) ($field['label'] ?? $key)),
                    'source' => 'acf',
                );
            }
        }

        return $fields;
    }

    protected function get_meta_field_label(string $key, array $args): string
    {
        foreach (array('label', 'description') as $property) {
            if ('' !== trim((string) ($args[$property] ?? ''))) {
                return trim((string) $args[$property]);
            }
        }

        return $key;
    }

    protected function is_safe_meta_key(string $key): bool
    {
        if ('' === $key) {
            return false;
        }

        foreach (array('_wp_', '_edit_', '_oembed_', '_elementor_', '_thumbnail_id', '_wp_page_template') as $blocked) {
            if ($key === $blocked || 0 === strpos($key, $blocked)) {
                return false;
            }
        }

        return true;
    }

    public function get_terms(string $taxonomy, ?int $parent = 0): array
    {
        if (! function_exists('get_terms')) {
            return array();
        }

        $args = array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        );

        if (null !== $parent) {
            $args['parent'] = $parent;
        }

        $terms = get_terms($args);
        if (is_wp_error($terms) || ! is_array($terms)) {
            return array();
        }

        $items = array();

        foreach ($terms as $term) {
            if (! $term instanceof WP_Term) {
                continue;
            }

            $items[] = array(
                'term_id'  => (int) $term->term_id,
                'name'     => $term->name,
                'slug'     => $term->slug,
                'parent'   => (int) $term->parent,
                'has_children' => $this->term_has_children($taxonomy, (int) $term->term_id),
            );
        }

        return $items;
    }

    public function validate_selection(array $request): void
    {
        $post_type       = (string) ($request['post_type'] ?? '');
        $target_taxonomy = (string) ($request['target_taxonomy'] ?? '');
        $target_term_id  = (int) ($request['target_term_id'] ?? 0);

        if (! post_type_exists($post_type)) {
            throw new InvalidArgumentException('所选文章类型不存在。');
        }

        $taxonomy = get_taxonomy($target_taxonomy);
        if (! $taxonomy instanceof WP_Taxonomy) {
            throw new InvalidArgumentException('所选分类法不存在。');
        }

        if (! is_object_in_taxonomy($post_type, $target_taxonomy)) {
            throw new InvalidArgumentException('所选分类法未挂载到当前文章类型。');
        }

        if (! $taxonomy->hierarchical) {
            throw new InvalidArgumentException('目标目录所用分类法必须是层级型分类法。');
        }

        $term = get_term($target_term_id, $target_taxonomy);
        if (! $term instanceof WP_Term) {
            throw new InvalidArgumentException('所选目标目录不存在。');
        }

        $extra_tax_terms = $request['extra_tax_terms'] ?? array();
        if (! is_array($extra_tax_terms)) {
            return;
        }

        foreach ($extra_tax_terms as $taxonomy_name => $term_ids) {
            if (! is_string($taxonomy_name) || ! is_object_in_taxonomy($post_type, $taxonomy_name)) {
                throw new InvalidArgumentException('附加分类法选择与当前文章类型不匹配。');
            }

            if (! is_array($term_ids)) {
                throw new InvalidArgumentException('附加分类法项必须以数组形式提交。');
            }

            foreach ($term_ids as $term_id) {
                $extra_term = get_term((int) $term_id, $taxonomy_name);
                if (! $extra_term instanceof WP_Term) {
                    throw new InvalidArgumentException('所选附加分类法项中存在不存在的条目。');
                }
            }
        }
    }

    protected function get_taxonomies_for_post_type(string $post_type): array
    {
        if (! function_exists('get_object_taxonomies')) {
            return array();
        }

        $taxonomies = get_object_taxonomies($post_type, 'objects');

        return is_array($taxonomies) ? $taxonomies : array();
    }

    protected function term_has_children(string $taxonomy, int $term_id): bool
    {
        $children = get_terms(
            array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'parent'     => $term_id,
                'number'     => 1,
                'fields'     => 'ids',
            )
        );

        return is_array($children) && ! empty($children);
    }
}
