<?php

declare(strict_types = 1);

namespace App\Imports;

final class PlainAccessFallback
{
    /**
     * @var array<string, string>
     */
    private array $config = [];

    private ?string $label = null;

    /**
     * @param array<string, mixed> $row
     */
    public function values(array $row): string
    {
        // Coalesce on an array offset / a property is the idiomatic
        // absent-key default — no helper call, nothing to hide.
        $city = $row['city'] ?? '';
        $label = $this->label ?? '';

        return $this->config['name'] ?? $city . $label;
    }
}
