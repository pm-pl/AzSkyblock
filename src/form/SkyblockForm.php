<?php

declare(strict_types=1);

namespace phuongaz\azskyblock\form;

use dktapps\pmforms\CustomFormResponse;
use dktapps\pmforms\element\Dropdown;
use dktapps\pmforms\element\Input;
use dktapps\pmforms\element\Label;
use dktapps\pmforms\element\Toggle;
use dktapps\pmforms\MenuOption;
use faz\common\form\AsyncForm;
use faz\common\form\FastForm;
use Generator;
use phuongaz\azskyblock\AzSkyBlock;
use phuongaz\azskyblock\handler\island\IslandTeleportEvent;
use phuongaz\azskyblock\island\components\Area;
use phuongaz\azskyblock\island\components\Warp;
use phuongaz\azskyblock\island\Island;
use phuongaz\azskyblock\utils\LanguageUtils;
use phuongaz\azskyblock\utils\Util;
use phuongaz\azskyblock\world\custom\CustomIsland;
use phuongaz\azskyblock\world\custom\IslandPool;
use phuongaz\azskyblock\world\WorldUtils;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\Position;
use SOFe\AwaitGenerator\Await;

class SkyblockForm extends AsyncForm {

    public function __construct(Player $player) {
        parent::__construct($player);
    }

    public function main(): Generator {
        $provider = AzSkyBlock::getInstance()->getProvider();
        yield $provider->awaitGet($this->getPlayer()->getName(), function(?Island $island) {
            Await::f2c(function() use ($island) {
                if (is_null($island)) {
                    yield $this->chooseIsland();
                    return;
                }
                $this->handleMenuOptions($island);
            });
        });
    }

    private function handleMenuOptions(Island $island): void {
        Await::f2c(function() use ($island) {
            $menuOptions = [
                new MenuOption(Util::praseFormat(
                    LanguageUtils::translate("menu.main.teleport")
                )),
                new MenuOption(
                    Util::praseFormat(LanguageUtils::translate("menu.main.teleport"))
                ),
                new MenuOption(
                    Util::praseFormat(LanguageUtils::translate("menu.main.manager"))
                ),
                new MenuOption(
                    Util::praseFormat(LanguageUtils::translate("menu.main.warps"))
                )
            ];

            $menuChoose = yield from $this->menu(
                LanguageUtils::translate("menu.main.title"),
                LanguageUtils::translate("menu.main.content", ["island" => $island->getIslandName()]),
                $menuOptions
            );

            if($menuChoose === null) {
                return;
            }

            switch ($menuChoose) {
                case 0:
                    $island->teleport($this->getPlayer());
                    break;
                case 1:
                    yield $this->teleport($island);
                    break;
                case 2:
                    yield $this->manager($island);
                    break;
                case 3:
                    yield $this->warps($island);
                    break;
            }
        });
    }


    public function warps(Island $island) : Generator {
        $menuOptions = [
            new MenuOption(
                Util::praseFormat(LanguageUtils::translate("menu.warps.create"))
            ),
            new MenuOption(
                Util::praseFormat(LanguageUtils::translate("menu.warps.remove"))
            ),
            new MenuOption(
                Util::praseFormat(LanguageUtils::translate("menu.warps.teleport"))
            )
        ];

        $menuChoose = yield from $this->menu(
            LanguageUtils::translate("menu.warp.title"),
            LanguageUtils::translate("menu.warp.content", ["island" => $island->getIslandName()]),
            $menuOptions
        );

        if($menuChoose === 0) {
            yield $this->createWarp($island);
        }

        if($menuChoose === 1) {
            yield $this->removeWarp($island);
        }

        if($menuChoose === 2) {
            yield $this->teleportWarp($island);
        }
    }

