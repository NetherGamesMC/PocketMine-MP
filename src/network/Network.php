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

/**
 * Network-related classes
 */
namespace pocketmine\network;

use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\event\server\NetworkInterfaceUnregisterEvent;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\utils\Utils;
use function base64_encode;
use function get_class;
use function preg_match;
use function spl_object_id;
use function strtolower;
use function time;
use function trim;
use const PHP_INT_MAX;

class Network{
	/** @var NetworkInterface[] */
	private array $interfaces = [];

	/** @var AdvancedNetworkInterface[] */
	private array $advancedInterfaces = [];

	/**
	 * @var Transport[]
	 * @phpstan-var array<string, Transport>
	 */
	private array $transports = [];

	/**
	 * @var Transport[]
	 * @phpstan-var array<string, Transport>
	 */
	private array $activeTransports = [];

	private bool $transportsStarted = false;

	/** @var RawPacketHandler[] */
	private array $rawPacketHandlers = [];

	/**
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $bannedIps = [];

	private BidirectionalBandwidthStatsTracker $bandwidthTracker;
	private string $name;
	private NetworkSessionManager $sessionManager;

	public function __construct(
		private \Logger $logger
	){
		$this->sessionManager = new NetworkSessionManager();
		$this->bandwidthTracker = new BidirectionalBandwidthStatsTracker(5);
	}

	public function getBandwidthTracker() : BidirectionalBandwidthStatsTracker{ return $this->bandwidthTracker; }

	/**
	 * @return NetworkInterface[]
	 */
	public function getInterfaces() : array{
		return $this->interfaces;
	}

	public function getSessionManager() : NetworkSessionManager{
		return $this->sessionManager;
	}

	public function getConnectionCount() : int{
		return $this->sessionManager->getSessionCount();
	}

	public function getValidConnectionCount() : int{
		return $this->sessionManager->getValidSessionCount();
	}

	public function tick() : void{
		foreach($this->interfaces as $interface){
			$interface->tick();
		}

		$this->sessionManager->tick();
	}

	/**
	 * @deprecated Register a {@link Transport} with {@link Network::registerTransport()} instead, so that users can
	 * enable it from pocketmine.yml.
	 *
	 * @throws NetworkInterfaceStartException
	 */
	public function registerInterface(NetworkInterface $interface) : bool{
		return $this->addInterface($interface);
	}

	/**
	 * @throws NetworkInterfaceStartException
	 */
	private function addInterface(NetworkInterface $interface) : bool{
		$ev = new NetworkInterfaceRegisterEvent($interface);
		$ev->call();
		if(!$ev->isCancelled()){
			$interface->start();
			$this->interfaces[$hash = spl_object_id($interface)] = $interface;
			if($interface instanceof AdvancedNetworkInterface){
				$this->advancedInterfaces[$hash] = $interface;
				$interface->setNetwork($this);
				foreach(Utils::stringifyKeys($this->bannedIps) as $ip => $until){
					$interface->blockAddress($ip);
				}
				foreach($this->rawPacketHandlers as $handler){
					$interface->addRawPacketFilter($handler->getPattern());
				}
			}
			$interface->setName($this->name);
			return true;
		}
		return false;
	}

	/**
	 * Registers a transport under the given name.
	 *
	 * @throws \InvalidArgumentException if a transport is already registered under the same name
	 */
	public function registerTransport(string $name, Transport $transport) : void{
		$name = strtolower(trim($name));
		if(isset($this->transports[$name])){
			throw new \InvalidArgumentException("Transport \"$name\" is already registered");
		}
		$this->transports[$name] = $transport;
	}

	/**
	 * Returns the transport registered under the given name, or null if there is none.
	 */
	public function getTransport(string $name) : ?Transport{
		return $this->transports[strtolower(trim($name))] ?? null;
	}

	/**
	 * Returns all registered transports, active or not, indexed by name.
	 *
	 * @return Transport[]
	 * @phpstan-return array<string, Transport>
	 */
	public function getTransports() : array{
		return $this->transports;
	}

	/**
	 * Marks a registered transport to be started on network startup. This must be called before active transports are
	 * started.
	 *
	 * @throws \InvalidArgumentException if no transport is registered under the given name
	 * @throws \LogicException if the active transports have already been started
	 */
	public function activateTransport(string $name) : void{
		$name = strtolower(trim($name));
		if(!isset($this->transports[$name])){
			throw new \InvalidArgumentException("Transport \"$name\" is not registered");
		}
		if($this->transportsStarted){
			throw new \LogicException("Transports have already been started");
		}
		$this->activeTransports[$name] = $this->transports[$name];
	}

