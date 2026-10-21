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

namespace pocketmine\network\mcpe\raklib;

use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\Network;
use pocketmine\network\Transport;
use pocketmine\Server;
use pocketmine\ServerProperties;
use raklib\utils\InternetAddress;

/**
 * @deprecated Deprecated in favor of NetherNet as of Minecraft Bedrock 1.26.50
 */
final class RakLibTransport implements Transport{

	public const NAME = "raknet";

	public function __construct(
		private Server $server
	){}

	/**
	 * Returns whether a RakLib interface bound to the given address is registered on the network.
	 *
	 * @internal
	 */
	public static function isListeningOn(Network $network, string $ip, int $port, bool $ipV6) : bool{
		$address = new InternetAddress($ip, $port, $ipV6 ? 6 : 4);
		foreach($network->getInterfaces() as $interface){
			if($interface instanceof RakLibInterface && $interface->getBindAddress()->equals($address)){
				return true;
			}
		}
		return false;
	}

	public function createInterfaces(
		PacketBroadcaster $packetBroadcaster,
		EntityEventBroadcaster $entityEventBroadcaster,
		TypeConverter $typeConverter
	) : array{
		$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_server_raknet_deprecated()));

		$interfaces = [new RakLibInterface($this->server, $this->server->getIp(), $this->server->getPort(), false, $packetBroadcaster, $entityEventBroadcaster, $typeConverter)];
		if($this->server->getConfigGroup()->getConfigBool(ServerProperties::ENABLE_IPV6, true)){
			$interfaces[] = new RakLibInterface($this->server, $this->server->getIpV6(), $this->server->getPortV6(), true, $packetBroadcaster, $entityEventBroadcaster, $typeConverter);
		}
		return $interfaces;
	}
}
