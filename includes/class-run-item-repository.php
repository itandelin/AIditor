<?php
declare(strict_types=1);

class AIditor_Run_Item_Repository
{
    public const TABLE_SUFFIX = 'aiditor_run_items';

    public function maybe_initialize_defaults(): void
    {
        if (! function_exists('dbDelta')) {
            $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';

            if (is_readable($upgrade_file)) {
                require_once $upgrade_file;
            }
        }

        if (! function_exists('dbDelta')) {
            return;
        }

        global $wpdb;

        if (! $wpdb instanceof wpdb) {
            return;
        }

        $table_name      = $this->get_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql             = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id char(36) NOT NULL,
            item_index int(10) unsigned NOT NULL DEFAULT 0,
            source_site varchar(50) NOT NULL DEFAULT '',
            source_slug varchar(191) NOT NULL DEFAULT '',
            title text NULL,
            summary longtext NULL,
            summary_zh longtext NULL,
            source_url text NULL,
            homepage text NULL,
            version varchar(50) NOT NULL DEFAULT '',
            payload longtext NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            attempt_count int(10) unsigned NOT NULL DEFAULT 0,
            retry_count int(10) unsigned NOT NULL DEFAULT 0,
            next_retry_at datetime NULL DEFAULT NULL,
            last_retry_error longtext NULL,
            post_id bigint(20) unsigned NULL DEFAULT NULL,
            message longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            processed_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_slug (run_id, source_slug),
            KEY run_status (run_id, status, item_index),
            KEY run_status_updated (run_id, status, updated_at),
            KEY run_retry (run_id, status, next_retry_at),
            KEY run_id (run_id),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    public function get_table_name(): string
    {
        global $wpdb;

        return $wpdb instanceof wpdb
            ? $wpdb->prefix . self::TABLE_SUFFIX
            : self::TABLE_SUFFIX;
    }

    public function insert_items(string $run_id, array $items, int $start_index = 0): int
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || empty($items)) {
            return 0;
        }

        $table_name = $this->get_table_name();
        $inserted   = 0;
        $now        = gmdate('Y-m-d H:i:s');

        foreach (array_values($items) as $offset => $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = trim((string) ($item['slug'] ?? ''));
            if ('' === $slug) {
                continue;
            }

            $payload = function_exists('wp_json_encode')
                ? wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $result = $wpdb->insert(
                $table_name,
                array(
                    'run_id'      => $run_id,
                    'item_index'  => $start_index + $offset,
                    'source_site' => (string) ($item['source_site'] ?? 'generic_ai'),
                    'source_slug' => $slug,
                    'title'       => (string) ($item['title'] ?? $slug),
                    'summary'     => (string) ($item['summary'] ?? ''),
                    'summary_zh'  => (string) ($item['summary_zh'] ?? ''),
                    'source_url'  => (string) ($item['source_url'] ?? ''),
                    'homepage'    => (string) ($item['homepage'] ?? ''),
                    'version'     => (string) ($item['version'] ?? ''),
                    'payload'     => is_string($payload) ? $payload : null,
                    'status'      => 'pending',
                    'attempt_count' => 0,
                    'retry_count' => 0,
                    'next_retry_at' => null,
                    'last_retry_error' => '',
                    'post_id'     => null,
                    'message'     => '',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                    'processed_at' => null,
                ),
                array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            if (false !== $result) {
                ++$inserted;
                continue;
            }

            if (false !== stripos((string) $wpdb->last_error, 'Duplicate')) {
                $wpdb->last_error = '';
            }
        }

        return $inserted;
    }

