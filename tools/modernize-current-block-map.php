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

namespace pocketmine\tools\modernize_current_block_map;

use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\R12ToCurrentBlockMapEntry;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\utils\Utils;
use Webmozart\PathUtil\Path;
use function defined;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function usort;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @param string[] $argv
 */
function main(array $argv) : int{
	if(!isset($argv[1])){
		echo "Usage: " . PHP_BINARY . " " . __FILE__ . " <path to 'r12_to_current_block_map.nbt' file>\n";
		return 1;
	}
	$file = $argv[1];
	$reader = PacketSerializer::decoder(
		Utils::assumeNotFalse(file_get_contents($file), "Missing required resource file"),
		0,
		new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary(GlobalItemTypeDictionary::getDictionaryProtocol(0)))
	);
	$reader->setProtocolId(0);

	$writer = PacketSerializer::encoder(
		new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary(GlobalItemTypeDictionary::getDictionaryProtocol(0)))
	);
	$writer->setProtocolId(0);

	$entriesRoot = $reader->getNbtRoot();
	$entries = $entriesRoot->getTag();
	if(!($entries instanceof ListTag)) {
		throw new NbtDataException("Expected TAG_List NBT root");
	}

	$newEntries = [];
	foreach($entries->getValue() as $entry) {
		if(!($entry instanceof CompoundTag)) {
			throw new NbtDataException("Expected TAG_Compound NBT entry");
		}
		$old = $entry->getCompoundTag("old");
		$new = $entry->getCompoundTag("new");
		if($old === null || $new === null) {
			throw new NbtDataException("Expected 'old' and 'new' TAG_Compound NBT entries");
		}

		$newEntries[] = new R12ToCurrentBlockMapEntry($old->getString("name"), $old->getShort("val"), $new);
	}

	usort($newEntries, fn(R12ToCurrentBlockMapEntry $a, R12ToCurrentBlockMapEntry $b) => $a <=> $b);

	$nbtWriter = new NetworkNbtSerializer();
	foreach($newEntries as $entry){
		$writer->putString($entry->getId());
		$writer->putLShort($entry->getMeta());
		$writer->put($nbtWriter->write(new TreeRoot($entry->getBlockState())));
	}

	$rootPath = Path::getDirectory($file);
	file_put_contents(Path::join($rootPath, "r12_to_current_block_map.bin"), $writer->getBuffer());

	return 0;
}

if(!defined('pocketmine\_PHPSTAN_ANALYSIS')){
	exit(main($argv));
}