    public function createWarp(Island $island) : Generator {
        $elements = [
            new Input("name", LanguageUtils::translate("menu.warp.input")),
        ];

        /** @var CustomFormResponse|null $response*/
        $response = yield from $this->custom(LanguageUtils::translate("menu.warp.title"), $elements);
        if($response !== null) {
            $data = $response->getAll();
            $name = $data["name"];
            $player = $this->getPlayer();
            if($name === "") {
                FastForm::simpleNotice($player, LanguageUtils::translate("menu.warp.create.empty"), function () use ($island) {
                    Await::g2c($this->createWarp($island));
                });
                return;
            }

            if(!$player->getWorld()->getFolderName() != WorldUtils::getSkyBlockWorld()->getFolderName()) {
                $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.warp.create.must.in.world"));
                return;
            }

            $isAdded = $island->addWarp($name, $this->getPlayer()->getPosition()->asVector3(), true);

            $message = $isAdded ?
                LanguageUtils::translate("menu.warp.create.success", ["warp" => $name]) :
                LanguageUtils::translate("menu.warp.create.exists", ["warp" => $name]);

            FastForm::simpleNotice($this->getPlayer(), $message, function () use ($island) {
                Await::g2c($this->warps($island));
            });
            return;
        }
        yield $this->warps($island);
    }

    public function removeWarp(Island $island) : Generator {
        $warps = $island->getIslandWarps();

        $warpOptions = array_map(function(Warp $warp) {
            return new MenuOption(
                Util::praseFormat($warp->getWarpName())
            );
        }, $warps);

        if(count($warpOptions) === 0) {
            $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.warp.remove.empty"));
            return;
        }

        $warpChoose = yield from $this->menu(
            LanguageUtils::translate("menu.warp.remove.title"),
            LanguageUtils::translate("menu.warp.remove.content", ["island" => $island->getIslandName()]),
            $warpOptions
        );

        if(!is_null($warpChoose)) {
            FastForm::question($this->getPlayer(), LanguageUtils::translate("menu.warp.remove.confirm.title"),
                LanguageUtils::translate("menu.warp.remove.confirm.content", ["warp" => $warps[$warpChoose]->getWarpName()]),
                LanguageUtils::translate("menu.warp.remove.confirm.yes"), LanguageUtils::translate("menu.warp.remove.confirm.no"),
                function(bool $accept) use ($island, $warps, $warpChoose) {
                if($accept) {
                    $island->removeWarp($warps[$warpChoose]->getWarpName(), true);
                    FastForm::simpleNotice($this->getPlayer(), LanguageUtils::translate("menu.warp.remove.success", ["warp" => $warps[$warpChoose]->getWarpName()]), function () use ($island) {
                        Await::g2c($this->warps($island));
                    });
                    return;
                }
                Await::g2c($this->warps($island));
            });
            return;
        }
        yield $this->warps($island);
    }

    public function teleportWarp(Island $island) : Generator {
        $warps = $island->getIslandWarps();

        $warpOptions = array_map(function(Warp $warp) {
            return new MenuOption(
                Util::praseFormat($warp->getWarpName())
            );
        }, $warps);

        if(count($warpOptions) === 0) {
            $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.warp.teleport.empty"));
            return;
        }

        $warpChoose = yield from $this->menu(
            LanguageUtils::translate("menu.warp.teleport.title"),
            LanguageUtils::translate("menu.warp.teleport.conent", ["island" => $island->getIslandName()]),
            $warpOptions
        );

        if(!is_null($warpChoose)) {
            $warp = array_values($warps)[$warpChoose];
            $event = new IslandTeleportEvent($this->getPlayer()->getName(), $island);
            $event->call();

            if($event->isCancelled()) {
                return;
            }

            $this->getPlayer()->teleport($warp->getWarpPosition());
            $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.warp.teleport.success", ["warp" => $warp->getWarpName()]));
            return;
        }
        yield $this->warps($island);
    }

