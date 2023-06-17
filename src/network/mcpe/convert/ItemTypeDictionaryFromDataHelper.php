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

namespace pocketmine\network\mcpe\convert;

use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function str_replace;

final class ItemTypeDictionaryFromDataHelper{

	private const PATHS = [
		ProtocolInfo::CURRENT_PROTOCOL => "",
		ProtocolInfo::PROTOCOL_1_19_80 => "-1.19.80",

		ProtocolInfo::PROTOCOL_1_19_70 => "-1.19.70",

		ProtocolInfo::PROTOCOL_1_19_63 => "-1.19.63",
		ProtocolInfo::PROTOCOL_1_19_60 => "-1.19.63",

		ProtocolInfo::PROTOCOL_1_19_50 => "-1.19.50",

		ProtocolInfo::PROTOCOL_1_19_40 => "-1.19.40",
		ProtocolInfo::PROTOCOL_1_19_30 => "-1.19.40",
		ProtocolInfo::PROTOCOL_1_19_21 => "-1.19.40",
		ProtocolInfo::PROTOCOL_1_19_20 => "-1.19.40",
		ProtocolInfo::PROTOCOL_1_19_10 => "-1.19.40",

		ProtocolInfo::PROTOCOL_1_19_0 => "-1.19.0",

		ProtocolInfo::PROTOCOL_1_18_30 => "-1.18.30",

		ProtocolInfo::PROTOCOL_1_18_10 => "-1.18.10",
	];

	public static function loadFromProtocolId(int $protocolId) : ItemTypeDictionary{
		return self::loadFromString(Filesystem::fileGetContents(str_replace(".json", self::PATHS[$protocolId] . ".json", BedrockDataFiles::REQUIRED_ITEM_LIST_JSON)));
	}

	public static function loadFromString(string $data) : ItemTypeDictionary{
		$table = json_decode($data, true);
		if(!is_array($table)){
			throw new AssumptionFailedError("Invalid item list format");
		}

		$params = [];
		foreach($table as $name => $entry){
			if(!is_array($entry) || !is_string($name) || !isset($entry["component_based"], $entry["runtime_id"]) || !is_bool($entry["component_based"]) || !is_int($entry["runtime_id"])){
				throw new AssumptionFailedError("Invalid item list format");
			}
			$params[] = new ItemTypeEntry($name, $entry["runtime_id"], $entry["component_based"]);
		}
		return new ItemTypeDictionary($params);
	}
}
