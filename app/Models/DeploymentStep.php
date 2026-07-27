<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StepName;
use App\Enums\StepStatus;
use Database\Factories\DeploymentStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'deployment_id', 'target_hostname', 'step_name', 'subject', 'status',
    'command', 'log', 'sequence', 'started_at', 'finished_at',
])]
class DeploymentStep extends Model
{
    /** @use HasFactory<DeploymentStepFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'step_name' => StepName::class,
            'status' => StepStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * Append to the step log without clobbering concurrent writes from other
     * server pipelines. The log column is append-only by contract.
     */
    public function appendLog(string $line): void
    {
        $stamped = '['.now()->format('H:i:s').'] '.rtrim($line);

        $this->log = $this->log === null || $this->log === ''
            ? $stamped
            : $this->log."\n".$stamped;

        $this->save();
    }

    public function label(): string
    {
        $label = $this->step_name->label();

        return $this->subject === null ? $label : $label.': '.$this->subject;
    }

    public function elapsedSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        // Carbon 3 returns a float here.
        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), true);
    }
}
