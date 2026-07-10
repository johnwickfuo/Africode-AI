<?php

namespace App\Services\Chat;

use App\Models\ChatLog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates one chat exchange: session history in, Gemini tool-calling
 * loop (max 5 rounds), session history out, everything logged to chat_logs.
 * Also owns the global daily Gemini request cap (cache counter, resets at
 * midnight Africa/Lagos).
 */
class ChatService
{
    public const CAPPED_REPLY = 'The chatbot is resting to stay inside its free daily quota — back tomorrow!';

    private const FALLBACK_REPLY = "I couldn't put together an answer for that — try asking in a different way.";

    private const ERROR_REPLY = 'Something went wrong on my side — please try again in a moment.';

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are the Africode Football AI assistant, embedded in the Africode Football AI web app — a football statistics and match-prediction tool.

        Scope: you only answer questions about football statistics, fixtures, predictions, referees, head-to-head records, player statistics, and the app's own model accuracy, using ONLY the provided tools. Your data covers the top 5 European leagues (Premier League, La Liga, Serie A, Bundesliga, Ligue 1) from season 2023-24 onwards and refreshes nightly. Player stats (goals, assists, shots, cards, xG, xA per match) are being backfilled progressively, newest matches first — so player history may be partial, and if a player tool returns little or no data, say the backfill is still in progress rather than implying the player did not play. When asked about anything outside that scope — other leagues or competitions, seasons before 2023-24, live in-play scores, transfer gossip, or non-football topics — briefly say it is outside your data and mention what you do cover.

        Rules:
        - Always call a tool for factual answers; never invent statistics. If a tool returns an error or no data, say so plainly.
        - Probabilities are model estimates, not guarantees; never present a pick as a sure thing, and never give financial advice.
        - Keep answers short and phone-friendly: plain text, no markdown tables, no headings. Round probabilities to whole percentages.
        - Kickoff times you receive are already in Africa/Lagos time; say so if the user asks about timezones.
        PROMPT;

    public function __construct(
        private GeminiClient $gemini,
        private ChatToolbox $toolbox,
    ) {
    }

    /**
     * Whether the global daily Gemini budget is already spent.
     */
    public function isCapped(): bool
    {
        return Cache::get($this->quotaKey(), 0) >= (int) config('africode.gemini.daily_cap');
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return array{reply: string, status: string, tools: list<string>, history: list<array{role: string, text: string}>, requests: int, usage: array}
     */
    public function respond(string $sessionId, string $message, array $history): array
    {
        $contents = array_map(fn (array $turn) => [
            'role' => $turn['role'],
            'parts' => [['text' => $turn['text']]],
        ], $history);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $toolsCalled = [];
        $usage = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        $requests = 0;
        $reply = null;
        $status = ChatLog::STATUS_OK;

        try {
            for ($round = 0; $round < (int) config('africode.gemini.max_tool_rounds'); $round++) {
                if ($this->isCapped()) {
                    // Cap can be crossed mid-conversation by other users.
                    $reply = self::CAPPED_REPLY;
                    $status = ChatLog::STATUS_CAPPED;
                    break;
                }
                $this->countRequest();
                $requests++;

                $result = $this->gemini->generate(self::SYSTEM_PROMPT, $contents, $this->toolbox->declarations());

                foreach ($usage as $key => $value) {
                    $usage[$key] = $value + ($result['usage'][$key] ?? 0);
                }

                if ($result['function_calls'] === []) {
                    $reply = $result['text'] ?? self::FALLBACK_REPLY;
                    break;
                }

                // Echo the model's calls back, then answer each with its
                // tool result, exactly as the function-calling protocol wants.
                // args must serialize as a JSON object — an empty PHP array
                // would encode as [] and Gemini rejects that with a 400.
                $contents[] = [
                    'role' => 'model',
                    'parts' => array_map(fn (array $call) => ['functionCall' => [
                        'name' => $call['name'],
                        'args' => (object) $call['args'],
                    ]], $result['function_calls']),
                ];
                $responseParts = [];
                foreach ($result['function_calls'] as $call) {
                    $toolsCalled[] = $call['name'];
                    $responseParts[] = [
                        'functionResponse' => [
                            'name' => $call['name'],
                            'response' => ['result' => $this->toolbox->execute($call['name'], (array) $call['args'])],
                        ],
                    ];
                }
                $contents[] = ['role' => 'user', 'parts' => $responseParts];
            }

            $reply ??= self::FALLBACK_REPLY;
        } catch (Throwable $exception) {
            Log::warning('Chat exchange failed', [
                'error' => $exception->getMessage(),
                // The exception message truncates the API's response body;
                // log it in full — it names the exact rejected field.
                'response' => $exception instanceof RequestException
                    ? $exception->response?->body()
                    : null,
            ]);
            $reply = self::ERROR_REPLY;
            $status = ChatLog::STATUS_ERROR;
        }

        if ($status === ChatLog::STATUS_OK) {
            $history[] = ['role' => 'user', 'text' => $message];
            $history[] = ['role' => 'model', 'text' => $reply];
            $history = array_slice($history, -1 * (int) config('africode.gemini.history_messages'));
        }

        ChatLog::create([
            'session_id' => $sessionId,
            'message' => $message,
            'reply' => $reply,
            'tools_called' => $toolsCalled ?: null,
            'gemini_requests' => $requests,
            'prompt_tokens' => $usage['prompt'] ?: null,
            'completion_tokens' => $usage['completion'] ?: null,
            'total_tokens' => $usage['total'] ?: null,
            'status' => $status,
        ]);

        return [
            'reply' => $reply,
            'status' => $status,
            'tools' => $toolsCalled,
            'history' => $history,
            'requests' => $requests,
            'usage' => $usage,
        ];
    }

    private function countRequest(): void
    {
        $key = $this->quotaKey();
        Cache::add($key, 0, $this->secondsUntilLagosMidnight());
        Cache::increment($key);
    }

    public function secondsUntilLagosMidnight(): int
    {
        return max(60, (int) now('Africa/Lagos')->diffInSeconds(
            Carbon::now('Africa/Lagos')->endOfDay(),
        ) + 1);
    }

    private function quotaKey(): string
    {
        return 'gemini-quota:'.now('Africa/Lagos')->toDateString();
    }
}