    public function teleport(Island $island) : Generator {
        $elements = [
            new Input("name", LanguageUtils::translate("menu.teleport.input"), $island->getIslandName()),
        ];

        $provider = AzSkyBlock::getInstance()->getProvider();

        /** @var CustomFormResponse|null $response*/
        $response = yield from $this->custom(LanguageUtils::translate("menu.teleport.title"), $elements);
        if($response !== null) {
            $data = $response->getAll();
            $name = $data["name"];
            yield $provider->awaitGet($name, function(?Island $islandTarget) use ($name, $island) {
                if(is_null($islandTarget)) {
                    $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.teleport.not.found", ["player" => $name]));
                    return;
                }
                if($islandTarget->isLocked()) {
                    FastForm::simpleNotice($this->getPlayer(), LanguageUtils::translate("menu.teleport.locked", ["player" => $islandTarget->getPlayer()]), function () use ($island) {
                        Await::g2c($this->teleport($island));
                    });
                    return;
                }
                $islandTarget->teleport($this->getPlayer());

                $islandInfo = LanguageUtils::translate("menu.teleport.info", ["island" => $islandTarget->getIslandName(), "owner" => $islandTarget->getPlayer(), "members" => implode(", ", $islandTarget->getMembers()), "warps" => implode(", ", $islandTarget->getIslandWarps()), "created" => $islandTarget->getDateCreated(), "level" => $islandTarget->getIslandLevel()->getLevelInt()]);

                FastForm::simpleNotice($this->getPlayer(), $islandInfo);
            });
            return;
        }
        yield $this->main();
    }

    public function chooseIsland(): Generator {
        /** @var CustomIsland[] $islands */
        $islands = array_values(IslandPool::getAll());
        $menuOptions = [];

        foreach($islands as $island) {
            $menuOptions[] = new MenuOption(
                Util::praseFormat($island->getName())
            );
        }

        $menuChoose = yield from $this->menu(
            LanguageUtils::translate("menu.choose.island.title"),
            LanguageUtils::translate("menu.choose.island.content"),
            $menuOptions
        );

        if($menuChoose === null) {
            yield $this->main();
            return;
        }

        /** @var CustomIsland $island*/
        $island = array_values(IslandPool::getAll())[$menuChoose];

        $confirm = yield from $this->modal(
            LanguageUtils::translate("menu.choose.island.confirm.title"),
            LanguageUtils::translate("menu.choose.island.confirm.content", ["type" => $island->getName()]),
        );

        if($confirm) {
            $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.choose.island.success", ["type" => $island->getName()]));
            $island->generate(function(Area $area, bool $hasGiven){
                Await::f2c(function() use ($area) {
                    $player = $this->getPlayer()->getName();
                    $island = Island::new($player, $player . "'s island", $area);
                    $provider = AzSkyBlock::getInstance()->getProvider();
                    yield $provider->awaitCreate($player, $island, function () use ($island) {
                        $island->teleport($this->getPlayer());
                    });
                });
            });
            return;
        }
        yield $this->chooseIsland();
    }

    public function manager(Island $island) : Generator {
        $menuOptions = [
            new MenuOption(
                Util::praseFormat(LanguageUtils::translate("menu.manager.info"))
            ),
            new MenuOption(
                Util::praseFormat(LanguageUtils::translate("menu.manager.invite"))
            ),
            new MenuOption(
                Util::praseFormat(LanguageUtils::translate("menu.manager.kick"))
            ),
            new MenuOption(
                Util::praseFormat(LanguageUtils::translate("menu.manager.members"))
            )
        ];

        $menuChoose = yield from $this->menu(
            LanguageUtils::translate("menu.manager.title"),
            LanguageUtils::translate("menu.manager.content"),
            $menuOptions
        );

        if($menuChoose === 0) {
            return yield $this->information($island);
        }

        if($menuChoose === 1) {
            return yield $this->inviteVisit($island);
        }

        if($menuChoose === 2) {
            return yield $this->kick($island);
        }

        if($menuChoose === 3) {
            return yield $this->members($island);
        }
    }

