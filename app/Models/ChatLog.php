<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatLog extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_LIMITED = 'limited';

    public const STATUS_CAPPED = 'capped';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'session_id',
        'message',
        'reply',
        'tools_called',
        'gemini_requests',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tools_called' => 'array',
        ];
    }
}
