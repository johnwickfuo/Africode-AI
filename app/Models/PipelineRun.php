<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * Run a pipeline step under a logged run: records start, success, or
     * failure (with the error message) and rethrows so queue-level retry
     * and failure handling still apply.
     */
    public static function track(string $jobName, Closure $callback): mixed
    {
        $run = static::create([
            'job_name' => $jobName,
            'started_at' => now(),
            'status' => self::STATUS_RUNNING,
        ]);

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $run->update([
                'finished_at' => now(),
                'status' => self::STATUS_FAILED,
                'error' => Str::limit($exception->getMessage(), 2000),
            ]);

            throw $exception;
        }

        $run->update([
            'finished_at' => now(),
            'status' => self::STATUS_SUCCESS,
        ]);

        return $result;
    }

    public static function lastSuccessfulRun(string $jobName): ?self
    {
        return static::where('job_name', $jobName)
            ->where('status', self::STATUS_SUCCESS)
            ->latest('finished_at')
            ->first();
    }
}
