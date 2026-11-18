<?php

namespace WOP\OnlinePayments\Migration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\Serializer\Interfaces\Serializable;
use WOP\OnlinePayments\Core\Infrastructure\Serializer\Serializer;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Task;
defined('ABSPATH') || exit;
/**
 * Core queue task that migrates the previous (2.x) plugin's orders batch by batch. The task keeps its
 * position (and the total) in its own state and reports progress after every batch: the queue saves
 * the task with each progress report, and a run that is stopped (e.g. by the PHP time limit) is put
 * back in the queue as a continuation, not counted as a failed attempt, and resumes from that position.
 */
class OrderMigrationTask extends Task
{
    /** Single store. */
    private const STORE_ID = '1';
    /** Highest progress reported before the end (base points, 100 = 1 %). */
    private const MAX_PROGRESS = 9999;
    /** Position of the next batch. */
    private int $offset;
    /** Number of orders when the migration started (for the progress). */
    private int $total;
    /** Last reported progress in base points; always increases between batches. */
    private int $progress;
    public function __construct(int $offset = 0, int $total = 0, int $progress = 0)
    {
        $this->offset = $offset;
        $this->total = $total;
        $this->progress = $progress;
    }
    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        $migration = new OrderMigration();
        StoreContext::doWithStore(self::STORE_ID, function () use ($migration): void {
            if ($this->total === 0) {
                $this->total = max(1, OrderMigration::totalOrders());
            }
            while (($next = $migration->processBatch($this->offset)) !== null) {
                $this->offset = $next;
                $this->reportBatchProgress();
            }
        });
        $this->reportProgress(100);
    }
    /**
     * Reports the progress after a batch. It must grow after every batch, otherwise the queue treats a
     * stopped run as inactive and counts it as a failed attempt.
     */
    private function reportBatchProgress(): void
    {
        $byPosition = (int) floor($this->offset * 10000 / $this->total);
        $this->progress = min(self::MAX_PROGRESS, max($this->progress + 1, $byPosition));
        $this->reportProgress($this->progress / 100);
    }
    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['offset' => $this->offset, 'total' => $this->total, 'progress' => $this->progress];
    }
    /**
     * @inheritDoc
     */
    public static function fromArray(array $array): Serializable
    {
        return new static((int) ($array['offset'] ?? 0), (int) ($array['total'] ?? 0), (int) ($array['progress'] ?? 0));
    }
    /**
     * @inheritDoc
     */
    public function serialize(): string
    {
        return Serializer::serialize($this->toArray());
    }
    /**
     * @inheritDoc
     */
    public function unserialize(string $serialized): void
    {
        $data = Serializer::unserialize($serialized);
        $this->offset = (int) ($data['offset'] ?? 0);
        $this->total = (int) ($data['total'] ?? 0);
        $this->progress = (int) ($data['progress'] ?? 0);
    }
}
