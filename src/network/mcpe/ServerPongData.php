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

namespace pocketmine\network\mcpe;

use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\player\GameMode;
use pocketmine\Server;
use function addcslashes;
use function implode;
use function rtrim;

/**
 * @internal
 */
final class ServerPongData{

	private const SERVER_NAME_FLAG_TRUE = "1";
	private const SERVER_NAME_FLAG_FALSE = "0";

	private function __construct(){
		//NOOP
	}

	public static function build(Server $server, string $name, int $serverId) : string{
		$info = $server->getQueryInformation();

		return implode(";",
			[
				"MCPE",
				rtrim(addcslashes($name, ";"), '\\'),
				ProtocolInfo::CURRENT_PROTOCOL,
				ProtocolInfo::MINECRAFT_VERSION_NETWORK,
				$info->getPlayerCount(),
				$info->getMaxPlayerCount(),
				$serverId,
				$server->getName(),
				match($server->getGamemode()){
					GameMode::SURVIVAL => "Survival",
					GameMode::ADVENTURE => "Adventure",
					default => "Creative"
				},
				self::SERVER_NAME_FLAG_TRUE, //isJoinableThroughServerScreen
				(string) $server->getPort(),
				(string) $server->getPortV6(),
				self::SERVER_NAME_FLAG_FALSE, //isEditorWorld
				//if the server can actually reach Xbox services
				($isOnline = $server->getOnlineMode()) ? self::SERVER_NAME_FLAG_TRUE : self::SERVER_NAME_FLAG_FALSE,
				//inverse of online-mode
				!$isOnline ? self::SERVER_NAME_FLAG_TRUE : self::SERVER_NAME_FLAG_FALSE,
			]) . ";";
	}
}
