<?php
/**
 * Plugin Name: AIditor
 * Plugin URI: https://github.com/itandelin/AIditor
 * Description: AIditor 使用通用 AI 采集模板从外部列表页或详情页抽取内容，并写入 WordPress 文章。
 * Version: 0.0.1
 * Author: Mr. T
 * Author URI: https://github.com/itandelin/AIditor
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: aiditor
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('AIDITOR_VERSION', '0.0.1');
define('AIDITOR_FILE', __FILE__);
define('AIDITOR_PATH', plugin_dir_path(__FILE__));
define('AIDITOR_URL', plugin_dir_url(__FILE__));

$aiditor_files = array(
    AIDITOR_PATH . 'includes/class-settings.php',
    AIDITOR_PATH . 'includes/class-run-item-repository.php',
    AIDITOR_PATH . 'includes/class-run-repository.php',
    AIDITOR_PATH . 'includes/class-template-repository.php',
    AIDITOR_PATH . 'includes/class-article-style-repository.php',
    AIDITOR_PATH . 'includes/class-deduper.php',
    AIDITOR_PATH . 'includes/class-page-fetcher.php',
    AIDITOR_PATH . 'includes/class-source-adapter-registry.php',
    AIDITOR_PATH . 'includes/class-content-normalizer.php',
    AIDITOR_PATH . 'includes/class-source-researcher.php',
    AIDITOR_PATH . 'includes/class-taxonomy-browser.php',
    AIDITOR_PATH . 'includes/class-ai-rewriter.php',
    AIDITOR_PATH . 'includes/class-ai-extractor.php',
    AIDITOR_PATH . 'includes/class-draft-writer.php',
    AIDITOR_PATH . 'includes/class-queue-worker.php',
    AIDITOR_PATH . 'includes/class-admin-page.php',
    AIDITOR_PATH . 'includes/class-rest-controller.php',
    AIDITOR_PATH . 'includes/class-plugin.php',
);

foreach ($aiditor_files as $aiditor_file) {
    require_once $aiditor_file;
}

function aiditor(): AIditor_Plugin
{
    static $plugin = null;

    if (null === $plugin) {
        $plugin = new AIditor_Plugin();
    }

    return $plugin;
}

register_activation_hook(__FILE__, array('AIditor_Plugin', 'activate'));
register_deactivation_hook(__FILE__, static function (): void {
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook(AIditor_Plugin::CLEANUP_CRON_HOOK);
    }
});

aiditor()->register_hooks();
