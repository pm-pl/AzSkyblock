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
use phuongaz\azskyblock\island\components\Warp;
use phuongaz\azskyblock\island\Island;
use phuongaz\azskyblock\world\custom\CustomIsland;
use phuongaz\azskyblock\world\custom\IslandPool;
use pocketmine\math\Vector3;
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
        yield from $provider->awaitGet($this->getPlayer()->getName(), function(?Island $island) {
            Await::f2c(function() use ($island) {
                if (is_null($island)) {
                    yield from $this->chooseIsland();
                    return;
                }

                yield from $this->handleMenuOptions($island);
            });
        });
    }

    private function handleMenuOptions(Island $island): Generator {
        yield from Await::f2c(function() use ($island) {
            $menuOptions = [
                new MenuOption("Go to island"),
                new MenuOption("Teleport"),
                new MenuOption("Manager"),
                new MenuOption("Warps")
            ];

            $menuChoose = yield from $this->menu(
                "Skyblock",
                $island->getIslandName(),
                $menuOptions
            );

            if($menuChoose === null) {
                return;
            }

            switch ($menuChoose) {
                case 0:
                    $this->getPlayer()->teleport($island->getIslandSpawn());
                    break;
                case 1:
                    yield from $this->teleport($island);
                    break;
                case 2:
                    yield from $this->manager($island);
                    break;
                case 3:
                    yield from $this->warps($island);
                    break;
            }
        });
    }


    public function warps(Island $island) : Generator {
        $menuOptions = [
            new MenuOption("Create"),
            new MenuOption("Remove"),
            new MenuOption("Teleport")
        ];

        $menuChoose = yield from $this->menu(
            "Warps",
            $island->getIslandName(),
            $menuOptions
        );

        if($menuChoose === 0) {
            yield from $this->createWarp($island);
        }

        if($menuChoose === 1) {
            yield from $this->removeWarp($island);
        }

        if($menuChoose === 2) {
            yield from $this->teleportWarp($island);
        }
    }

    public function createWarp(Island $island) : Generator {
        $elements = [
            new Input("name", "Name of warp"),
        ];

        /** @var CustomFormResponse|null $response*/
        $response = yield from $this->custom("Create warp", $elements);
        if($response !== null) {
            $data = $response->getAll();
            $name = $data["name"];

            if(!$island->isInIsland($this->getPlayer())) {
                $this->getPlayer()->sendMessage("§cYou must be in your island");
                return;
            }

            $isAdded = $island->addWarp($name, $this->getPlayer()->getPosition()->asVector3());

            $message = $isAdded ? "§aWarp " . $name . " has been created" : "§cWarp " . $name . " already exists";

            FastForm::simpleNotice($this->getPlayer(), $message, function () use ($island) {
                Await::g2c($this->warps($island));
            });
            return;
        }
        yield from $this->warps($island);
    }

    public function removeWarp(Island $island) : Generator {
        $warps = $island->getIslandWarps();
        $warpsName = array_map(function(string $warp) {
            return $warp;
        }, $warps);

        $response = yield from $this->custom("Remove warp", [
            new Label("label", "Warps of your island"),
            new Dropdown("name", "Name of warp", $warpsName)
        ]);

        if($response !== null) {
            $warpName = $response->getAll()["name"];
            $warp = array_values($warpsName)[$warpName];


            $isRemoved = $island->removeWarp($warp);
            $message = $isRemoved ? "§aWarp " . $warp . " has been removed" : "§cWarp " . $warp . " not found";

            FastForm::simpleNotice($this->getPlayer(), $message, function () use ($island) {
                Await::g2c($this->warps($island));
            });
            return;
        }
        yield from $this->warps($island);
    }

    public function teleportWarp(Island $island) : Generator {
        $warps = $island->getIslandWarps();

        $warpOptions = array_map(function(Warp $warp) {
            return new MenuOption($warp->getWarpName());
        }, $warps);

        if(count($warpOptions) === 0) {
            $this->getPlayer()->sendMessage("§cYour island has no warp");
            return;
        }

        $warpChoose = yield from $this->menu(
            "Teleport warp",
            $island->getIslandName(),
            $warpOptions
        );

        if(!is_null($warpChoose)) {
            $warp = array_values($warps)[$warpChoose];
            $this->getPlayer()->teleport($warp->getWarpPosition());
            $this->getPlayer()->sendMessage("§aTeleported to " . $warp->getWarpName());
            return;
        }
        yield from $this->warps($island);
    }

    public function teleport(Island $island) : Generator {
        $elements = [
            new Input("name", "Name of player", $island->getIslandName()),
        ];

        $provider = AzSkyBlock::getInstance()->getProvider();

        /** @var CustomFormResponse|null $response*/
        $response = yield from $this->custom("Teleport", $elements);
        if($response !== null) {
            $data = $response->getAll();
            $name = $data["name"];
            yield from $provider->awaitGet($name, function(?Island $islandTarget) use ($island) {
                if(is_null($islandTarget)) {
                    $this->getPlayer()->sendMessage("§cPlayer not found");
                    return;
                }
                if($islandTarget->isLocked()) {
                    FastForm::simpleNotice($this->getPlayer(), "Island ". $islandTarget->getIslandName()." locked", function () use ($island) {
                        Await::g2c($this->teleport($island));
                    });
                    return;
                }
                $spawn = $islandTarget->getIslandSpawn();
                $this->getPlayer()->teleport($spawn);
                $this->getPlayer()->sendMessage("§aTeleported to " . $islandTarget->getIslandName());
            });
            return;
        }
        yield from $this->main();
    }

    public function chooseIsland(): Generator {
        /** @var CustomIsland[] $islands */
        $islands = array_values(IslandPool::getAll());
        $menuOptions = [];

        foreach($islands as $island) {
            $menuOptions[] = new MenuOption($island->getName());
        }

        $menuChoose = yield from $this->menu(
            "Choose island",
            "Choose island",
            $menuOptions
        );

        if($menuChoose === null) {
            yield from $this->main();
            return;
        }

        /** @var CustomIsland $island*/
        $island = array_values(IslandPool::getAll())[$menuChoose];

        $confirm = yield from $this->modal(
            "Confirm",
            "Create island " . $island->getName() . "?"
        );

        if($confirm) {
            $this->getPlayer()->sendMessage("§aCreating island " . $island->getName());
            $island->generate(function(Position|Vector3 $spawn){
                $this->getPlayer()->sendMessage("Island created");
                $player = $this->getPlayer()->getName();
                $island = Island::new($player, $player . "'s island", $spawn);
                $provider = AzSkyBlock::getInstance()->getProvider();
                Await::g2c($provider->awaitCreate($player, $island, function(?Island $island) {
                    $island->teleportToIsland($this->getPlayer());
                }));
            });
            return;
        }
        yield from $this->chooseIsland();
    }

    public function manager(Island $island) : Generator {
        $menuOptions = [
            new MenuOption("Invite"),
            new MenuOption("Kick"),
            new MenuOption("Lock"),
            new MenuOption("Members"),
        ];

        $menuChoose = yield from $this->menu(
            "Manager",
            $island->getIslandName(),
            $menuOptions
        );

        if($menuChoose === 0) {
            yield from $this->inviteVisit($island);
        }

        if($menuChoose === 1) {
            yield from $this->kick($island);
        }

        if($menuChoose === 2) {
            yield from $this->lock($island);
        }

        if($menuChoose === 3) {
            yield from $this->members($island);
        }
    }

    public function members(Island $island) : Generator {
        $menuOptions = [
            new MenuOption("Add"),
            new MenuOption("Remove"),
        ];

        $menuChoose = yield from $this->menu(
            "Members",
            $island->getIslandName(),
            $menuOptions
        );

        if($menuChoose === 0) {
            yield from $this->addMember($island);
            return;
        }

        if($menuChoose === 1) {
            yield from $this->removeMembers($island);
            return;
        }
        yield from $this->manager($island);
    }

    public function addMember(Island $island) : Generator {
        $members = $island->getMembers();
        $membersName = array_map(function(string $member) {
            return $member;
        }, $members);

        $response = yield from $this->custom("Members", [
            new Label("label", "Members of your island"),
            new Dropdown("name", "Name of player", $membersName)
        ]);

        if($response !== null) {
            $playerName = $response->getAll()["name"];
            $player = array_values($membersName)[$playerName];

            if(($player = Server::getInstance()->getPlayerExact($player)) !== null) {
                $this->getPlayer()->sendMessage("§cPlayer is online");
                return;
            }
            (new InvitedForm($player,
                "Player " . $this->getPlayer()->getName() . " invited you to join island " . $island->getIslandName(),
                $island,
                function(bool $accept) use ($player, $island) {
                    if($accept) {
                        $island->addMember($player->getName());
                        $this->getPlayer()->sendMessage("§aPlayer accepted");
                    } else {
                        $this->getPlayer()->sendMessage("§cPlayer denied");
                    }
                }))->send();
        }
        yield from $this->members($island);
    }

    public function removeMembers(Island $island) : Generator {
        $members = $island->getMembers();
        $membersName = array_map(function(string $member) {
            return $member;
        }, $members);

        $response = yield from $this->custom("Members", [
            new Label("label", "Members of your island"),
            new Dropdown("name", "Name of player", $membersName)
        ]);

        if($response !== null) {
            $playerName = $response->getAll()["name"];
            $player = array_values($membersName)[$playerName];

            $island->removeMember($player);
            FastForm::simpleNotice($this->getPlayer(), "Player " . $player . " has been removed", function () use ($island) {
                Await::g2c($this->members($island));
            });
            return;
        }
        yield from $this->members($island);
    }

    public function lock(Island $island) : Generator {
        $islandLocked = $island->isLocked();

        $response = yield from $this->custom("Lock island", [
            new Label("label", "Lock or unlock your island"),
            new Toggle("lock", "Lock", $islandLocked)
        ]);

        if($response !== null) {
            $islandLocked = $response->getAll()["lock"];
            $island->setLocked($islandLocked);
            FastForm::simpleNotice($this->getPlayer(), "Island has been " . ($islandLocked ? "locked" : "unlocked"), function () use ($island) {
                Await::g2c($this->manager($island));
            });
            return;
        }

        yield from $this->manager($island);
    }

    public function kick(Island $island) : Generator {
        $playersName = array_map(function(Player $player) {
            return $player->getName();
        }, $island->getPlayersInIsland());

        $response = yield from $this->custom("Kick player", [
            new Label("label", "Kick players who are on your island"),
            new Dropdown("name", "Name of player", $playersName)
        ]);

        if($response !== null) {
            $playerName = $response->getAll()["name"];
            $player = array_values($playersName)[$playerName];

            if(($player = Server::getInstance()->getPlayerExact($player)) !== null) {
                $provider = AzSkyBlock::getInstance()->getProvider();
                yield from $provider->awaitGet($player->getName(), function(?Island $island) use ($player) {
                    if(is_null($island)) {
                        $this->getPlayer()->sendMessage("§cPlayer not found");
                        return;
                    }
                    $player->teleport($island->getIslandSpawn());
                    $player->sendMessage("§cYou have been kicked from island " . $island->getIslandName());
                    FastForm::simpleNotice($this->getPlayer(), "Player " . $player->getName() . " has been kicked", function () use ($island) {
                        Await::g2c($this->manager($island));
                    });
                });
                return;
            }
            $this->getPlayer()->sendMessage("§cPlayer is offline");
            return;
        }
        yield from $this->manager($island);
    }

    public function inviteVisit(Island $island) : Generator {
        $players = array_map(function(Player $player) {
            return $player->getName();
        }, Server::getInstance()->getOnlinePlayers());

        $response = yield from $this->custom("Visit member", [
            new Dropdown("name", "Name of player", $players)
        ]);

        if($response !== null) {
            $playerName = $response->getAll()["name"];
            $player = array_values($players)[$playerName];

            if(($player = Server::getInstance()->getPlayerExact($player)) !== null) {
                $this->getPlayer()->sendMessage("§cPlayer is online");
                return;
            }
            (new InvitedForm($player,
                "Player " . $this->getPlayer()->getName() . " invited you to visit island " . $island->getIslandName(),
                $island,
                function(bool $accept) use ($player, $island) {
                    if($accept) {
                        $player->teleport($island->getIslandSpawn());
                        $this->getPlayer()->sendMessage("§aPlayer accepted");
                    } else {
                        $this->getPlayer()->sendMessage("§cPlayer denied");
                    }
                }))->send();
        }
    }
}