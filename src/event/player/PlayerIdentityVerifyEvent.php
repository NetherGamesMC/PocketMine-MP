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

namespace pocketmine\event\player;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\Event;
use pocketmine\lang\Translatable;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\TransportIdentityKey;
use pocketmine\player\PlayerInfo;

/**
 * Called after the server has verified the player's login (JWT chain, and the transport identity key if the transport
 * provides one), before the player is created. Plugins can perform their own identity checks here.
 * Cancelling the event disconnects the player with the disconnect reason set.
 */
class PlayerIdentityVerifyEvent extends Event implements Cancellable{
	use CancellableTrait;
	use PlayerDisconnectEventTrait;

	public function __construct(
		private NetworkSession $session,
		private PlayerInfo $playerInfo,
		private bool $authenticated,
		private bool $authRequired,
		private string $clientPublicKey,
		private ?TransportIdentityKey $transportIdentityKey,
		private Translatable|string $disconnectReason,
		private Translatable|string|null $disconnectScreenMessage
	){}

	public function getSession() : NetworkSession{
		return $this->session;
	}

	/**
	 * Returns information about the player. Unlike PlayerPreLoginEvent, the XUID has been verified if
	 * {@link isAuthenticated()} is true. If the player is not authenticated, any unverified Xbox Live
	 * data (including XUID) has been stripped.
	 */
	public function getPlayerInfo() : PlayerInfo{
		return $this->playerInfo;
	}

	/**
	 * Returns whether the player is signed into Xbox Live.
	 */
	public function isAuthenticated() : bool{
		return $this->authenticated;
	}

	/**
	 * Returns whether the server requires Xbox Live authentication for this player, as determined by the server settings
	 * and PlayerPreLoginEvent. If this is true, the player is authenticated; otherwise, they may or may not be authenticated.
	 */
	public function isAuthRequired() : bool{
		return $this->authRequired;
	}

	/**
	 * Returns the DER-encoded public key from the login chain.
	 */
	public function getClientPublicKey() : string{
		return $this->clientPublicKey;
	}

	public function getTransportIdentityKey() : ?TransportIdentityKey{
		return $this->transportIdentityKey;
	}
}
