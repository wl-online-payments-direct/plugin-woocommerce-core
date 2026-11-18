<?php

namespace WOP\OnlinePayments\Tasks;

use DateInterval;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Time\TimeProviderInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueService;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Task runner tick listener: enqueues SessionTimeoutTask at most once every 30 minutes, the same way
 * the core periodic listeners enqueue their tasks.
 */
class SessionTimeoutListener
{
    /** Minimum time between two enqueued session timeout tasks. */
    private const INTERVAL = 'PT30M';
    /**
     * Enqueues the session timeout task when the last one was enqueued more than 30 minutes ago.
     */
    public function handle(): void
    {
        if (!$this->canHandle()) {
            return;
        }
        $this->getQueueService()->enqueue(ModuleHelper::moduleKey('_session_timeout'), new SessionTimeoutTask());
    }
    /**
     * @return bool Whether no session timeout task was enqueued in the last 30 minutes.
     */
    protected function canHandle(): bool
    {
        $task = $this->getQueueService()->findLatestByType(SessionTimeoutTask::getClassName());
        $threshold = $this->getTimeProvider()->getCurrentLocalTime()->sub(new DateInterval(self::INTERVAL));
        return !$task || $task->getQueueTimestamp() < $threshold->getTimestamp();
    }
    protected function getQueueService(): QueueService
    {
        return ServiceRegister::getService(QueueService::class);
    }
    protected function getTimeProvider(): TimeProviderInterface
    {
        return ServiceRegister::getService(TimeProviderInterface::class);
    }
}
