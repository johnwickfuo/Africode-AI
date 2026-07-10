<?php

namespace App\Services\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal client for the Gemini generateContent REST API (v1beta) with
 * function calling. One call here == one request against the daily quota,
 * so 503 "model overloaded" responses (which don't consume quota) get two
 * quick retries; a 503 that persists, a timeout, or a connection failure
 * then gets one attempt on the fallback model before giving up. Timeouts
 * are deliberately not retried on the same model — a stalled endpoint that
 * ate 30 s once will usually eat it again, and the user is waiting.
 */
class GeminiClient
{
    /**
     * @param  list<array<string, mixed>>  $contents  Gemini "contents" turns
     * @param  list<array<string, mixed>>  $functionDeclarations
     * @return array{text: ?string, function_calls: list<array{name: string, args: array}>, usage: array{prompt: ?int, completion: ?int, total: ?int}}
     */
    public function generate(string $systemPrompt, array $contents, array $functionDeclarations): array
    {
        $apiKey = config('africode.gemini.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not set — the chat assistant is not configured.');
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $contents,
            'tools' => [['function_declarations' => $functionDeclarations]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 1024,
            ],
        ];

        $model = config('africode.gemini.model');
        $fallback = config('africode.gemini.fallback_model');

        try {
            $response = $this->post($apiKey, $model, $payload);
        } catch (ConnectionException|RequestException $exception) {
            $overloaded = $exception instanceof ConnectionException
                || in_array($exception->response?->status(), [429, 503], true);
            if (! $overloaded || blank($fallback) || $fallback === $model) {
                throw $exception;
            }
            Log::info('Gemini primary model unavailable, using fallback', [
                'model' => $model, 'fallback' => $fallback, 'error' => $exception->getMessage(),
            ]);
            $response = $this->post($apiKey, $fallback, $payload);
        }

        $parts = $response['candidates'][0]['content']['parts'] ?? [];

        $text = null;
        $functionCalls = [];
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text = trim(($text ?? '')."\n".$part['text']);
            }
            if (isset($part['functionCall'])) {
                $functionCalls[] = [
                    'name' => $part['functionCall']['name'] ?? '',
                    'args' => $part['functionCall']['args'] ?? [],
                    // Gemini 3+ returns an opaque signature with each call
                    // and rejects the tool-result round with a 400 unless
                    // it is echoed back on the same part.
                    'thought_signature' => $part['thoughtSignature'] ?? null,
                ];
            }
        }

        return [
            'text' => $text,
            'function_calls' => $functionCalls,
            'usage' => [
                'prompt' => $response['usageMetadata']['promptTokenCount'] ?? null,
                'completion' => $response['usageMetadata']['candidatesTokenCount'] ?? null,
                'total' => $response['usageMetadata']['totalTokenCount'] ?? null,
            ],
        ];
    }

    private function post(string $apiKey, string $model, array $payload): array
    {
        return Http::baseUrl(config('africode.gemini.base_url'))
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(30)
            ->retry(
                [1500, 4000],
                when: fn ($exception) => $exception instanceof RequestException
                    && $exception->response->status() === 503,
                throw: true,
            )
            ->post("{$model}:generateContent", $payload)
            ->throw()
            ->json();
    }
}
