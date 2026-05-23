<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Author extends Model
{
    use BelongsToSite;

    protected $table = 'authors';

    protected $fillable = [
        'site_id',
        'name',
        'bio',
        'email',
        'avatar',
        'website',
        'social_links',
    ];

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'author_id');
    }

    public function tasksAsAuthor(): HasMany
    {
        return $this->hasMany(Task::class, 'author_id');
    }

    public function tasksAsCustomAuthor(): HasMany
    {
        return $this->hasMany(Task::class, 'custom_author_id');
    }
}
