<?php

namespace BitApps\AiKit\Audit;

final class AuditStatus
{
    public const OK = 'ok';

    public const DENIED = 'denied';

    public const ERROR = 'error';

    public const RATE_LIMITED = 'rate_limited';

    public const CONFIRMATION_REQUIRED = 'confirmation_required';
}
