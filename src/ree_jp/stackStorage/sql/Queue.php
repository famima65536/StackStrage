<?php

namespace ree_jp\stackStorage\sql;

use Closure;
use pocketmine\item\Item;
use pocketmine\scheduler\ClosureTask;
use ree_jp\StackStorage\StackStoragePlugin;

class Queue
{
    static array $queues = [];
    static array $cache = [];
    static array $task = [];

    static function enqueue(string $xuid, Closure $func, bool $isCache = false): void
    {
        if (empty(self::$queues[$xuid])) {
            self::$queues[$xuid] = [];
        }
        if (!$isCache && !empty(self::$cache[$xuid])) {
            foreach (self::$cache[$xuid] as $item) self::addItem($xuid, $item, true);
            unset(self::$cache[$xuid]);
            self::$task[$xuid]->cancel();
            unset(self::$task[$xuid]);
        }
        array_push(self::$queues[$xuid], $func);
        if (empty(self::$queues[$xuid])) $func();
    }

    static function dequeue(string $xuid): void
    {
        if (isset(self::$queues[$xuid]) && !empty(self::$queues[$xuid])) {
            array_shift(self::$queues[$xuid]);
            $next = current(self::$queues[$xuid]);
            if ($next !== false) {
                $next();
            }
        }
    }

    static function add(string $xuid, Item $item): void
    {
        if (self::isEmpty($xuid)) {
            if (empty(self::$cache[$xuid])) {
                self::$cache[$xuid] = [];
                self::$task[$xuid] = StackStoragePlugin::getMain()->getScheduler()->scheduleDelayedTask(new ClosureTask(function (int $currentTick) use ($xuid): void {
                    if (empty(self::$cache[$xuid])) return;
                    foreach (self::$cache[$xuid] as $item) self::addItem($xuid, $item, true);
                    unset(self::$cache[$xuid]);
                    unset(self::$task[$xuid]);
                }), 1 * 20);
            }
            foreach (self::$cache[$xuid] as $key => $cacheItem) {
                if (!$cacheItem instanceof Item) continue;
                if ($item->equals($cacheItem)) self::$cache[$xuid][$key] = $cacheItem->setCount($cacheItem->getCount() + $item->getCount());
            }
        } else self::addItem($xuid, $item);
    }

    static function reduce(string $xuid, Item $item): void
    {
        self::add($xuid, $item->setCount(-$item->getCount()));
    }

    private static function addItem(string $xuid, Item $item, bool $isTask = false): void
    {
        if ($item->getCount() === 0) return;
        self::enqueue($xuid, function () use ($item, $xuid) {
            StackStorageHelper::$instance->getItem($xuid, $item, function (array $rows) use ($item, $xuid) {
                $arrayItem = array_shift($rows);
                if (isset($arrayItem['count'])) $item->setCount($arrayItem['count'] + $item->getCount());
                StackStorageHelper::$instance->setItem($xuid, $item, isset($arrayItem['count']), function () use ($xuid) {
                    Queue::dequeue($xuid);
                });
            });
        }, $isTask);
    }

    static function isEmpty(?string $xuid = null): bool
    {
        if (is_null($xuid)) {
            foreach (self::$queues as $queue) {
                if (!empty($queue)) return false;
            }
        } else {
            if (isset(self::$queues[$xuid]) && !empty(self::$queues[$xuid])) return false;
        }
        return true;
    }
}
