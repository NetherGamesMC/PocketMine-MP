<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperNotifier;
use function strlen;

/**
 * @internal
 */
final class NetherNetChannel{

	private int $totalBytes = 0;

	/**
	 * @phpstan-param ThreadSafeArray<int, string> $buffer
	 */
	public function __construct(
		private readonly ThreadSafeArray $buffer,
		private readonly ?SleeperNotifier $notifier = null
	){}

	public function read() : ?string{
		$message = $this->buffer->shift();
		if($message !== null){
			$this->totalBytes += strlen($message);
		}

		return $message;
	}

	public function write(string $message) : void{
		$this->totalBytes += strlen($message);
		$this->buffer[] = $message;
		$this->notifier?->wakeupSleeper();
	}

	public function count() : int{
		return $this->buffer->count();
	}

	/**
	 * Total bytes that have passed through this end of the channel.
	 *
	 * Each thread holds its own instance over the shared buffer, so neither end can measure the buffer's
	 * depth in bytes alone. Subtracting the reading end's total from the writing end's gives it.
	 */
	public function getTotalBytes() : int{
		return $this->totalBytes;
	}
}
