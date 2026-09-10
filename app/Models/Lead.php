<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'manager_id',
        'status',
    ];

    protected $casts = [
        'status' => LeadStatus::class,
    ];

    /**
     * Get the manager that owns the lead.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    /**
     * Get all of the assignment histories for the lead.
     */
    public function assignmentHistories(): HasMany
    {
        return $this->hasMany(LeadAssignmentHistory::class);
    }
}
