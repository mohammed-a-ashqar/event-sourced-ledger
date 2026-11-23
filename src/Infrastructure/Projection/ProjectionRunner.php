<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Projection;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Ledger\EventSourcing\Clock\Clock;
use Ledger\EventSourcing\EventStore;
use Ledger\Infrastructure\Database\Row;
use Ledger\Infrastructure\Database\Timestamp;

/**
 * Feeds projectors from the global event log, batch by batch.
 *
 * Each batch is one transaction: the read-model changes and the new
 * checkpoint commit together, so an event is applied exactly once per
 * projector even if the process dies mid-way. The checkpoint row is locked
 * for the duration of the batch, so two runners never apply the same batch.
 */
final readonly class ProjectionRunner
{
    private const CHECKPOINTS = 'projection_checkpoints';

    /** @var array<string, Projector> */
    private array $projectors;

    /**
     * @param  iterable<Projector>  $projectors
     */
    public function __construct(
        private ConnectionInterface $db,
        private EventStore $events,
        private Clock $clock,
        iterable $projectors,
        private int $batchSize = 500,
    ) {
        $byName = [];

        foreach ($projectors as $projector) {
            $byName[$projector->name()] = $projector;
        }

        $this->projectors = $byName;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->projectors);
    }

    /**
     * Catches every projector up with the event log.
     *
     * @return array<string, int> events applied per projector
     */
    public function runAll(): array
    {
        return array_map(fn (Projector $projector): int => $this->catchUp($projector), $this->projectors);
    }

    /**
     * Empties a read model and replays the whole event log into it.
     *
     * @return int events replayed
     */
    public function rebuild(string $name): int
    {
        $projector = $this->projector($name);

        $this->db->transaction(function () use ($projector): void {
            $projector->reset();
            $this->db->table(self::CHECKPOINTS)->where('projection', $projector->name())->delete();
        });

        return $this->catchUp($projector);
    }

    public function checkpoint(string $name): int
    {
        $row = $this->db->table(self::CHECKPOINTS)->where('projection', $this->projector($name)->name())->first();

        return is_object($row) ? Row::int($row, 'position') : 0;
    }

    private function catchUp(Projector $projector): int
    {
        $applied = 0;

        do {
            $batch = $this->db->transaction(fn (): int => $this->applyNextBatch($projector));
            $applied += $batch;
        } while ($batch === $this->batchSize);

        return $applied;
    }

    private function applyNextBatch(Projector $projector): int
    {
        $position = $this->lockCheckpoint($projector->name());
        $events = $this->events->loadFromPosition($position, $this->batchSize);

        foreach ($events as $envelope) {
            $projector->project($envelope);
            $position = (int) $envelope->position;
        }

        if ($events !== []) {
            $this->db->table(self::CHECKPOINTS)
                ->where('projection', $projector->name())
                ->update(['position' => $position, 'updated_at' => Timestamp::toColumn($this->clock->now())]);
        }

        return count($events);
    }

    private function lockCheckpoint(string $name): int
    {
        $this->db->table(self::CHECKPOINTS)->insertOrIgnore([
            'projection' => $name,
            'position' => 0,
            'updated_at' => Timestamp::toColumn($this->clock->now()),
        ]);

        $row = $this->db->table(self::CHECKPOINTS)->where('projection', $name)->lockForUpdate()->first();

        return is_object($row) ? Row::int($row, 'position') : 0;
    }

    private function projector(string $name): Projector
    {
        return $this->projectors[$name] ?? throw new InvalidArgumentException(sprintf(
            'Unknown projection "%s". Known: %s.',
            $name,
            implode(', ', $this->names()),
        ));
    }
}
