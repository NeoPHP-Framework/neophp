<?php

declare(strict_types=1);

namespace NeoPHP\Process\Doctor\Model;

class DoctorReport
{
    /**
     * @var array<string, list<CheckResult>>
     */
    protected array $results = [];

    public function add(string $check, CheckResult $result): static
    {
        $this->results[$check][] = $result;

        return $this;
    }

    /**
     * @return array<string, list<CheckResult>> The results, by check name, in the order of the run
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Counts the results, all of them or the ones of a status.
     */
    public function count(?string $status = null): int
    {
        $count = 0;

        foreach ($this->results as $results) {
            foreach ($results as $result) {
                $count += $status === null || $result->getStatus() === $status ? 1 : 0;
            }
        }

        return $count;
    }

    public function hasErrors(): bool
    {
        return $this->count(CheckResult::ERROR) > 0;
    }

    public function hasWarnings(): bool
    {
        return $this->count(CheckResult::WARNING) > 0;
    }

    /**
     * @return array{summary: array<string, int>, checks: array<string, list<array{status: string, label: string, message: string, fix: string|null}>>}
     */
    public function toArray(): array
    {
        $summary = [];

        foreach (CheckResult::STATUSES as $status) {
            $summary[$status] = $this->count($status);
        }

        return [
            'summary' => $summary,
            'checks' => array_map(static fn (array $results): array => array_map(static fn (CheckResult $result): array => $result->toArray(), $results), $this->results),
        ];
    }
}