<?php

namespace BitApps\AiKit\Audit;

/**
 * A bounded audit trail in one non-autoloaded option — the default for plugins
 * that do not provide a table. Keeps the newest $capacity events; concurrent
 * writes may drop an event, which is why plugins with real traffic should supply
 * a table-backed logger.
 */
final class OptionsAuditLogger implements AuditLoggerInterface
{
    private int $capacity;

    public function __construct(private string $option, int $capacity = 200)
    {
        $this->capacity = max(1, $capacity);
    }

    public function record(array $event): void
    {
        $events = get_option($this->option, []);
        if (!\is_array($events)) {
            $events = [];
        }

        array_unshift($events, $event);
        $events = \array_slice($events, 0, $this->capacity);

        if (!add_option($this->option, $events, '', 'no')) {
            update_option($this->option, $events, false);
        }
    }

    public function recent(int $limit = 50): array
    {
        $events = get_option($this->option, []);

        return \is_array($events) ? \array_slice($events, 0, max(0, $limit)) : [];
    }
}
