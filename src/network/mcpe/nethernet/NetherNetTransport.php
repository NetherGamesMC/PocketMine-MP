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

use pmmp\thread\ThreadSafeArray;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\nethernet\identity\IdentityException;
use pocketmine\nethernet\identity\PublicKey;
use pocketmine\nethernet\identity\ServerIdentity;
use pocketmine\nethernet\signaling\http\ReverseProxy;
use pocketmine\nethernet\signaling\lan\LanSignaling;
use pocketmine\nethernet\transport\webrtc\WebRtcTransportFactory;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\NetworkInterfaceStartException;
use pocketmine\network\Transport;
use pocketmine\Server;
use pocketmine\ServerConfigGroup;
use pocketmine\ServerProperties;
use pocketmine\utils\Filesystem;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use pocketmine\YmlServerProperties as Yml;
use Symfony\Component\Filesystem\Path;
use function chmod;
use function dirname;
use function extension_loaded;
use function get_debug_type;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function phpversion;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;
use function umask;
use function unpack;
use function version_compare;
use const PHP_INT_MAX;

final class NetherNetTransport implements Transport{

	public const NAME = "nethernet";

	private const MIN_WEBRTC_VERSION = "0.3.1";
	private const MAX_WEBRTC_VERSION = "0.4.0";

	private const TLS_PASSPHRASE_FILE_PREFIX = "file:";

	/**
	 * @var NetherNetSignalingFactory[]
	 * @phpstan-var list<NetherNetSignalingFactory>
	 */
	private array $signaling = [];

	private ?NetherNetServerConfigurator $configurator = null;

	private bool $started = false;

	public function __construct(
		private Server $server
	){}

	/**
	 * @throws \LogicException if NetherNet has already started
	 */
	public function addSignaling(NetherNetSignalingFactory $factory) : void{
		if($this->started){
			throw new \LogicException("Signaling must be registered before NetherNet starts");
		}
		$this->signaling[] = $factory;
	}

	/**
	 * @throws \LogicException if NetherNet has already started
	 */
	public function setConfigurator(?NetherNetServerConfigurator $configurator) : void{
		if($this->started){
			throw new \LogicException("The configurator must be set before NetherNet starts");
		}
		$this->configurator = $configurator;
	}

	private function getUnavailableReason() : ?string{
		if(!extension_loaded("webrtc")){
			return "the axolotl-pm/ext-webrtc extension is not loaded";
		}
		$version = phpversion("webrtc");
		if($version !== false && (version_compare($version, self::MIN_WEBRTC_VERSION, "<") || version_compare($version, self::MAX_WEBRTC_VERSION, ">="))){
			return "ext-webrtc ^" . self::MIN_WEBRTC_VERSION . " is required (found $version)";
		}
		return null;
	}

