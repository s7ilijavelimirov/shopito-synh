<?php

namespace Shopito_Sync;

class Logger
{
    private static $instance = null;
    private $logs = [];
    private $enabled = false;
    private $max_logs = 100; // Povećan maksimalan broj logova

    private function __construct()
    {
        $settings = get_option('shopito_sync_settings');
        $this->enabled = isset($settings['enable_logging']) && $settings['enable_logging'] == 'yes';
        $this->logs = get_option('shopito_sync_logs', []);

        // Ograničavamo broj logova
        if (count($this->logs) > $this->max_logs) {
            $this->logs = array_slice($this->logs, -$this->max_logs);
            update_option('shopito_sync_logs', $this->logs);
        }
    }

    public static function get_instance()
    {
        if (self::$instance == null) {
            self::$instance = new Logger();
        }
        return self::$instance;
    }

    public function is_enabled()
    {
        return $this->enabled;
    }

    public function set_enabled($enabled)
    {
        $this->enabled = $enabled;
        // $settings = get_option('shopito_sync_settings', []);
        // $settings['enable_logging'] = $enabled ? 'yes' : 'no';
        // update_option('shopito_sync_settings', $settings);
    }

    public function log($message, $level = 'info', $context = [])
    {
        if (!$this->enabled) {
            return;
        }
        if (is_array($level)) {
            $context = $level;
            $level = 'info';
        }

        if (!$this->enabled || empty($message)) {
            return;
        }
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];

        $this->logs[] = $log_entry;

        // Ograničavamo broj logova
        if (count($this->logs) > $this->max_logs) {
            $this->logs = array_slice($this->logs, -$this->max_logs);
        }

        update_option('shopito_sync_logs', $this->logs);

        // Debugovanje u error_log samo ako je omogućeno logovanje
        $context_str = !empty($context) ? ' | ' . json_encode($context) : '';
        $emoji = $this->get_level_emoji($level);
       \error_log("{$emoji} Shopito Sync [{$level}]: {$message}{$context_str}");
    }

    private function get_level_emoji($level)
    {
        switch ($level) {
            case 'error':
                return '❌';
            case 'success':
                return '✅';
            case 'warning':
                return '⚠️';
            case 'info':
            default:
                return 'ℹ️';
        }
    }

    public function info($message, $context = [])
    {
        $this->log($message, 'info', $context);
    }

    public function error($message, $context = [])
    {
        $this->log($message, 'error', $context);
    }

    public function success($message, $context = [])
    {
        $this->log($message, 'success', $context);
    }

    public function warning($message, $context = [])
    {
        $this->log($message, 'warning', $context);
    }

    public function get_logs($limit = 50, $level = null)
    {
        if (!$this->enabled) {
            return [];
        }

        $filtered_logs = $this->logs;

        if ($level) {
            $filtered_logs = array_filter($filtered_logs, function ($log) use ($level) {
                return $log['level'] === $level;
            });
        }

        return array_slice(array_reverse($filtered_logs), 0, $limit);
    }

    public function clear_logs()
    {
        $this->logs = [];
        update_option('shopito_sync_logs', []);
    }
}
