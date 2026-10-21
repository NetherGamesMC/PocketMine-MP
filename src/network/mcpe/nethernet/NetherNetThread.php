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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\VarInt;
use pmmp\thread\Thread as NativeThread;
use pmmp\thread\ThreadSafeArray;
use pmmp\webrtc\IceServer;
use pocketmine\nethernet\identity\AssertionIdentityVerifier;
use pocketmine\nethernet\identity\SelfSignedIdentityProvider;
use pocketmine\nethernet\identity\ServerIdentity;
use pocketmine\nethernet\info\MutableServerInfoProvider;
use pocketmine\nethernet\info\ServerInfoCodec;
use pocketmine\nethernet\NetherNetException;
use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\server\NetherNetServer;
use pocketmine\nethernet\server\ServerConfiguration;
use pocketmine\nethernet\server\ServerState;
use pocketmine\nethernet\signaling\lan\packet\DiscoveryException;
use pocketmine\nethernet\transport\webrtc\SctpConfiguration;
use pocketmine\nethernet\transport\webrtc\WebRtcTransportFactory;
use pocketmine\network\NetworkInterfaceStartException;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\Thread;
use pocketmine\thread\ThreadCrashException;
use function microtime;
use function ord;
use function time_sleep_until;

/**
 * @internal
 */
final class NetherNetThread extends Thread{

	private const TPS = 100;
	private const TIME_PER_TICK = 1 / self::TPS;

	private const SHUTDOWN_DRAIN_TIMEOUT = 3.0;

	/**
	 * Interval in milliseconds between SCTP heartbeats.
	 */
	private const SCTP_HEARTBEAT_INTERVAL = 750;
	/**
	 * Maximum number of retransmit attempts for SCTP
	 * Clients will be disconnected after this threshold is reached.
	 */
	private const SCTP_MAX_RETRANSMIT_ATTEMPTS = 4;
	/**
	 * Minimum SCTP retransmission timeout in milliseconds.
	 * Clients delay their acknowledgements by up to 200 ms, so anything at or below that retransmits needlessly.
	 */
	private const SCTP_MIN_RETRANSMIT_TIMEOUT = 400;

	protected bool $ready = false;

	protected ?string $startupError = null;

	protected int $consumedBytes = 0;

	/** @phpstan-var ThreadSafeArray<int, NetherNetSignalingFactory> */
	protected ThreadSafeArray $signalingFactories;

	/**
	 * @phpstan-param ThreadSafeArray<int, string>                    $mainToThread
	 * @phpstan-param ThreadSafeArray<int, string>                    $threadToMain
	 * @phpstan-param ThreadSafeArray<int, NetherNetSignalingFactory> $signalingFactories
	 */
	public function __construct(
		protected ThreadSafeLogger $logger,
		protected ThreadSafeArray $mainToThread,
		protected ThreadSafeArray $threadToMain,
		protected int $mtu,
		protected string $identityPem,
		protected string $identityDomain,
		protected bool $allowAnonymous,
		protected NetherNetIceConfiguration $iceConfig,
		ThreadSafeArray $signalingFactories,
		protected ?NetherNetServerConfigurator $configurator,
		protected SleeperHandlerEntry $sleeperEntry
	){
		$this->signalingFactories = $signalingFactories;
	}

	/**
	 * @throws NetworkInterfaceStartException
	 */
	public function startAndWait(int $options = NativeThread::INHERIT_NONE) : void{
		$this->start($options);
		$this->synchronized(function() : void{
			while(!$this->ready && !$this->isTerminated()){
				$this->wait();
			}
		});

		$crashInfo = $this->getCrashInfo();
		if($crashInfo !== null){
			throw new ThreadCrashException("NetherNet failed to start", $crashInfo);
		}
		if($this->startupError !== null){
			throw new NetworkInterfaceStartException($this->startupError);
		}
	}

	public function setConsumedBytes(int $bytes) : void{
		$this->consumedBytes = $bytes;
	}

	protected function onRun() : void{
		\GlobalLogger::set($this->logger);

		$in = new NetherNetChannel($this->mainToThread);
		$out = new NetherNetChannel($this->threadToMain, $this->sleeperEntry->createNotifier());

		$listener = new NetherNetSessionListener($out);
		//nothing is advertised until the main thread has said what to advertise
		$serverInfo = new MutableServerInfoProvider();

		try{
			[$server, $ipcSignaling] = $this->createServer($listener, $out, $serverInfo);
			$server->start();
		}catch(NetherNetException|\InvalidArgumentException $e){
			$this->synchronized(function() use ($e) : void{
				$this->startupError = $e->getMessage();
				$this->ready = true;
				$this->notify();
			});

			return;
		}

		$this->synchronized(function() : void{
			$this->ready = true;
			$this->notify();
		});

		while(!$this->isKilled){
			$start = microtime(true);

			$listener->setConsumedBytes($this->consumedBytes);
			$server->tick();

			$this->handleInbound($in, $server, $ipcSignaling, $serverInfo);

			self::sleepUntilNextTick($start);
		}
		$this->handleInbound($in, $server, $ipcSignaling, $serverInfo);

		$server->initiateShutdown();
		$deadline = microtime(true) + self::SHUTDOWN_DRAIN_TIMEOUT;
		while($server->getState() !== ServerState::STOPPED && microtime(true) < $deadline){
			$start = microtime(true);

			$server->tick();

			self::sleepUntilNextTick($start);
		}

		$server->shutdown();
	}

