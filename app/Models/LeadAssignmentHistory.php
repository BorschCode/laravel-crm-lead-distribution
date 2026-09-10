<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAssignmentHistory extends Model
{
    protected $table = 'lead_assignment_history';

    protected $fillable = [
        'lead_id',
        'manager_id',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    /**
     * Get the lead that the history belongs to.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Get the manager that assigned the lead.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }
}
