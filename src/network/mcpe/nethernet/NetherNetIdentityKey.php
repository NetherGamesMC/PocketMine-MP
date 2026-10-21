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

use pocketmine\network\TransportIdentityException;
use pocketmine\network\TransportIdentityKey;
use function hash;
use function hash_equals;

/**
 * SHA-256 digest of the DER-encoded public key presented by the client's NetherNet identity. The key is null when the
 * client connects without presenting an identity.
 */
final class NetherNetIdentityKey implements TransportIdentityKey{

	public function __construct(
		private ?string $key
	){}

	public function getKey() : ?string{
		return $this->key;
	}

	public function verify(string $clientPublicKey) : void{
		if($this->key === null){
			throw new TransportIdentityException("Missing NetherNet identity key");
		}
		if(!hash_equals($this->key, hash("sha256", $clientPublicKey, binary: true))){
			throw new TransportIdentityException("Client public key does not match NetherNet identity key");
		}
	}
}
