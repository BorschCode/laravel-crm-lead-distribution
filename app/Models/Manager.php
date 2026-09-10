<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Manager extends Model
{
    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function assignmentHistories(): HasMany
    {
        return $this->hasMany(LeadAssignmentHistory::class);
    }
}
