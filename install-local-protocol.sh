#!/usr/bin/env bash

echo "--- Installing BedrockProtocol, BedrockData, BedrockBlockUpgradeSchema, BedrockItemUpgradeSchema dependencies from local repositories."
echo "--- This allows you to perform integration tests using PocketMine-MP, without immediately publishing new versions of these libraries."

cp composer.json composer-local-protocol.json
cp composer.lock composer-local-protocol.lock

export COMPOSER=composer-local-protocol.json
composer config repositories.bedrock-protocol path ../deps/BedrockProtocol
composer config repositories.bedrock-data path ../deps/BedrockData
composer config repositories.bedrock-block-upgrade-schema path ../deps/BedrockBlockUpgradeSchema
composer config repositories.bedrock-item-upgrade-schema path ../deps/BedrockItemUpgradeSchema

composer require nethergamesmc/bedrock-protocol:*@dev nethergamesmc/bedrock-data:*@dev axolotl-pm/bedrock-block-upgrade-schema:*@dev axolotl-pm/bedrock-item-upgrade-schema:*@dev

composer install

echo "--- Local dependencies have been successfully installed."
echo "--- This script does not modify composer.json. To go back to the original dependency versions, simply run 'composer install'."

