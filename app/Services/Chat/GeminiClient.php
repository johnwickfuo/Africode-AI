<?php

namespace App\Services\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal client for the Gemini generateContent REST API (v1beta) with
 * function calling. One call here == one request against the daily quota,
 * so retries are limited to connection errors only.
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

        $model = config('africode.gemini.model');

        $response = Http::baseUrl(config('africode.gemini.base_url'))
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(30)
            ->retry(2, 1000, fn ($exception) => $exception instanceof ConnectionException, throw: true)
            ->post("{$model}:generateContent", [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => $contents,
                'tools' => [['function_declarations' => $functionDeclarations]],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 1024,
                ],
            ])
            ->throw()
            ->json();

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
}
