<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'job_name',
        'started_at',
        'finished_at',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
