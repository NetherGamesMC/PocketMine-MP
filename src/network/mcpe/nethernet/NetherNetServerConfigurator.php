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
use pocketmine\nethernet\server\ServerConfiguration;

/**
 * Lets a plugin change how the NetherNet server is configured, for example to verify client tokens with its own
 * {@link \pocketmine\nethernet\identity\TokenVerifier} or to adjust connection limits.
 *
 * @see NetherNetTransport::setConfigurator()
 */
abstract class NetherNetServerConfigurator extends ThreadSafe{

	/**
	 * Runs on the NetherNet thread before the server is created.
	 *
	 * @param ServerConfiguration $defaults What PocketMine-MP would run the server with.
	 *
	 * @throws \InvalidArgumentException
	 */
	abstract public function configure(ServerConfiguration $defaults) : ServerConfiguration;
}