	/**
	 * @return Transport[]
	 * @phpstan-return array<string, Transport>
	 */
	public function getActiveTransports() : array{
		return $this->activeTransports;
	}

	/**
	 * Starts all active transports and registers their interfaces.
	 *
	 * @throws NetworkInterfaceStartException if a transport fails to start
	 * @throws \LogicException if active transports have already been started
	 *
	 * @internal
	 */
	public function startActiveTransports(
		PacketBroadcaster $packetBroadcaster,
		EntityEventBroadcaster $entityEventBroadcaster,
		TypeConverter $typeConverter
	) : void{
		if($this->transportsStarted){
			throw new \LogicException("Transports have already been started");
		}
		$this->transportsStarted = true;

		foreach(Utils::stringifyKeys($this->activeTransports) as $name => $transport){
			try{
				foreach($transport->createInterfaces($packetBroadcaster, $entityEventBroadcaster, $typeConverter) as $interface){
					$this->addInterface($interface);
				}
			}catch(NetworkInterfaceStartException $e){
				throw new NetworkInterfaceStartException("Failed to start transport \"$name\": " . $e->getMessage(), 0, $e);
			}
		}
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function unregisterInterface(NetworkInterface $interface) : void{
		if(!isset($this->interfaces[$hash = spl_object_id($interface)])){
			throw new \InvalidArgumentException("Interface " . get_class($interface) . " is not registered on this network");
		}
		(new NetworkInterfaceUnregisterEvent($interface))->call();
		unset($this->interfaces[$hash], $this->advancedInterfaces[$hash]);
		$interface->shutdown();
	}

	/**
	 * Sets the server name shown on each interface Query
	 */
	public function setName(string $name) : void{
		$this->name = $name;
		foreach($this->interfaces as $interface){
			$interface->setName($this->name);
		}
	}

	public function getName() : string{
		return $this->name;
	}

	public function updateName() : void{
		foreach($this->interfaces as $interface){
			$interface->setName($this->name);
		}
	}

	public function sendPacket(string $address, int $port, string $payload) : void{
		foreach($this->advancedInterfaces as $interface){
			$interface->sendRawPacket($address, $port, $payload);
		}
	}

	/**
	 * Blocks an IP address from the main interface. Setting timeout to -1 will block it forever
	 */
	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->bannedIps[$address] = $timeout > 0 ? time() + $timeout : PHP_INT_MAX;
		foreach($this->advancedInterfaces as $interface){
			$interface->blockAddress($address, $timeout);
		}
	}

	public function unblockAddress(string $address) : void{
		unset($this->bannedIps[$address]);
		foreach($this->advancedInterfaces as $interface){
			$interface->unblockAddress($address);
		}
	}

	/**
	 * Registers a raw packet handler on the network.
	 */
	public function registerRawPacketHandler(RawPacketHandler $handler) : void{
		$this->rawPacketHandlers[spl_object_id($handler)] = $handler;

		$regex = $handler->getPattern();
		foreach($this->advancedInterfaces as $interface){
			$interface->addRawPacketFilter($regex);
		}
	}

	/**
	 * Unregisters a previously-registered raw packet handler.
	 */
	public function unregisterRawPacketHandler(RawPacketHandler $handler) : void{
		unset($this->rawPacketHandlers[spl_object_id($handler)]);
	}

	public function processRawPacket(AdvancedNetworkInterface $interface, string $address, int $port, string $packet) : void{
		if(isset($this->bannedIps[$address]) && time() < $this->bannedIps[$address]){
			$this->logger->debug("Dropped raw packet from banned address $address $port");
			return;
		}
		$handled = false;
		foreach($this->rawPacketHandlers as $handler){
			if(preg_match($handler->getPattern(), $packet) === 1){
				try{
					$handled = $handler->handle($interface, $address, $port, $packet);
				}catch(PacketHandlingException $e){
					$handled = true;
					$this->logger->error("Bad raw packet from /$address:$port: " . $e->getMessage());
					$this->blockAddress($address, 600);
					break;
				}
			}
		}
		if(!$handled){
			$this->logger->debug("Unhandled raw packet from /$address:$port: " . base64_encode($packet));
		}
	}
}
