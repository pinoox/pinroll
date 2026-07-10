<?php

namespace Pinoox\Pinroll\Host;

use Pinoox\Pinroll\Target\TargetChecker;

/**
 * Host connectivity checks (alias for TargetChecker during migration).
 */
final class HostChecker
{
    public function __construct(
        private readonly TargetChecker $checker = new TargetChecker(),
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function checkAll(): array
    {
        return $this->checker->checkAll();
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $hostName, ?string $via = null): array
    {
        $result = $this->checker->check($hostName, $via);
        $result['host'] = $result['target'] ?? $hostName;

        return $result;
    }
}
