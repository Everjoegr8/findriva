<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiQuestion extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'business_id', 'keyword_id', 'question', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(AiAnswerCheck::class);
    }
}