	public function createInterfaces(
		PacketBroadcaster $packetBroadcaster,
		EntityEventBroadcaster $entityEventBroadcaster,
		TypeConverter $typeConverter
	) : array{
		$unavailableReason = $this->getUnavailableReason();
		if($unavailableReason !== null){
			//other transports may still be usable, Server fails if none are
			$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_server_transport_unavailable(self::NAME, $unavailableReason)));
			return [];
		}
		$configGroup = $this->server->getConfigGroup();

		$identityKeyFile = $configGroup->getPropertyString(Yml::TRANSPORT_NETHERNET_KEY_FILE, "nethernet.key");
		$identityPem = $this->loadOrCreateIdentityPem($identityKeyFile);
		try{
			$networkId = self::networkIdOf(ServerIdentity::fromPrivateKeyPem($identityPem)->getPublicKey());
		}catch(IdentityException $e){
			throw new NetworkInterfaceStartException("NetherNet identity key $identityKeyFile is invalid", 0, $e);
		}

		$signaling = $this->signaling;
		try{
			$bindAddress = $this->server->getIp();
			$iceConfig = NetherNetIceConfiguration::parse(
				$configGroup->getProperty(Yml::TRANSPORT_NETHERNET_ICE_SERVERS),
				$configGroup->getProperty(Yml::TRANSPORT_NETHERNET_PORT_RANGE),
				$configGroup->getPropertyBool(Yml::TRANSPORT_NETHERNET_ICE_UDP_MUX, false),
				$bindAddress === "0.0.0.0" ? null : $bindAddress,
				$configGroup->getProperty(Yml::TRANSPORT_NETHERNET_ADVERTISE_ADDRESSES)
			);
			$mtu = $configGroup->getPropertyInt(Yml::TRANSPORT_NETHERNET_MTU, WebRtcTransportFactory::DEFAULT_MTU);
			if($mtu < WebRtcTransportFactory::MIN_MTU || $mtu > WebRtcTransportFactory::MAX_MTU){
				throw new \InvalidArgumentException("mtu must be between " . WebRtcTransportFactory::MIN_MTU . " and " . WebRtcTransportFactory::MAX_MTU . ", got $mtu");
			}
			if($configGroup->getPropertyBool(Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_ENABLED, true)){
				$signaling = [$this->createBuiltinSignaling($configGroup, $networkId), ...$signaling];
			}else{
				$this->server->getLogger()->notice($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_server_nethernet_builtinSignalingDisabled()));
			}
		}catch(\InvalidArgumentException $e){
			throw new NetworkInterfaceStartException("Invalid NetherNet settings in pocketmine.yml: " . $e->getMessage(), 0, $e);
		}
		if($iceConfig->isUdpMux() && $iceConfig->getPortRangeBegin() === null){
			$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_server_nethernet_udpMuxWithoutPortRange(Yml::TRANSPORT_NETHERNET_ICE_UDP_MUX)));
		}

		$this->started = true;

		return [new NetherNetInterface(
			$this->server,
			$identityPem,
			$this->identityDomain($configGroup),
			$iceConfig,
			$mtu,
			ThreadSafeArray::fromArray($signaling),
			$this->configurator,
			$packetBroadcaster,
			$entityEventBroadcaster,
			$typeConverter
		)];
	}

	private function identityDomain(ServerConfigGroup $configGroup) : string{
		$domain = $configGroup->getPropertyString(Yml::TRANSPORT_NETHERNET_IDENTITY_DOMAIN, "");
		if($domain === ""){
			$domain = TextFormat::clean($this->server->getMotd());
		}

		return $domain !== "" ? $domain : $this->server->getName();
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function createBuiltinSignaling(ServerConfigGroup $configGroup, int $networkId) : NetherNetBuiltinSignalingFactory{
		[$certificate, $key, $passphrase] = $this->parseTls($configGroup);

		$httpPort = $configGroup->getPropertyInt(Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_PORT, 0);
		if($httpPort < 0 || $httpPort > 65535){
			throw new \InvalidArgumentException(Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_PORT . " must be between 0 and 65535, got $httpPort");
		}

		return new NetherNetBuiltinSignalingFactory(
			httpBindAddress: $this->server->getIp(),
			httpPort: $httpPort === 0 ? $this->server->getPort() : $httpPort,
			httpBindAddressV6: $configGroup->getConfigBool(ServerProperties::ENABLE_IPV6, true) ? $this->server->getIpV6() : null,
			tlsCertFile: $certificate,
			tlsKeyFile: $key,
			tlsPassphrase: $passphrase,
			reverseProxyNetworks: $configGroup->getPropertyBool(Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_REVERSE_PROXY_ENABLED, false)
				? self::parseReverseProxyNetworks($configGroup->getProperty(Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_REVERSE_PROXY_TRUSTED_IPS))
				: null,
			reverseProxyHeader: $configGroup->getPropertyString(Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_REVERSE_PROXY_HEADER, ReverseProxy::HEADER_FORWARDED_FOR),
			lanBindAddress: "0.0.0.0",
			lanPort: LanSignaling::DEFAULT_PORT, //clients look for LAN games on this port
			networkId: $networkId
		);
	}

	/**
	 * @phpstan-return list<string>
	 *
	 * @throws \InvalidArgumentException
	 */
	private static function parseReverseProxyNetworks(mixed $trustedIps) : array{
		if($trustedIps === null){
			$trustedIps = [];
		}elseif(!is_array($trustedIps)){
			throw new \InvalidArgumentException("Trusted IPs must be a list of addresses or CIDR blocks, got " . get_debug_type($trustedIps));
		}

		$networks = [];
		foreach(Utils::promoteKeys($trustedIps) as $index => $entry){
			if(!is_string($entry) || $entry === ""){
				throw new \InvalidArgumentException("Trusted IPs entry $index must be an address or CIDR block, got " . get_debug_type($entry));
			}
			$networks[] = $entry;
		}

		return $networks;
	}

	/**
	 * @phpstan-return array{?string, ?string, ?string}
	 *
	 * @throws \InvalidArgumentException
	 */
	private function parseTls(ServerConfigGroup $configGroup) : array{
		$certificate = $configGroup->getPropertyString(Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_TLS_CERTIFICATE, "");
		$key = $configGroup->getPropertyString(Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_TLS_KEY, "");
		if(($certificate === "") !== ($key === "")){
			throw new \InvalidArgumentException(Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_TLS_CERTIFICATE . " and " . Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_TLS_KEY . " must be set together");
		}
		if($certificate === ""){
			$this->server->getLogger()->notice($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_server_nethernet_tls_disabled()));
			return [null, null, null];
		}

		$passphrase = $configGroup->getPropertyString(Yml::TRANSPORT_NETHERNET_BUILTIN_SIGNALING_TLS_PASSPHRASE, "");
		if(str_starts_with($passphrase, self::TLS_PASSPHRASE_FILE_PREFIX)){
			$passphraseFile = Path::makeAbsolute(substr($passphrase, strlen(self::TLS_PASSPHRASE_FILE_PREFIX)), $this->server->getDataPath());
			try{
				$passphrase = rtrim(Filesystem::fileGetContents($passphraseFile), "\r\n");
			}catch(\RuntimeException $e){
				throw new \InvalidArgumentException("Could not read the TLS passphrase file: " . $e->getMessage(), 0, $e);
			}
		}

		$this->server->getLogger()->notice($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_server_nethernet_tls_enabled()));
		return [
			Path::makeAbsolute($certificate, $this->server->getDataPath()),
			Path::makeAbsolute($key, $this->server->getDataPath()),
			$passphrase === "" ? null : $passphrase
		];
	}

	/**
	 * @throws NetworkInterfaceStartException
	 */
	private function loadOrCreateIdentityPem(string $identityKeyFile) : string{
		$path = Path::makeAbsolute($identityKeyFile, $this->server->getDataPath());

		if(is_file($path)){
			try{
				return Filesystem::fileGetContents($path);
			}catch(\RuntimeException $e){
				throw new NetworkInterfaceStartException(
					"Could not read NetherNet identity key $path: " . $e->getMessage(),
					0,
					$e
				);
			}
		}

		try{
			$pem = ServerIdentity::generate()->exportPrivateKeyPem();
		}catch(IdentityException $e){
			throw new NetworkInterfaceStartException("Could not create a NetherNet identity: " . $e->getMessage(), 0, $e);
		}

		$directory = dirname($path);
		if(!is_dir($directory)){
			@mkdir($directory, 0700, true);
		}

		$previousUmask = umask(0077);
		try{
			Filesystem::safeFilePutContents($path, $pem);
		}catch(\RuntimeException $e){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_server_nethernet_identityKeySaveFailed($path)));
		}finally{
			umask($previousUmask);
		}
		@chmod($path, 0600);

		return $pem;
	}

	/**
	 * Derives a deterministic 64-bit positive integer network ID from the identity public key.
	 */
	private static function networkIdOf(PublicKey $publicKey) : int{
		$digest = $publicKey->getDigest();
		$id = unpack("P", substr($digest, 0, 8));

		return $id === false ? 1 : (($id[1] & PHP_INT_MAX) | 1);
	}
}
