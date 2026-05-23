<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prompt extends Model
{
    use BelongsToSite;

    protected $table = 'prompts';

    protected $fillable = [
        'site_id',
        'name',
        'type',
        'content',
        'variables',
    ];

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'prompt_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'prompt_id');
    }
}
