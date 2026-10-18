<?php

namespace BitApps\AiKit\Audit;

final class NullAuditLogger implements AuditLoggerInterface
{
    public function record(array $event): void {}

    public function recent(int $limit = 50): array
    {
        return [];
    }
}
