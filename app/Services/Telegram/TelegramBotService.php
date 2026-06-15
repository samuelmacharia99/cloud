<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function sendMessage(string $token, string $chatId, string $text, string $parseMode = 'HTML'): bool
    {
        $token = trim($token);
        $chatId = trim($chatId);

        if ($token === '' || $chatId === '') {
            return false;
        }

        $chunks = $this->chunkMessage($text);

        foreach ($chunks as $chunk) {
            $response = Http::timeout(15)->post(self::API_BASE.$token.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => $chunk,
                'parse_mode' => $parseMode,
                'disable_web_page_preview' => true,
            ]);

            if (! $response->successful()) {
                Log::warning('Telegram sendMessage failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * @return array{ok: bool, username?: string, message?: string}
     */
    public function validateCredentials(string $token, string $chatId): array
    {
        $token = trim($token);
        $chatId = trim($chatId);

        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'message' => 'Bot token and chat ID are required.'];
        }

        $me = Http::timeout(15)->get(self::API_BASE.$token.'/getMe');

        if (! $me->successful() || ! ($me->json('ok') ?? false)) {
            return ['ok' => false, 'message' => 'Invalid bot token. Check the token from @BotFather.'];
        }

        $username = $me->json('result.username');

        $sent = $this->sendMessage(
            $token,
            $chatId,
            "✅ <b>Talksasa Cloud monitoring connected</b>\n\nBot: @{$username}\nYou will receive platform alerts here."
        );

        if (! $sent) {
            return [
                'ok' => false,
                'message' => 'Bot token is valid but the test message could not be delivered. Confirm the chat ID and that you have started a chat with the bot.',
            ];
        }

        return ['ok' => true, 'username' => $username];
    }

    /**
     * @return list<string>
     */
    private function chunkMessage(string $text, int $limit = 4000): array
    {
        if (strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [$text];
        $buffer = '';

        foreach ($lines as $line) {
            $candidate = $buffer === '' ? $line : $buffer."\n".$line;

            if (strlen($candidate) > $limit) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = $line;
                } else {
                    $chunks[] = substr($line, 0, $limit);
                    $buffer = substr($line, $limit);
                }
            } else {
                $buffer = $candidate;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }
}
