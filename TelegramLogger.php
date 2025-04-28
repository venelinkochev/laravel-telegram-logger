<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Http;
use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use Throwable;

class TelegramLogger extends AbstractProcessingHandler
{
    protected string $botToken;
    protected string $chatId;
    protected string $appName;
    protected string $environment;

    public function __construct($level = Logger::ERROR)
    {
        parent::__construct($level);

        $this->botToken = config('services.telegram.error_bot_token');
        $this->chatId = config('services.telegram.error_chat_id');
        $this->appName = config('app.name');
        $this->environment = config('app.env');
    }

    protected function write($record): void
    {
        if (!$this->botToken || !$this->chatId) {
            return;
        }

        try {
            $content = $this->formatMessage($record->toArray());

            Http::post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $content,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ]);
        } catch (Throwable $e) {
            // Silently fail to prevent recursive logging
        }
    }

    protected function formatMessage(array $record): string
    {
        $emoji = $this->getEmoji($record['level_name']);

        $message = "<b>{$emoji} {$this->appName} Error ({$this->environment})</b>\n\n";

        // Add timestamp
        $message .= "<b>🕒 Time:</b> " . now()->format('Y-m-d H:i:s') . "\n";

        // Add error level
        $message .= "<b>📊 Level:</b> {$record['level_name']}\n";

        // Add error message
        $message .= "<b>❌ Message:</b>\n{$record['message']}\n";

        // Add context if available
        if (!empty($record['context'])) {
            $message .= "\n<b>📝 Context:</b>\n";
            foreach ($record['context'] as $key => $value) {
                if ($key === 'exception' && $value instanceof Throwable) {
                    $message .= $this->formatException($value);
                    continue;
                }
                $message .= "<b>{$key}:</b> " . $this->formatValue($value) . "\n";
            }
        }

        // Add server info
        $message .= "\n<b>🖥️ Server Info:</b>\n";
        $message .= "IP: " . request()->server('SERVER_ADDR', 'N/A') . "\n";
        $message .= "URL: " . request()->fullUrl() . "\n";
        $message .= "Method: " . request()->method() . "\n";

        // Truncate if too long for Telegram
        return mb_substr($message, 0, 4096);
    }

    protected function formatException(Throwable $exception): string
    {
        $message = "\n<b>Exception:</b>\n";
        $message .= "Type: " . get_class($exception) . "\n";
        $message .= "Message: " . $exception->getMessage() . "\n";
        $message .= "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n";

        // Add stack trace (limited to 3 frames)
        $trace = array_slice($exception->getTrace(), 0, 3);
        if (!empty($trace)) {
            $message .= "\nStack Trace (latest 3 calls):\n";
            foreach ($trace as $frame) {
                $message .= isset($frame['file'])
                    ? "• " . basename($frame['file']) . ":" . ($frame['line'] ?? '?') . "\n"
                    : "• [internal function]\n";
            }
        }

        return $message;
    }

    protected function formatValue($value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT);
        }
        if (is_object($value)) {
            return get_class($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        return (string) $value;
    }

    protected function getEmoji(string $level): string
    {
        return match ($level) {
            'EMERGENCY' => '🚨',
            'ALERT'     => '⚠️',
            'CRITICAL'  => '🔴',
            'ERROR'     => '❌',
            'WARNING'   => '⚡',
            'NOTICE'    => '📢',
            'INFO'      => 'ℹ️',
            'DEBUG'     => '🐛',
            default     => '❓'
        };
    }
}
