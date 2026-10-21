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

use pmmp\thread\ThreadSafe;

/**
 * @internal
 */
final class NetherNetIceServer extends ThreadSafe{

	/**
	 * Username and password are empty for STUN server
	 */
	public function __construct(
		private bool $turn,
		private string $host,
		private int $port,
		private string $username,
		private string $password
	){}

	public function isTurn() : bool{ return $this->turn; }

	public function getHost() : string{ return $this->host; }

	public function getPort() : int{ return $this->port; }

	public function getUsername() : string{ return $this->username; }

	public function getPassword() : string{ return $this->password; }
}
