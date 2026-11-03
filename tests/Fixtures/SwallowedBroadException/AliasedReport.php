<?php

declare(strict_types = 1);

namespace App\Services\Swallowing\Helpers {
    function cleanup(): void {}
}

namespace App\Services\Swallowing\Aliased {
    use RuntimeException;
    use Throwable;

    use function App\Services\Swallowing\Helpers\cleanup as report;
    use function report as recordFailure;

    final class AliasedReport
    {
        public function anAliasOfAnotherFunctionNamedReport(): void
        {
            try {
                $this->fetch();
            } catch (Throwable) {
                report();
            }
        }

        public function anAliasOfTheGlobalHelper(): void
        {
            try {
                $this->fetch();
            } catch (Throwable) {
                recordFailure(new RuntimeException('Fetch failed.'));
            }
        }

        private function fetch(): void {}
    }
}

namespace App\Services\Swallowing\FallsBackToGlobal {
    use RuntimeException;
    use Throwable;

    final class FallsBackToGlobal
    {
        public function anUnqualifiedCallWithNoLocalFunction(): void
        {
            try {
                $this->fetch();
            } catch (Throwable) {
                report(new RuntimeException('Fetch failed.'));
            }
        }

        private function fetch(): void {}
    }
}
