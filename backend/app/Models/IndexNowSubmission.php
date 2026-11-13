<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class IndexNowSubmission extends Model
{
    use BelongsToWorkspace;

    protected $table = 'indexnow_submissions';

    protected $fillable = ['workspace_id', 'business_id', 'submitted_by', 'host', 'url_count', 'urls', 'status_code', 'outcome', 'message'];

    protected function casts(): array
    {
        return ['urls' => 'array'];
    }
}
