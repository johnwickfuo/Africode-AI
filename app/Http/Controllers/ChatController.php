<?php

namespace App\Http\Controllers;

use App\Models\ChatLog;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * POST /api/chat — the chat widget's endpoint. Lives in the web middleware
 * group on purpose: the conversation history is session-bound and CSRF
 * applies. Every refusal path is logged and returns a friendly `reply`
 * the widget can display as a normal bot message.
 */
class ChatController extends Controller
{
    public function __invoke(Request $request, ChatService $chat): JsonResponse
    {
        $message = trim((string) $request->input('message', ''));
        $sessionId = $request->session()->getId() ?: $request->ip();

        // 3. Input guard — before any quota is spent.
        $maxLength = (int) config('africode.gemini.max_message_length');
        if ($message === '' || Str::length($message) > $maxLength) {
            $reply = $message === ''
                ? 'Type a question first — e.g. "When do Arsenal play next?"'
                : "That message is a bit long — keep it under {$maxLength} characters.";

            return $this->refusal($sessionId, $message, $reply, ChatLog::STATUS_REJECTED, 422);
        }

        // 1. Per-user limits. Keyed on IP: stable for cookie-less clients too,
        // while the session id (which can rotate) is kept for the logs.
        $limiterKey = $request->ip();
        $minuteKey = "chat-minute:{$limiterKey}";
        $dayKey = "chat-day:{$limiterKey}";

        if (RateLimiter::tooManyAttempts($minuteKey, (int) config('africode.gemini.per_minute'))) {
            return $this->refusal($sessionId, $message,
                "You're sending messages very quickly — give it a few seconds and try again.",
                ChatLog::STATUS_LIMITED, 429);
        }
        if (RateLimiter::tooManyAttempts($dayKey, (int) config('africode.gemini.per_day'))) {
            return $this->refusal($sessionId, $message,
                "You've reached today's chat limit, try again tomorrow.",
                ChatLog::STATUS_LIMITED, 429);
        }
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($dayKey, $chat->secondsUntilLagosMidnight());

        // 2. Global daily Gemini cap.
        if ($chat->isCapped()) {
            return $this->refusal($sessionId, $message, ChatService::CAPPED_REPLY, ChatLog::STATUS_CAPPED, 429);
        }

        $result = $chat->respond($sessionId, $message, $request->session()->get('chat_history', []));
        $request->session()->put('chat_history', $result['history']);

        return response()->json(['reply' => $result['reply']]);
    }

    private function refusal(string $sessionId, string $message, string $reply, string $status, int $httpStatus): JsonResponse
    {
        ChatLog::create([
            'session_id' => $sessionId,
            'message' => Str::limit($message, 600),
            'reply' => $reply,
            'status' => $status,
        ]);

        return response()->json(['reply' => $reply], $httpStatus);
    }
}
