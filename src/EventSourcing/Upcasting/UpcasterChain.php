<?php

declare(strict_types=1);

namespace Ledger\EventSourcing\Upcasting;

use InvalidArgumentException;

/**
 * Applies upcasters step by step (v1 -> v2 -> v3 ...) until the payload
 * reaches the version the event class currently understands.
 */
final class UpcasterChain
{
    /** @var array<string, array<int, Upcaster>> */
    private array $upcasters = [];

    /**
     * @param  iterable<Upcaster>  $upcasters
     */
    public function __construct(iterable $upcasters = [])
    {
        foreach ($upcasters as $upcaster) {
            $this->add($upcaster);
        }
    }

    public function add(Upcaster $upcaster): void
    {
        $type = $upcaster->eventType();
        $from = $upcaster->fromVersion();

        if (isset($this->upcasters[$type][$from])) {
            throw new InvalidArgumentException(sprintf('An upcaster for "%s" v%d is already registered.', $type, $from));
        }

        $this->upcasters[$type][$from] = $upcaster;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upcast(string $eventType, int $fromVersion, int $toVersion, array $payload): array
    {
        if ($fromVersion > $toVersion) {
            throw new InvalidArgumentException(sprintf(
                'Event "%s" is stored as v%d but this code only understands up to v%d.',
                $eventType,
                $fromVersion,
                $toVersion,
            ));
        }

        for ($version = $fromVersion; $version < $toVersion; $version++) {
            $upcaster = $this->upcasters[$eventType][$version] ?? throw MissingUpcaster::for($eventType, $version);
            $payload = $upcaster->upcast($payload);
        }

        return $payload;
    }
}
