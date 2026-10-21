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

namespace pocketmine\world\sound;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ClientboundUpdateSoundDataPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\network\mcpe\protocol\types\sound\SoundDataEvent;

class RecordStopSound extends ProtocolSound{
	public function __construct(private int $serverSoundHandleId = 0){
	}

	public function encode(Vector3 $pos) : array{
		if($this->protocolId < ProtocolInfo::PROTOCOL_1_26_50){
			return [LevelSoundEventPacket::nonActorSound(LevelSoundEvent::RECORD_NULL, $pos, false)];
		}

		return [
			ClientboundUpdateSoundDataPacket::create($this->serverSoundHandleId, SoundDataEvent::stop())
		];
	}
}