    public function information(Island $island) : Generator {

        $warpsName = array_map(function(Warp $warp) {
            return $warp->getWarpName();
        }, $island->getIslandWarps());
        $name = $island->getIslandName();
        $isLocked = $island->isLocked();

        $elements = [
            new Input("name", LanguageUtils::translate("menu.manager.info.input.island"), $name, $name),
            new Label("owner", LanguageUtils::translate("menu.manager.info.owner", ["owner" => $island->getPlayer()])),
            new Label("members",  LanguageUtils::translate("menu.manager.info.members", ["members" => implode(", ", $island->getMembers())])),
            new Toggle("lock", LanguageUtils::translate("menu.manager.info.lock"), $isLocked),
            new Label("warps", LanguageUtils::translate("menu.manager.info.warps", ["warps" => implode(", ", $warpsName)])),
            new Label("created", LanguageUtils::translate("menu.manager.info.created", ["created" => $island->getDateCreated()])),
            new Label("level", LanguageUtils::translate("menu.manager.info.level", ["level" => $island->getIslandLevel()->getLevelInt()])),
        ];

        /** @var CustomFormResponse|null $response*/
        $response = yield $this->custom(LanguageUtils::translate("menu.manager.info.title"), $elements);
        if($response !== null) {
            $data = $response->getAll();
            $name = $data["name"];
            $locked = $data["lock"];
            if($name === "") {
                FastForm::simpleNotice($this->getPlayer(), LanguageUtils::translate("menu.manager.info.input.empty"), function () use ($island) {
                    Await::g2c($this->information($island));
                });
                return;
            }

            if($name !== $island->getIslandName() or $isLocked !== $locked) {
                $island->setIslandName($name);
                $island->setLocked($locked);
                $island->save();
                FastForm::simpleNotice($this->getPlayer(), LanguageUtils::translate("menu.manager.info.update"), function () use ($island) {
                    Await::g2c($this->information($island));
                });
            }
            return;
        }
        yield $this->manager($island);
    }

    public function members(Island $island) : Generator {
        $menuOptions = [
            new MenuOption(
                Util::praseFormat(LanguageUtils::translate("menu.manager.members.add"))
            ),
            new MenuOption(
                Util::praseFormat(LanguageUtils::translate("menu.manager.members.remove"))
            )
        ];

        $menuChoose = yield from $this->menu(
            LanguageUtils::translate("menu.manager.members.title"),
            LanguageUtils::translate("menu.manager.members.content", ["members" => implode(", ", $island->getMembers())]),
            $menuOptions
        );

        if($menuChoose === 0) {
            yield $this->addMember($island);
            return;
        }

        if($menuChoose === 1) {
            yield $this->removeMembers($island);
            return;
        }
        yield $this->manager($island);
    }

    public function addMember(Island $island) : Generator {
        $playersOnline = Server::getInstance()->getOnlinePlayers();

        $membersName = array_map(function(Player $player) {
            if ($player->getName() === $this->getPlayer()->getName()) {
                return "";
            }
            return $player->getName();
        }, $playersOnline);

        $membersName = array_filter($membersName, function(string $member) {
            return $member !== "";
        });

        unset($membersName[$this->getPlayer()->getName()]);

        $dropdown = (count($membersName) === 0) ?
            new Label("label_2", LanguageUtils::translate("menu.manager.members.add.empty"))
            :
            new Dropdown("name", LanguageUtils::translate("menu.manager.members.add.input"), $membersName);

        $response = yield $this->custom(LanguageUtils::translate("menu.manager.members.add.title"), [
            new Label("label", LanguageUtils::translate("menu.manager.members.add.content")),
            $dropdown
        ]);

        if($response !== null) {

            if(count($membersName) == 0) {
                $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.manager.members.add.empty"));
                return yield $this->members($island);
            }

            $playerIndex = $response->getAll()["name"];
            $playerName = array_values($membersName)[$playerIndex];
            $player = Server::getInstance()->getPlayerExact($playerName);

            if($player == null) {
                $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.manager.members.add.online", ["player" => $playerName]));
                return yield $this->members($island);
            }

            FastForm::question($player,
                LanguageUtils::translate("menu.invite.title"),
                LanguageUtils::translate("menu.invite.content", ["player" => $island->getPlayer(), "island" => $island->getIslandName()]),
                LanguageUtils::translate("menu.invite.accept"), LanguageUtils::translate("menu.invite.deny"),
                function(bool $accept) use ($player, $island) {
                if($accept) {
                    $island->addMember($player->getName());
                    $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.manager.members.add.success", ["player" => $player->getName()]));
                    $player->sendMessage(LanguageUtils::translate("menu.manager.members.add.accept", ["player" => $this->getPlayer()->getName()]));
                } else {
                    $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.manager.members.add.deny", ["player" => $player->getName()]));
                }
            });
        }
        //yield $this->members($island);
    }

