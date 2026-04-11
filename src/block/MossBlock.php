<?php

declare(strict_types=1);

namespace pocketmine\block;

class MossBlock extends Opaque {
    public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo){
        parent::__construct($idInfo, $name, $typeInfo);
    }
}