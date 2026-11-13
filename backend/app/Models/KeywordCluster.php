<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordCluster extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'business_id', 'name', 'intent', 'theme'];

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }
}
