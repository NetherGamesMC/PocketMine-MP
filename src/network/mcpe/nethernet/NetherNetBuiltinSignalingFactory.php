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
use pocketmine\nethernet\signaling\http\HttpSignaling;
use pocketmine\nethernet\signaling\http\ReverseProxy;
use pocketmine\nethernet\signaling\http\TlsConfiguration;
use pocketmine\nethernet\signaling\lan\LanSignaling;
use pocketmine\nethernet\signaling\SignalingRegistry;
use pocketmine\nethernet\utils\InternetAddress;
use function array_values;
use function count;

/**
 * @internal
 */
final class NetherNetBuiltinSignalingFactory extends NetherNetSignalingFactory{

	/** @phpstan-var ThreadSafeArray<int, string>|null */
	private ?ThreadSafeArray $reverseProxyNetworks;

	/**
	 * @phpstan-param list<string>|null $reverseProxyNetworks
	 *
	 * @throws \InvalidArgumentException if the header or a network is invalid
	 */
	public function __construct(
		private string $httpBindAddress,
		private int $httpPort,
		private ?string $httpBindAddressV6,
		private ?string $tlsCertFile,
		private ?string $tlsKeyFile,
		private ?string $tlsPassphrase,
		?array $reverseProxyNetworks,
		private string $reverseProxyHeader,
		private string $lanBindAddress,
		private int $lanPort,
		private int $networkId
	){
		if($reverseProxyNetworks !== null){
			self::createReverseProxy($reverseProxyHeader, $reverseProxyNetworks);
		}
		$this->reverseProxyNetworks = $reverseProxyNetworks === null ? null : ThreadSafeArray::fromArray($reverseProxyNetworks);
	}

	/**
	 * @phpstan-param list<string> $networks
	 *
	 * @throws \InvalidArgumentException
	 */
	private static function createReverseProxy(string $header, array $networks) : ReverseProxy{
		if(count($networks) === 0){
			throw new \InvalidArgumentException("At least one trusted reverse proxy address or CIDR block is required");
		}

		return new ReverseProxy($header, $networks);
	}

	public function register(SignalingRegistry $registry, NetherNetSignalingContext $context) : void{
		$tls = $this->tlsCertFile !== null && $this->tlsKeyFile !== null ? new TlsConfiguration($this->tlsCertFile, $this->tlsKeyFile, $this->tlsPassphrase) : null;

		$registry->addSignaling($this->createHttpSignaling($context, new InternetAddress($this->httpBindAddress, $this->httpPort, 4), $tls));
		if($this->httpBindAddressV6 !== null){
			$registry->addSignaling($this->createHttpSignaling($context, new InternetAddress($this->httpBindAddressV6, $this->httpPort, 6), $tls), optional: true);
		}
		$registry->addSignaling(new LanSignaling(
			$context->getServerInfoProvider(),
			$this->networkId,
			new InternetAddress($this->lanBindAddress, $this->lanPort, 4)
		), optional: true);
	}

	private function createHttpSignaling(NetherNetSignalingContext $context, InternetAddress $bindAddress, ?TlsConfiguration $tls) : HttpSignaling{
		return new HttpSignaling(
			$bindAddress,
			$tls,
			$context->getServerInfoProvider(),
			$this->reverseProxyNetworks !== null ? self::createReverseProxy($this->reverseProxyHeader, array_values((array) $this->reverseProxyNetworks)) : null
		);
	}
}
