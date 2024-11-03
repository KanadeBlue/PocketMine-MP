<?php

declare(strict_types=1);

namespace pocketmine\scheduler;

use pocketmine\utils\ObjectSet;
use pocketmine\utils\ReversePriorityQueue;
use pocketmine\player\Player;

class TaskScheduler {
    private bool $enabled = true;

    /** @phpstan-var ReversePriorityQueue<int, TaskHandler> */
    protected ReversePriorityQueue $queue;

    /**
     * @var ObjectSet|TaskHandler[]
     * @phpstan-var ObjectSet<TaskHandler>
     */
    protected ObjectSet $tasks;

    protected int $currentTick = 0;

    /** @var array<string, TaskHandler> */
    private array $playerTasks = [];

    public function __construct(
        private ?string $owner = null
    ) {
        $this->queue = new ReversePriorityQueue();
        $this->tasks = new ObjectSet();
    }

    public function scheduleTask(Task $task): TaskHandler {
        return $this->addTask($task, -1, -1);
    }

    public function scheduleDelayedTask(Task $task, int $delay): TaskHandler {
        return $this->addTask($task, $delay, -1);
    }

    public function scheduleRepeatingTask(Task $task, int $period): TaskHandler {
        return $this->addTask($task, -1, $period);
    }

    public function scheduleDelayedRepeatingTask(Task $task, int $delay, int $period): TaskHandler {
        return $this->addTask($task, $delay, $period);
    }

    public function cancelAllTasks(): void {
        foreach ($this->tasks as $id => $task) {
            $task->cancel();
        }
        $this->tasks->clear();
        while (!$this->queue->isEmpty()) {
            $this->queue->extract();
        }
    }

    public function isQueued(TaskHandler $task): bool {
        return $this->tasks->contains($task);
    }

    /**
     * Check if a task is scheduled for a specific player.
     *
     * @param Player $player
     * @return bool
     */
    public function isScheduledFor(Player $player): bool {
        return isset($this->playerTasks[$player->getUniqueId()->toString()]);
    }

    /**
     * Schedule a task for a player and store the reference.
     *
     * @param Player $player
     * @param Task $task
     * @return TaskHandler
     */
    public function scheduleForPlayer(Player $player, Task $task, int $ticks = 20): TaskHandler {
        if ($this->isScheduledFor($player)) {
            return $this->playerTasks[$player->getUniqueId()->toString()];
        }

        $taskHandler = $this->scheduleRepeatingTask($task, $ticks);
        $this->playerTasks[$player->getUniqueId()->toString()] = $taskHandler;

        return $taskHandler;
    }

    /**
     * Cancel all tasks associated with a specific player.
     *
     * @param Player $player
     */
    public function cancelForPlayer(Player $player): void {
        $uniqueId = $player->getUniqueId()->toString();
        if (isset($this->playerTasks[$uniqueId])) {
            $this->playerTasks[$uniqueId]->cancel();
            unset($this->playerTasks[$uniqueId]);
        }
    }

    private function addTask(Task $task, int $delay, int $period): TaskHandler {
        if (!$this->enabled) {
            throw new \LogicException("Tried to schedule task to disabled scheduler");
        }

        if ($delay <= 0) {
            $delay = -1;
        }

        if ($period <= -1) {
            $period = -1;
        } elseif ($period < 1) {
            $period = 1;
        }

        return $this->handle(new TaskHandler($task, $delay, $period, $this->owner));
    }

    private function handle(TaskHandler $handler): TaskHandler {
        if ($handler->isDelayed()) {
            $nextRun = $this->currentTick + $handler->getDelay();
        } else {
            $nextRun = $this->currentTick;
        }

        $handler->setNextRun($nextRun);
        $this->tasks->add($handler);
        $this->queue->insert($handler, $nextRun);

        return $handler;
    }

    public function shutdown(): void {
        $this->enabled = false;
        $this->cancelAllTasks();
    }

    public function setEnabled(bool $enabled): void {
        $this->enabled = $enabled;
    }

    public function mainThreadHeartbeat(int $currentTick): void {
        if (!$this->enabled) {
            throw new \LogicException("Cannot run heartbeat on a disabled scheduler");
        }
        $this->currentTick = $currentTick;
        while ($this->isReady($this->currentTick)) {
            /** @var TaskHandler $task */
            $task = $this->queue->extract();
            if ($task->isCancelled()) {
                $this->tasks->remove($task);
                continue;
            }
            $task->run();
            if (!$task->isCancelled() && $task->isRepeating()) {
                $task->setNextRun($this->currentTick + $task->getPeriod());
                $this->queue->insert($task, $this->currentTick + $task->getPeriod());
            } else {
                $task->remove();
                $this->tasks->remove($task);
            }
        }
    }

    private function isReady(int $currentTick): bool {
        return !$this->queue->isEmpty() && $this->queue->current()->getNextRun() <= $currentTick;
    }
}