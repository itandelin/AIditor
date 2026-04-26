<?php
declare(strict_types=1);

class AIditor_Run_Repository
{
    public const TABLE_SUFFIX = 'aiditor_runs';

    protected AIditor_Run_Item_Repository $items;

    public function __construct(AIditor_Run_Item_Repository $items)
    {
        $this->items = $items;
    }

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
            source_url text NOT NULL,
            source_type varchar(50) NOT NULL DEFAULT '',
            status varchar(30) NOT NULL DEFAULT 'queued',
            requested_limit int(10) unsigned NOT NULL DEFAULT 0,
            post_type varchar(50) NOT NULL DEFAULT 'post',
            post_status varchar(20) NOT NULL DEFAULT 'draft',
            target_taxonomy varchar(50) NOT NULL DEFAULT '',
            target_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
            author_id bigint(20) unsigned NOT NULL DEFAULT 0,
            extra_tax_terms longtext NULL,
            current_page int(10) unsigned NOT NULL DEFAULT 1,
            initialized_items int(10) unsigned NOT NULL DEFAULT 0,
            no_progress_pages int(10) unsigned NOT NULL DEFAULT 0,
            source_exhausted tinyint(1) NOT NULL DEFAULT 0,
            last_batch_count int(10) unsigned NOT NULL DEFAULT 0,
            last_batch_started_at datetime NULL DEFAULT NULL,
            last_batch_finished_at datetime NULL DEFAULT NULL,
            last_scheduled_at datetime NULL DEFAULT NULL,
            next_scheduled_at datetime NULL DEFAULT NULL,
            worker_message longtext NULL,
            started_at datetime NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            finished_at datetime NULL DEFAULT NULL,
            last_error longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_id (run_id),
            KEY status (status),
            KEY status_finished (status, finished_at),
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

