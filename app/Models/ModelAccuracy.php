<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelAccuracy extends Model
{
    protected $table = 'model_accuracy';

    protected $fillable = [
        'market',
        'line_bucket',
        'total_settled',
        'hits',
        'hit_rate',
        'avg_probability',
        'calibration_gap',
    ];

    protected function casts(): array
    {
        return [
            'hit_rate' => 'float',
            'avg_probability' => 'float',
            'calibration_gap' => 'float',
        ];
    }
}