    public function list_recent(string $run_id, int $limit = 20): array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb) {
            return array();
        }

        $table_name = $this->get_table_name();
        $limit      = max(1, min(100, $limit));
        $sql        = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE run_id = %s ORDER BY updated_at DESC, id DESC LIMIT %d",
            $run_id,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? array_map(array($this, 'hydrate_item'), $rows) : array();
    }

    public function list_by_status(string $run_id, string $status, int $limit = 100): array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || '' === trim($run_id) || '' === trim($status)) {
            return array();
        }

        $table_name = $this->get_table_name();
        $limit      = max(1, min(500, $limit));
        $sql        = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE run_id = %s AND status = %s ORDER BY updated_at DESC, id DESC LIMIT %d",
            $run_id,
            $status,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? array_map(array($this, 'hydrate_item'), $rows) : array();
    }

    public function requeue_failed(string $run_id): int
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || '' === trim($run_id)) {
            return 0;
        }

        $table_name = $this->get_table_name();

        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name}
                 SET status = 'pending',
                     attempt_count = 0,
                     retry_count = 0,
                     next_retry_at = NULL,
                     last_retry_error = '',
                     processed_at = NULL,
                     message = %s,
                     updated_at = %s
                 WHERE run_id = %s
                   AND status = 'failed'",
                '已手动重新加入队列，等待重新采集。',
                gmdate('Y-m-d H:i:s'),
                $run_id
            )
        );
    }

    public function delete_by_run(string $run_id): int
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || '' === trim($run_id)) {
            return 0;
        }

        return (int) $wpdb->delete(
            $this->get_table_name(),
            array('run_id' => $run_id),
            array('%s')
        );
    }

    public function get_total_count(string $run_id): int
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb) {
            return 0;
        }

        $table_name = $this->get_table_name();
        $sql        = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE run_id = %s",
            $run_id
        );

        return (int) $wpdb->get_var($sql);
    }

    public function get_status_counts(string $run_id): array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb) {
            return array();
        }

        $table_name = $this->get_table_name();
        $sql        = $wpdb->prepare(
            "SELECT status, COUNT(*) AS item_count FROM {$table_name} WHERE run_id = %s GROUP BY status",
            $run_id
        );

        $rows   = $wpdb->get_results($sql, ARRAY_A);
        $counts = array(
            'pending' => 0,
            'running' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed'  => 0,
            'cancelled' => 0,
        );

        if (! is_array($rows)) {
            return $counts;
        }

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');

            if (isset($counts[$status])) {
                $counts[$status] = (int) ($row['item_count'] ?? 0);
            }
        }

        return $counts;
    }

    public function recover_stale_running_items(string $run_id, int $stale_after_seconds = 900, int $max_attempts = 3): array
    {
        global $wpdb;

        $summary = array(
            'requeued' => 0,
            'failed'   => 0,
        );

        if (! $wpdb instanceof wpdb || '' === trim($run_id)) {
            return $summary;
        }

        $table_name          = $this->get_table_name();
        $stale_after_seconds = max(60, $stale_after_seconds);
        $max_attempts        = max(1, $max_attempts);
        $cutoff              = gmdate('Y-m-d H:i:s', time() - $stale_after_seconds);
        $sql                 = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE run_id = %s AND status = 'running' AND updated_at <= %s ORDER BY updated_at ASC, id ASC",
            $run_id,
            $cutoff
        );
        $rows                = $wpdb->get_results($sql, ARRAY_A);

        if (! is_array($rows) || empty($rows)) {
            return $summary;
        }

        foreach ($rows as $row) {
            $item_id   = (int) ($row['id'] ?? 0);
            $attempts  = max(1, (int) ($row['attempt_count'] ?? 0));
            $is_failed = $attempts >= $max_attempts;
            $patch     = array(
                'status'  => $is_failed ? 'failed' : 'pending',
                'message' => $is_failed
                    ? sprintf('连续 %d 次因 worker 中断未完成，已标记为失败。', $attempts)
                    : '已从中断的 worker 中恢复，并重新加入队列等待执行。',
            );

            if ($is_failed) {
                $patch['processed_at'] = gmdate('Y-m-d H:i:s');
            }

            $updated = $this->update_item($item_id, $patch);
            if (! is_array($updated)) {
                continue;
            }

            if ($is_failed) {
                ++$summary['failed'];
                continue;
            }

            ++$summary['requeued'];
        }

        return $summary;
    }

    public function claim_next_pending(string $run_id, int $attempt_limit = 10): ?array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb) {
            return null;
        }

        $table_name = $this->get_table_name();
        $attempt_limit = max(1, min(50, $attempt_limit));

        for ($attempt = 0; $attempt < $attempt_limit; ++$attempt) {
            $now = gmdate('Y-m-d H:i:s');
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table_name}
                 WHERE run_id = %s
                   AND status = 'pending'
                   AND (next_retry_at IS NULL OR next_retry_at = '0000-00-00 00:00:00' OR next_retry_at <= %s)
                 ORDER BY item_index ASC
                 LIMIT 1",
                $run_id,
                $now
            );
            $row = $wpdb->get_row($sql, ARRAY_A);

            if (! is_array($row) || empty($row['id'])) {
                return null;
            }

            $result = $wpdb->update(
                $table_name,
                array(
                    'status'        => 'running',
                    'attempt_count' => ((int) ($row['attempt_count'] ?? 0)) + 1,
                    'next_retry_at' => null,
                    'updated_at'    => $now,
                ),
                array(
                    'id'     => (int) $row['id'],
                    'status' => 'pending',
                ),
                array('%s', '%d', '%s', '%s'),
                array('%d', '%s')
            );

            if (1 === (int) $result) {
                $row['status']        = 'running';
                $row['attempt_count'] = ((int) ($row['attempt_count'] ?? 0)) + 1;
                $row['next_retry_at'] = null;
                $row['updated_at']    = $now;

                return $this->hydrate_item($row);
            }
        }

        return null;
    }

    public function update_item(int $id, array $patch): ?array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || $id <= 0 || empty($patch)) {
            return null;
        }

        $table_name = $this->get_table_name();
        $formats    = array();
        $update     = array();

        foreach ($patch as $key => $value) {
            switch ($key) {
                case 'status':
                case 'title':
                case 'summary':
                case 'summary_zh':
                case 'source_url':
                case 'homepage':
                case 'version':
                case 'message':
                case 'updated_at':
                case 'last_retry_error':
                    $update[$key] = (string) $value;
                    $formats[]    = '%s';
                    break;
                case 'processed_at':
                case 'next_retry_at':
                    $update[$key] = $this->normalize_datetime($value);
                    $formats[]    = '%s';
                    break;
                case 'post_id':
                case 'attempt_count':
                case 'item_index':
                case 'retry_count':
                    $update[$key] = null === $value ? null : (int) $value;
                    $formats[]    = null === $value ? '%s' : '%d';
                    break;
                case 'payload':
                    if (is_array($value)) {
                        $encoded = function_exists('wp_json_encode')
                            ? wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $update[$key] = is_string($encoded) ? $encoded : '';
                    } else {
                        $update[$key] = (string) $value;
                    }
                    $formats[] = '%s';
                    break;
            }
        }

        if (empty($update)) {
            return $this->get_by_id($id);
        }

        if (! isset($update['updated_at'])) {
            $update['updated_at'] = gmdate('Y-m-d H:i:s');
            $formats[]            = '%s';
        }

        $wpdb->update(
            $table_name,
            $update,
            array('id' => $id),
            $formats,
            array('%d')
        );

        return $this->get_by_id($id);
    }

    public function get_by_id(int $id): ?array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || $id <= 0) {
            return null;
        }

        $table_name = $this->get_table_name();
        $sql        = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d LIMIT 1",
            $id
        );
        $row        = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $this->hydrate_item($row) : null;
    }

    public function has_ready_pending(string $run_id): bool
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || '' === trim($run_id)) {
            return false;
        }

        $table_name = $this->get_table_name();
        $now        = gmdate('Y-m-d H:i:s');
        $sql        = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name}
             WHERE run_id = %s
               AND status = 'pending'
               AND (next_retry_at IS NULL OR next_retry_at = '0000-00-00 00:00:00' OR next_retry_at <= %s)",
            $run_id,
            $now
        );

        return (int) $wpdb->get_var($sql) > 0;
    }

    public function get_ready_pending_count(string $run_id): int
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || '' === trim($run_id)) {
            return 0;
        }

        $table_name = $this->get_table_name();
        $now        = gmdate('Y-m-d H:i:s');
        $sql        = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name}
             WHERE run_id = %s
               AND status = 'pending'
               AND (next_retry_at IS NULL OR next_retry_at = '0000-00-00 00:00:00' OR next_retry_at <= %s)",
            $run_id,
            $now
        );

        return (int) $wpdb->get_var($sql);
    }

    public function get_delayed_pending_count(string $run_id): int
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || '' === trim($run_id)) {
            return 0;
        }

        $table_name = $this->get_table_name();
        $now        = gmdate('Y-m-d H:i:s');
        $sql        = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name}
             WHERE run_id = %s
               AND status = 'pending'
               AND next_retry_at IS NOT NULL
               AND next_retry_at <> '0000-00-00 00:00:00'
               AND next_retry_at > %s",
            $run_id,
            $now
        );

        return (int) $wpdb->get_var($sql);
    }

    public function get_next_retry_at(string $run_id): ?string
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || '' === trim($run_id)) {
            return null;
        }

        $table_name = $this->get_table_name();
        $now        = gmdate('Y-m-d H:i:s');
        $sql        = $wpdb->prepare(
            "SELECT MIN(next_retry_at) FROM {$table_name}
             WHERE run_id = %s
               AND status = 'pending'
               AND next_retry_at IS NOT NULL
               AND next_retry_at <> '0000-00-00 00:00:00'
               AND next_retry_at > %s",
            $run_id,
            $now
        );

        $value = $wpdb->get_var($sql);
        if (! is_string($value) || '' === trim($value)) {
            return null;
        }

        return $value;
    }

    protected function hydrate_item(array $row): array
    {
        $payload = $row['payload'] ?? null;

        if (is_string($payload) && '' !== $payload) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : array();
        } elseif (! is_array($payload)) {
            $payload = array();
        }

        return array(
            'id'            => (int) ($row['id'] ?? 0),
            'run_id'        => (string) ($row['run_id'] ?? ''),
            'item_index'    => (int) ($row['item_index'] ?? 0),
            'source_site'   => (string) ($row['source_site'] ?? ''),
            'slug'          => (string) ($row['source_slug'] ?? ''),
            'title'         => (string) ($row['title'] ?? ''),
            'summary'       => (string) ($row['summary'] ?? ''),
            'summary_zh'    => (string) ($row['summary_zh'] ?? ''),
            'source_url'    => (string) ($row['source_url'] ?? ''),
            'homepage'      => (string) ($row['homepage'] ?? ''),
            'version'       => (string) ($row['version'] ?? ''),
            'status'        => (string) ($row['status'] ?? 'pending'),
            'attempt_count' => (int) ($row['attempt_count'] ?? 0),
            'retry_count'   => (int) ($row['retry_count'] ?? 0),
            'next_retry_at' => $this->normalize_datetime($row['next_retry_at'] ?? null),
            'last_retry_error' => (string) ($row['last_retry_error'] ?? ''),
            'post_id'       => isset($row['post_id']) ? (int) $row['post_id'] : null,
            'message'       => (string) ($row['message'] ?? ''),
            'created_at'    => (string) ($row['created_at'] ?? ''),
            'updated_at'    => (string) ($row['updated_at'] ?? ''),
            'processed_at'  => $this->normalize_datetime($row['processed_at'] ?? null),
            'payload'       => $payload,
        );
    }

    protected function normalize_datetime($value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim((string) $value);

        if ('' === $value || '0000-00-00 00:00:00' === $value) {
            return null;
        }

        return $value;
    }
}