    public function create(array $run): array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb) {
            return array();
        }

        $record = array_replace(
            array(
                'run_id'            => $this->generate_run_id(),
                'source_url'        => '',
                'source_type'       => '',
                'status'            => 'queued',
                'requested_limit'   => 0,
                'post_type'         => 'post',
                'post_status'       => 'draft',
                'target_taxonomy'   => '',
                'target_term_id'    => 0,
                'author_id'         => 0,
                'extra_tax_terms'   => array(),
                'current_page'      => 1,
                'initialized_items' => 0,
                'no_progress_pages' => 0,
                'source_exhausted'  => 0,
                'last_batch_count'  => 0,
                'last_batch_started_at' => null,
                'last_batch_finished_at' => null,
                'last_scheduled_at' => null,
                'next_scheduled_at' => null,
                'worker_message'    => '',
                'started_at'        => null,
                'created_at'        => gmdate('Y-m-d H:i:s'),
                'updated_at'        => gmdate('Y-m-d H:i:s'),
                'finished_at'       => null,
                'last_error'        => '',
            ),
            $run
        );

        $extra_tax_terms = is_array($record['extra_tax_terms'])
            ? $record['extra_tax_terms']
            : array();

        $payload = function_exists('wp_json_encode')
            ? wp_json_encode($extra_tax_terms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($extra_tax_terms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $wpdb->insert(
            $this->get_table_name(),
            array(
                'run_id'            => (string) $record['run_id'],
                'source_url'        => (string) $record['source_url'],
                'source_type'       => (string) $record['source_type'],
                'status'            => (string) $record['status'],
                'requested_limit'   => (int) $record['requested_limit'],
                'post_type'         => (string) $record['post_type'],
                'post_status'       => (string) $record['post_status'],
                'target_taxonomy'   => (string) $record['target_taxonomy'],
                'target_term_id'    => (int) $record['target_term_id'],
                'author_id'         => (int) $record['author_id'],
                'extra_tax_terms'   => is_string($payload) ? $payload : '[]',
                'current_page'      => (int) $record['current_page'],
                'initialized_items' => (int) $record['initialized_items'],
                'no_progress_pages' => (int) $record['no_progress_pages'],
                'source_exhausted'  => ! empty($record['source_exhausted']) ? 1 : 0,
                'last_batch_count'  => (int) $record['last_batch_count'],
                'last_batch_started_at' => $this->normalize_datetime($record['last_batch_started_at'] ?? null),
                'last_batch_finished_at' => $this->normalize_datetime($record['last_batch_finished_at'] ?? null),
                'last_scheduled_at' => $this->normalize_datetime($record['last_scheduled_at'] ?? null),
                'next_scheduled_at' => $this->normalize_datetime($record['next_scheduled_at'] ?? null),
                'worker_message'    => (string) ($record['worker_message'] ?? ''),
                'started_at'        => $this->normalize_datetime($record['started_at'] ?? null),
                'created_at'        => (string) $record['created_at'],
                'updated_at'        => (string) $record['updated_at'],
                'finished_at'       => $this->normalize_datetime($record['finished_at'] ?? null),
                'last_error'        => (string) ($record['last_error'] ?? ''),
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $this->get((string) $record['run_id']) ?? array();
    }

    public function update(string $run_id, array $patch): ?array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || '' === trim($run_id) || empty($patch)) {
            return null;
        }

        $update  = array();
        $formats = array();

        foreach ($patch as $key => $value) {
            switch ($key) {
                case 'source_url':
                case 'source_type':
                case 'status':
                case 'post_type':
                case 'post_status':
                case 'target_taxonomy':
                case 'started_at':
                case 'created_at':
                case 'updated_at':
                case 'finished_at':
                case 'last_batch_started_at':
                case 'last_batch_finished_at':
                case 'last_scheduled_at':
                case 'next_scheduled_at':
                case 'last_error':
                case 'worker_message':
                    $update[$key] = $this->normalize_datetime_field($key, $value);
                    $formats[]    = '%s';
                    break;
                case 'requested_limit':
                case 'target_term_id':
                case 'author_id':
                case 'current_page':
                case 'initialized_items':
                case 'no_progress_pages':
                case 'source_exhausted':
                case 'last_batch_count':
                    $update[$key] = (int) $value;
                    $formats[]    = '%d';
                    break;
                case 'extra_tax_terms':
                    if (is_array($value)) {
                        $encoded = function_exists('wp_json_encode')
                            ? wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $update[$key] = is_string($encoded) ? $encoded : '[]';
                    } else {
                        $update[$key] = (string) $value;
                    }
                    $formats[] = '%s';
                    break;
            }
        }

        if (empty($update)) {
            return $this->get($run_id);
        }

        if (! isset($update['updated_at'])) {
            $update['updated_at'] = gmdate('Y-m-d H:i:s');
            $formats[]            = '%s';
        }

        $wpdb->update(
            $this->get_table_name(),
            $update,
            array('run_id' => $run_id),
            $formats,
            array('%s')
        );

        return $this->get($run_id);
    }

    public function list_runs(int $limit = 10): array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb) {
            return array();
        }

        $limit = max(1, min(100, $limit));
        $sql   = $wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} ORDER BY created_at DESC LIMIT %d",
            $limit
        );
        $rows  = $wpdb->get_results($sql, ARRAY_A);

        if (! is_array($rows)) {
            return array();
        }

        return array_map(
            function (array $row): array {
                return $this->hydrate_run($row, false);
            },
            $rows
        );
    }

    public function get(string $run_id): ?array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || '' === trim($run_id)) {
            return null;
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} WHERE run_id = %s LIMIT 1",
            $run_id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $this->hydrate_run($row, true) : null;
    }

    public function append_item(string $run_id, array $item): ?array
    {
        $this->items->insert_items($run_id, array($item), $this->items->get_total_count($run_id));

        return $this->get($run_id);
    }

    public function delete_run(string $run_id): array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || '' === trim($run_id)) {
            return array(
                'run_deleted'   => 0,
                'items_deleted' => 0,
            );
        }

        $items_deleted = $this->items->delete_by_run($run_id);
        $run_deleted   = (int) $wpdb->delete(
            $this->get_table_name(),
            array('run_id' => $run_id),
            array('%s')
        );

        return array(
            'run_deleted'   => $run_deleted,
            'items_deleted' => $items_deleted,
        );
    }

    protected function hydrate_run(array $row, bool $include_items): array
    {
        $extra_tax_terms = array();

        if (isset($row['extra_tax_terms']) && is_string($row['extra_tax_terms']) && '' !== $row['extra_tax_terms']) {
            $decoded = json_decode($row['extra_tax_terms'], true);
            $extra_tax_terms = is_array($decoded) ? $decoded : array();
        }

        $status_counts  = $this->items->get_status_counts((string) ($row['run_id'] ?? ''));
        $discovered     = $this->items->get_total_count((string) ($row['run_id'] ?? ''));
        $ready_pending  = $this->items->get_ready_pending_count((string) ($row['run_id'] ?? ''));
        $delayed_pending = $this->items->get_delayed_pending_count((string) ($row['run_id'] ?? ''));
        $next_retry_at  = $this->items->get_next_retry_at((string) ($row['run_id'] ?? ''));
        $next_scheduled_at = $this->normalize_datetime($row['next_scheduled_at'] ?? null);
        $processed      = (int) ($status_counts['created'] ?? 0) + (int) ($status_counts['updated'] ?? 0) + (int) ($status_counts['skipped'] ?? 0) + (int) ($status_counts['failed'] ?? 0) + (int) ($status_counts['cancelled'] ?? 0);
        $summary        = array(
            'requested'          => (int) ($row['requested_limit'] ?? 0),
            'discovered'         => $discovered,
            'initialized_items'  => (int) ($row['initialized_items'] ?? 0),
            'no_progress_pages'  => (int) ($row['no_progress_pages'] ?? 0),
            'processed'          => $processed,
            'created'            => (int) ($status_counts['created'] ?? 0),
            'updated'            => (int) ($status_counts['updated'] ?? 0),
            'skipped'            => (int) ($status_counts['skipped'] ?? 0),
            'failed'             => (int) ($status_counts['failed'] ?? 0),
            'cancelled'          => (int) ($status_counts['cancelled'] ?? 0),
            'pending'            => (int) ($status_counts['pending'] ?? 0),
            'ready_pending'      => $ready_pending,
            'delayed_pending'    => $delayed_pending,
            'running'            => (int) ($status_counts['running'] ?? 0),
            'source_exhausted'   => ! empty($row['source_exhausted']),
            'current_page'       => (int) ($row['current_page'] ?? 1),
            'last_batch_count'   => (int) ($row['last_batch_count'] ?? 0),
            'next_retry_at'      => is_string($next_retry_at) && '' !== $next_retry_at ? $next_retry_at : null,
            'next_scheduled_at'  => $next_scheduled_at,
        );

        return array(
            'run_id'            => (string) ($row['run_id'] ?? ''),
            'source_url'        => (string) ($row['source_url'] ?? ''),
            'source_type'       => (string) ($row['source_type'] ?? ''),
            'status'            => (string) ($row['status'] ?? 'queued'),
            'created_at'        => (string) ($row['created_at'] ?? ''),
            'updated_at'        => (string) ($row['updated_at'] ?? ''),
            'started_at'        => isset($row['started_at']) ? (string) $row['started_at'] : null,
            'finished_at'       => isset($row['finished_at']) ? (string) $row['finished_at'] : null,
            'limit'             => (int) ($row['requested_limit'] ?? 0),
            'requested_limit'   => (int) ($row['requested_limit'] ?? 0),
            'post_type'         => (string) ($row['post_type'] ?? 'post'),
            'post_status'       => (string) ($row['post_status'] ?? 'draft'),
            'target_taxonomy'   => (string) ($row['target_taxonomy'] ?? ''),
            'target_term_id'    => (int) ($row['target_term_id'] ?? 0),
            'author_id'         => (int) ($row['author_id'] ?? 0),
            'extra_tax_terms'   => $extra_tax_terms,
            'current_page'      => (int) ($row['current_page'] ?? 1),
            'initialized_items' => (int) ($row['initialized_items'] ?? 0),
            'no_progress_pages' => (int) ($row['no_progress_pages'] ?? 0),
            'source_exhausted'  => ! empty($row['source_exhausted']),
            'last_batch_count'  => (int) ($row['last_batch_count'] ?? 0),
            'last_batch_started_at' => $this->normalize_datetime($row['last_batch_started_at'] ?? null),
            'last_batch_finished_at' => $this->normalize_datetime($row['last_batch_finished_at'] ?? null),
            'last_scheduled_at' => $this->normalize_datetime($row['last_scheduled_at'] ?? null),
            'next_scheduled_at' => $next_scheduled_at,
            'worker_message'    => (string) ($row['worker_message'] ?? ''),
            'last_error'        => (string) ($row['last_error'] ?? ''),
            'summary'           => $summary,
            'items'             => $include_items ? $this->items->list_recent((string) ($row['run_id'] ?? ''), 20) : array(),
            'failed_items'      => $include_items ? $this->items->list_by_status((string) ($row['run_id'] ?? ''), 'failed', 100) : array(),
        );
    }

    public function cleanup_terminal_runs(int $retention_days, int $limit = 50): array
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb || $retention_days <= 0) {
            return array(
                'runs_deleted'  => 0,
                'items_deleted' => 0,
            );
        }

        $limit  = max(1, min(500, $limit));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));
        $table  = $this->get_table_name();
        $sql    = $wpdb->prepare(
            "SELECT run_id FROM {$table}
             WHERE status IN ('completed', 'completed_with_errors', 'failed', 'cancelled')
               AND finished_at IS NOT NULL
               AND finished_at <> '0000-00-00 00:00:00'
               AND finished_at < %s
             ORDER BY finished_at ASC
             LIMIT %d",
            $cutoff,
            $limit
        );
        $run_ids = $wpdb->get_col($sql);

        if (! is_array($run_ids) || empty($run_ids)) {
            return array(
                'runs_deleted'  => 0,
                'items_deleted' => 0,
            );
        }

        $run_ids = array_values(array_filter(array_map('strval', $run_ids)));
        if (empty($run_ids)) {
            return array(
                'runs_deleted'  => 0,
                'items_deleted' => 0,
            );
        }

        $placeholders = implode(',', array_fill(0, count($run_ids), '%s'));
        $items_table  = $this->items->get_table_name();
        $items_sql    = $wpdb->prepare("DELETE FROM {$items_table} WHERE run_id IN ({$placeholders})", $run_ids);
        $items_deleted = (int) $wpdb->query($items_sql);
        $runs_sql      = $wpdb->prepare("DELETE FROM {$table} WHERE run_id IN ({$placeholders})", $run_ids);
        $runs_deleted  = (int) $wpdb->query($runs_sql);

        return array(
            'runs_deleted'  => $runs_deleted,
            'items_deleted' => $items_deleted,
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

    protected function normalize_datetime_field(string $field, $value): string
    {
        if (in_array($field, array('started_at', 'finished_at', 'last_batch_started_at', 'last_batch_finished_at', 'last_scheduled_at', 'next_scheduled_at'), true)) {
            $value = $this->normalize_datetime($value);

            return null === $value ? '' : $value;
        }

        return (string) $value;
    }

    protected function generate_run_id(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        return uniqid('aiditor-run-', true);
    }
}
