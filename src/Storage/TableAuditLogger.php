<?php

namespace BitApps\AiKit\Storage;

use BitApps\AiKit\Audit\OptionsAuditLogger;
use BitApps\AiKit\Audit\SearchableAuditLog;
use BitApps\AiKit\Storage\Model\AuditEvent;

/**
 * The activity log in `{prefix}aikit_audit_log` through the AuditEvent model:
 * paged, filtered and faceted in SQL.
 *
 * A tool call can arrive before the table exists (the first request after an
 * update, or a site without CREATE rights). Until then events go to the bounded
 * option store instead of being lost, and the screens read from there.
 */
final class TableAuditLogger implements SearchableAuditLog
{
    public function __construct(private Tables $tables, private OptionsAuditLogger $fallback) {}

    public function record(array $event): void
    {
        if (!$this->ready()) {
            $this->fallback->record($event);

            return;
        }

        $row = array_intersect_key($event, array_flip(AuditEvent::COLUMNS));
        $this->model()->insert([$row]);
        if (AuditEvent::failed()) {
            $this->fallback->record($event);
        }
    }

    public function recent(int $limit = 50): array
    {
        return $this->search([], 1, $limit)['rows'];
    }

    public function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        if (!$this->ready()) {
            return $this->fallback->search($filters, $page, $perPage);
        }

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $total = $this->filtered($filters)->count();
        $rows = $this->filtered($filters)->orderBy('id')->desc()->skip(($page - 1) * $perPage)->take($perPage)->get();
        if (AuditEvent::failed()) {
            return ['rows' => [], 'total' => 0];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::shape($row->getAttributes());
        }

        return ['rows' => $out, 'total' => (int) $total];
    }

    public function facets(): array
    {
        if (!$this->ready()) {
            return $this->fallback->facets();
        }

        $facets = [];
        foreach (self::FILTERS as $column) {
            $rows = $this->model()->select([$column])->groupBy([$column])->orderBy($column)->take(200)->get();
            $values = [];
            if (!AuditEvent::failed()) {
                foreach ($rows as $row) {
                    $value = $row->getAttribute($column);
                    if (null !== $value && '' !== (string) $value) {
                        $values[] = 'user_id' === $column ? (int) $value : (string) $value;
                    }
                }
            }
            $facets[$column] = $values;
        }

        return $facets;
    }

    public function clear(): bool
    {
        if (!$this->ready()) {
            return $this->fallback->clear();
        }

        $this->model()->where('id', '>', 0)->delete();

        return !AuditEvent::failed();
    }

    /** Delete events older than this many days; returns how many. */
    public function purgeOlderThan(int $days): int
    {
        if (!$this->ready()) {
            return 0;
        }

        $deleted = $this->model()->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - max(0, $days) * DAY_IN_SECONDS))->delete();

        return AuditEvent::failed() ? 0 : (int) $deleted;
    }

    /** @param array<string, mixed> $filters */
    private function filtered(array $filters)
    {
        $query = $this->model();
        foreach (self::FILTERS as $column) {
            if (isset($filters[$column]) && \is_scalar($filters[$column]) && '' !== (string) $filters[$column]) {
                $query->where($column, 'user_id' === $column ? absint($filters[$column]) : (string) $filters[$column]);
            }
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $value = $filters[$key] ?? null;
            if (\is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value)) {
                // A bare date in `to` means the whole day.
                $query->where('created_at', $operator, 'to' === $key && 10 === \strlen($value) ? $value . ' 23:59:59' : $value);
            }
        }

        return $query;
    }

    /**
     * A row as the option store gives it: numbers as numbers.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        foreach (['id', 'user_id', 'item_count', 'duration_ms'] as $number) {
            if (isset($row[$number])) {
                $row[$number] = (int) $row[$number];
            }
        }

        return $row;
    }

    private function model(): AuditEvent
    {
        return AuditEvent::for($this->tables->optionPrefix());
    }

    private function ready(): bool
    {
        return $this->tables->ready(Tables::AUDIT);
    }
}
