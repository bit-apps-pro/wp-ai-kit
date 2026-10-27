<?php

namespace BitApps\AiKit\Audit;

/**
 * A bounded audit trail in one non-autoloaded option — the default for plugins
 * that do not provide a table. Keeps the newest $capacity events; concurrent
 * writes may drop an event, which is why plugins with real traffic should supply
 * a table-backed logger.
 */
final class OptionsAuditLogger implements SearchableAuditLog
{
    private int $capacity;

    public function __construct(private string $option, int $capacity = 200)
    {
        $this->capacity = max(1, $capacity);
    }

    public function record(array $event): void
    {
        $events = $this->all();
        array_unshift($events, $event);
        $this->write(\array_slice($events, 0, $this->capacity));
    }

    public function recent(int $limit = 50): array
    {
        return \array_slice($this->all(), 0, max(0, $limit));
    }

    public function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $rows = array_values(array_filter($this->all(), static fn ($event) => self::matches($event, $filters)));

        return ['rows' => \array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => \count($rows)];
    }

    public function facets(): array
    {
        $facets = array_fill_keys(self::FILTERS, []);
        foreach ($this->all() as $event) {
            foreach (self::FILTERS as $column) {
                $value = $event[$column] ?? null;
                if (null !== $value && '' !== $value && !\in_array($value, $facets[$column], true)) {
                    $facets[$column][] = $value;
                }
            }
        }

        return $facets;
    }

    public function clear(): bool
    {
        $this->write([]);

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    private function all(): array
    {
        $events = get_option($this->option, []);

        return \is_array($events) ? array_values(array_filter($events, 'is_array')) : [];
    }

    /** @param array<int, array<string, mixed>> $events */
    private function write(array $events): void
    {
        if (!add_option($this->option, $events, '', 'no')) {
            update_option($this->option, $events, false);
        }
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $filters
     */
    private static function matches(array $event, array $filters): bool
    {
        foreach (self::FILTERS as $column) {
            if (isset($filters[$column]) && '' !== (string) $filters[$column] && (string) ($event[$column] ?? '') !== (string) $filters[$column]) {
                return false;
            }
        }
        $at = (string) ($event['created_at'] ?? '');
        foreach (['from' => -1, 'to' => 1] as $key => $direction) {
            $bound = $filters[$key] ?? null;
            if (!\is_string($bound) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $bound)) {
                continue;
            }
            // A bare date means the whole day; `to` then runs to its end.
            if (10 === \strlen($bound) && 1 === $direction) {
                $bound .= ' 23:59:59';
            }
            if ($direction * strcmp($at, $bound) > 0) {
                return false;
            }
        }

        return true;
    }
}
