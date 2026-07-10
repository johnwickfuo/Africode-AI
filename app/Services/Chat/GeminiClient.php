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
 * so retries are limited to connection errors and 503 "model overloaded"
 * responses (which don't consume quota). If the primary model stays
 * overloaded, one attempt goes to the fallback model before giving up.
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
        } catch (RequestException $exception) {
            if ($exception->response?->status() !== 503 || blank($fallback) || $fallback === $model) {
                throw $exception;
            }
            Log::info('Gemini primary model overloaded, using fallback', [
                'model' => $model, 'fallback' => $fallback,
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
                when: fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->status() === 503),
                throw: true,
            )
            ->post("{$model}:generateContent", $payload)
            ->throw()
            ->json();
    }
}
