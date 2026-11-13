<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class AiAnswerCheck extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'ai_question_id', 'engine', 'model', 'location', 'answer', 'named', 'own_site_cited',
        'excerpt', 'sources', 'searches', 'cost', 'checked_at',
    ];

    protected function casts(): array
    {
        return ['named' => 'boolean', 'own_site_cited' => 'boolean', 'sources' => 'array', 'searches' => 'integer', 'cost' => 'float', 'checked_at' => 'datetime'];
    }
}