    public function removeMembers(Island $island) : Generator {
        $members = $island->getMembers();
        $membersName = array_map(function(string $member) {
            return $member;
        }, $members);

        if(isset($membersName[$this->getPlayer()->getName()])) {
            unset($membersName[$this->getPlayer()->getName()]);
        }

        $response = yield from $this->custom(LanguageUtils::translate("menu.manager.members.remove.title"), [
            new Label("label", LanguageUtils::translate("menu.manager.members.remove.content", ["island" => $island->getIslandName()])),
            new Dropdown("name", LanguageUtils::translate("menu.manager.members.remove.input"), $membersName)
        ]);

        if($response !== null) {
            $playerName = $response->getAll()["name"];
            $player = array_values($membersName)[$playerName];

            if($playerName == "") {
                yield $this->members($island);
                return;
            }

            if($island->hasMember($player)) {
                $island->removeMember($player);
                FastForm::simpleNotice($this->getPlayer(),LanguageUtils::translate("menu.manager.members.remove.success", ["player" => $playerName]), function () use ($island) {
                    Await::g2c($this->members($island));
                });
                return;
            }
        }
        yield $this->members($island);
    }


    public function kick(Island $island) : Generator {
        $playersName = array_map(function(Player $player) {
            return $player->getName();
        }, $island->getPlayersInIsland());

        $response = yield from $this->custom(LanguageUtils::translate("menu.manager.kick.title"), [
            new Label("label", LanguageUtils::translate("menu.manager.kick.content")),
            new Dropdown("name", LanguageUtils::translate("menu.manager.kick.input"), $playersName)
        ]);

        if($response !== null) {
            $playerName = $response->getAll()["name"];
            $player = array_values($playersName)[$playerName];

            if(($player = Server::getInstance()->getPlayerExact($player)) !== null) {
                $provider = AzSkyBlock::getInstance()->getProvider();
                yield $provider->awaitGet($player->getName(), function(?Island $island) use ($playerName, $player) {
                    if(is_null($island)) {
                        $this->getPlayer()->sendMessage("§cPlayer not found");
                        return;
                    }
                    $island->teleport($player);
                    FastForm::question($this->getPlayer(), LanguageUtils::translate("menu.manager.kick.confirm.title"),
                        LanguageUtils::translate("menu.manager.kick.confirm.content", ["player" => $playerName]),
                        LanguageUtils::translate("menu.manager.kick.confirm.yes"), LanguageUtils::translate("menu.manager.kick.confirm.no"),
                        function(bool $accept) use ($player, $island) {
                        if($accept) {
                            $island->removeMember($player->getName());
                            FastForm::simpleNotice($this->getPlayer(),
                                LanguageUtils::translate("menu.manager.kick.success", ["player" => $player->getName()])
                                , function () use ($island) {
                                Await::g2c($this->manager($island));
                            });
                            return;
                        }
                        Await::g2c($this->manager($island));
                    });
                });
                return;
            }
            $this->getPlayer()->sendMessage("§cPlayer is offline");
            return;
        }
        yield $this->manager($island);
    }

    public function inviteVisit(Island $island) : Generator {
        $players = array_map(function(Player $player) {
            return $player->getName();
        }, Server::getInstance()->getOnlinePlayers());

        $response = yield from $this->custom(LanguageUtils::translate("menu.manager.invite.title"), [
            new Dropdown("name", LanguageUtils::translate("menu.manager.invite.input"), $players)
        ]);

        if($response !== null) {
            $playerIndex = $response->getAll()["name"];
            $playerName = array_values($players)[$playerIndex];
            $player = Server::getInstance()->getPlayerExact($playerName);

            if($player == null) {
                $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.manager.invite.online", ["player" => $playerName]));
                return;
            }

            FastForm::question($player, LanguageUtils::translate("menu.invite.accept.title"),
                LanguageUtils::translate("menu.invite.content", ["player" => $island->getPlayer(), "island" => $island->getIslandName()]),
                LanguageUtils::translate("menu.invite.accept"), LanguageUtils::translate("menu.invite.deny"),
                function(bool $accept) use ($player, $island) {
                if($accept) {
                    $island->teleport($player);
                    $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.manager.invite.accept", ["player" => $player->getName()]));
                } else {
                    $this->getPlayer()->sendMessage(LanguageUtils::translate("menu.manager.invite.deny", ["player" => $player->getName()]));
                }
            });
        }
    }
}