	/**
	 * @phpstan-return array{NetherNetServer, NetherNetIpcSignaling}
	 *
	 * @throws NetherNetException
	 * @throws \InvalidArgumentException
	 */
	private function createServer(NetherNetSessionListener $listener, NetherNetChannel $out, MutableServerInfoProvider $serverInfo) : array{
		$iceServers = [];
		foreach($this->iceConfig->getServers() as $iceServer){
			$iceServers[] = $iceServer->isTurn()
				? IceServer::turn($iceServer->getHost(), $iceServer->getPort(), $iceServer->getUsername(), $iceServer->getPassword())
				: IceServer::stun($iceServer->getHost(), $iceServer->getPort());
		}

		//this thread is the only user of WebRTC in the process, so the process-wide SCTP timers are ours to set
		(new SctpConfiguration(
			heartbeatInterval: self::SCTP_HEARTBEAT_INTERVAL,
			maxRetransmitAttempts: self::SCTP_MAX_RETRANSMIT_ATTEMPTS,
			minRetransmitTimeout: self::SCTP_MIN_RETRANSMIT_TIMEOUT
		))->applyGlobally();

		$configuration = new ServerConfiguration(
			identityProvider: new SelfSignedIdentityProvider(ServerIdentity::fromPrivateKeyPem($this->identityPem), $this->identityDomain),
			logger: $this->logger,
			identityVerifier: new AssertionIdentityVerifier(allowAnonymous: $this->allowAnonymous),
			transportFactory: new WebRtcTransportFactory(
				iceServers: $iceServers,
				bindAddress: $this->iceConfig->getBindAddress(),
				portRangeBegin: $this->iceConfig->getPortRangeBegin(),
				portRangeEnd: $this->iceConfig->getPortRangeEnd(),
				iceUdpMuxEnabled: $this->iceConfig->isUdpMux(),
				mtu: $this->mtu
			),
			advertisedAddresses: $this->iceConfig->getAdvertisedAddresses()
		);
		$server = NetherNetServer::create($this->configurator?->configure($configuration) ?? $configuration, $listener);

		$context = new NetherNetSignalingContext($serverInfo, $this->logger);
		foreach((array) $this->signalingFactories as $factory){
			$factory->register($server, $context);
		}

		$ipcSignaling = new NetherNetIpcSignaling($out, $this->logger);
		$server->addSignaling($ipcSignaling);

		return [$server, $ipcSignaling];
	}

	private static function sleepUntilNextTick(float $start) : void{
		if(microtime(true) - $start < self::TIME_PER_TICK){
			@time_sleep_until($start + self::TIME_PER_TICK);
		}
	}

	private function handleInbound(NetherNetChannel $in, NetherNetServer $server, NetherNetIpcSignaling $ipcSignaling, MutableServerInfoProvider $serverInfo) : void{
		while(($message = $in->read()) !== null){
			$reader = new ByteBufferReader($message);
			$type = ord($reader->readByteArray(1));

			if($type === NetherNetIpc::M2T_SET_SERVER_DATA){
				try{
					$serverInfo->setServerInfo(ServerInfoCodec::decode($reader->readByteArray($reader->getUnreadLength())));
				}catch(DiscoveryException $e){
					$this->logger->debug("Ignoring unreadable server info from the main thread: " . $e->getMessage());
				}
				continue;
			}

			if($type === NetherNetIpc::M2T_BLOCK_ADDRESS){
				$address = $reader->readByteArray(VarInt::readUnsignedInt($reader));
				$timeout = VarInt::readSignedInt($reader);
				$server->blockAddress($address, $timeout);
				continue;
			}

			if($type === NetherNetIpc::M2T_UNBLOCK_ADDRESS){
				$address = $reader->readByteArray(VarInt::readUnsignedInt($reader));
				$server->unblockAddress($address);
				continue;
			}

			if($type === NetherNetIpc::M2T_OFFER){
				$requestId = VarInt::readUnsignedInt($reader);
				$networkId = $reader->readByteArray(VarInt::readUnsignedInt($reader));
				$clientAddress = $reader->readByteArray(VarInt::readUnsignedInt($reader));
				$ipcSignaling->acceptOffer(
					$requestId,
					$networkId,
					$reader->readByteArray($reader->getUnreadLength()),
					$clientAddress === "" ? null : $clientAddress
				);
				continue;
			}

			if($type === NetherNetIpc::M2T_CANCEL_OFFER){
				$ipcSignaling->cancel(VarInt::readUnsignedInt($reader));
				continue;
			}

			$sessionId = VarInt::readUnsignedInt($reader);
			if($type === NetherNetIpc::M2T_CLOSE_SESSION){
				$server->closeSession($sessionId);
				continue;
			}

			// 0 means no receipt requested; valid receipt IDs are incremented by 1
			$receiptId = VarInt::readUnsignedInt($reader);
			$server->sendPacket(
				$sessionId,
				$reader->readByteArray($reader->getUnreadLength()),
				$type === NetherNetIpc::M2T_SEND_UNRELIABLE ? Reliability::UNRELIABLE : Reliability::RELIABLE,
				$receiptId === 0 ? null : $receiptId - 1
			);
		}
	}

	public function getThreadName() : string{
		return "NetherNet";
	}
}
