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

namespace pocketmine\network;

/**
 * Identity key established by the transport layer (network interface) for a connection, independently of the login
 * packet. Transports that authenticate their clients provide this key to bind the login to the connection.
 */
interface TransportIdentityKey{

	/**
	 * Returns the identity bytes in whatever form the transport represents them (NetherNet uses the SHA-256 digest of
	 * the client's public key), or null if the client does not present an identity.
	 */
	public function getKey() : ?string;

	/**
	 * Verifies that the public key from the login chain belongs to the client associated with this key.
	 *
	 * @param string $clientPublicKey DER-encoded public key from the login chain
	 *
	 * @throws TransportIdentityException if the login public key does not belong to the client
	 */
	public function verify(string $clientPublicKey) : void;
}
