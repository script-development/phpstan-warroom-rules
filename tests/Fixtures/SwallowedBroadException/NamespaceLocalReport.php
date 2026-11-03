<?php

declare(strict_types = 1);

namespace App\Services\Swallowing\LocalReport;

use Throwable;

function report(): void {}

final class NamespaceLocalReport
{
    public function callsTheSameNamespaceHelper(): void
    {
        try {
            $this->fetch();
        } catch (Throwable) {
            report();
        }
    }

    public function callsAFunctionNothingDeclares(): void
    {
        try {
            $this->fetch();
        } catch (Throwable) {
            reportLater();
        }
    }

    private function fetch(): void {}
}
