<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasProjects;
use Database\Factories\VisualAutomationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisualAutomation extends Model
{
    /** @use HasFactory<VisualAutomationFactory> */
    use BelongsToClient, HasFactory, HasProjects;

    const STATUS_DRAFT = 'draft';

    const STATUS_LIVE = 'live';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workflow' => 'array',
        ];
    }

    /**
     * @param  Builder<VisualAutomation>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', self::STATUS_LIVE);
    }

    /**
     * Whether the trigger has this type and matches one of the IDs. A trigger
     * without a value matches each ID.
     *
     * @param  array<int, int>  $ids
     */
    public function isTriggeredBy(string $triggerType, array $ids): bool
    {
        $trigger = $this->triggerNode()['data'] ?? [];
        $value = $trigger['value'] ?? null;

        return ($trigger['trigger_type'] ?? null) === $triggerType
            && ($value === null || in_array((int) $value, $ids, true));
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<VisualAutomationEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(VisualAutomationEnrollment::class);
    }

    /**
     * Find the trigger node from the workflow graph.
     *
     * @return array<string, mixed>|null
     */
    public function triggerNode(): ?array
    {
        $nodes = $this->workflow['nodes'] ?? [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'trigger') {
                return $node;
            }
        }

        return null;
    }

    /**
     * Get outgoing edges from a given node.
     *
     * @return array<int, array<string, mixed>>
     */
    public function outgoingEdges(string $nodeId, ?string $sourceHandle = null): array
    {
        $edges = $this->workflow['edges'] ?? [];

        return array_values(array_filter($edges, function (array $edge) use ($nodeId, $sourceHandle) {
            if ($edge['source'] !== $nodeId) {
                return false;
            }

            if ($sourceHandle !== null) {
                return ($edge['sourceHandle'] ?? null) === $sourceHandle;
            }

            return true;
        }));
    }

    /**
     * Find a node by its ID in the workflow.
     *
     * @return array<string, mixed>|null
     */
    public function findNode(string $nodeId): ?array
    {
        $nodes = $this->workflow['nodes'] ?? [];

        foreach ($nodes as $node) {
            if ($node['id'] === $nodeId) {
                return $node;
            }
        }

        return null;
    }
}
