<?php
declare(strict_types=1);

namespace LotGD\Module\Training\Tests;

use Doctrine\Common\Util\Debug;
use Doctrine\ORM\EntityManager;
use LotGD\Core\Events\EventContext;
use LotGD\Core\Events\EventContextData;
use LotGD\Core\Game;
use LotGD\Core\Models\Character;
use LotGD\Core\Models\Viewpoint;
use LotGD\Module\Res\Fight\Fight;
use LotGD\Module\Res\Fight\Tests\helpers\EventRegistry;
use LotGD\Module\Res\Fight\Module as ResFightModule;

use LotGD\Module\Training\Module;

class ModuleTest extends ModuleTestCase
{
    const Library = 'lotgd/module-project';

    protected function getDataSet(): \PHPUnit_Extensions_Database_DataSet_YamlDataSet
    {
        return new \PHPUnit_Extensions_Database_DataSet_YamlDataSet(implode(DIRECTORY_SEPARATOR, [__DIR__, 'datasets', 'module.yml']));
    }

    public function testHandleUnknownEvent()
    {
        // Always good to test a non-existing event just to make sure nothing happens :).
        $context = new EventContext(
            "e/lotgd/tests/unknown-event",
            "none",
            EventContextData::create([])
        );

        Module::handleEvent($this->g, $context);
    }

    public function testTrainingAreaIsPresent()
    {
        /** @var Game $game */
        $game = $this->g;
        /** @var Character $character */
        $character = $this->getEntityManager()->getRepository(Character::class)->find("10000000-0000-0000-0000-000000000001");
        $game->setCharacter($character);

        $yardSceneId = $this->getTestSceneIds();

        // New day
        $v = $game->getViewpoint();
        $this->assertSame("It is a new day!", $v->getTitle());
        // Village
        $action = $v->getActionGroups()[0]->getActions()[0];
        $game->takeAction($action->getId());
        $this->assertSame("Village", $v->getTitle());

        // Assert action to training yard
        $action = $this->assertHasAction($v, ["getDestinationSceneId", $yardSceneId = $this->getTestSceneIds()], "Outside");

        // Assert yard exists
        $game->takeAction($action->getId());
        $this->assertSame("Bluspring's Warrior Training", $v->getTitle());
        $this->assertHasAction($v, ["getDestinationSceneId", "20000000-0000-0000-0000-000000000001"], "Back");
    }

    protected function getTestSceneIds()
    {
        $em = $this->getEntityManager(); /** @var EntityManager $game */
        $game = $this->g; /* @var Game $game */

        $module = $game->getModuleManager()->getModule(Module::ModuleIdentifier);
        $scenes = $module->getProperty(Module::GeneratedSceneProperty);
        return $scenes["yard"][0];
    }

    protected function goToYard(string $characterId, callable $executeBeforeTakingActionToYard = null): array
    {
        /** @var Game $game */
        $game = $this->g;
        /** @var Character $character */
        $character = $this->getEntityManager()->getRepository(Character::class)->find($characterId);
        $game->setCharacter($character);

        $yardSceneId = $this->getTestSceneIds();

        // New day
        $v = $game->getViewpoint();
        $this->assertSame("It is a new day!", $v->getTitle());
        // Village
        $action = $v->getActionGroups()[0]->getActions()[0];
        $game->takeAction($action->getId());
        $this->assertSame("Village", $v->getTitle());
        // Training Yard
        $action = $this->assertHasAction($v, ["getDestinationSceneId", $yardSceneId], "Outside");

        if ($executeBeforeTakingActionToYard !== NULL) {
            $executeBeforeTakingActionToYard($game, $v, $character);
        }

        $game->takeAction($action->getId());

        return [$game, $v, $character];
    }

    public function testIfMasterTellsInexperiencedCharacterToComeBackLater()
    {
        [$game, $v, $character] = $this->goToYard("10000000-0000-0000-0000-000000000002");
        $action = $this->assertHasAction($v, ["getTitle", "Question Master"], "The Yard");

        // Set experience to 0 and ask the master.
        $character->setProperty(ResFightModule::CharacterPropertyCurrentExperience, 0);
        $game->takeAction($action->getId());
        $this->assertSame("Bluspring's Warrior Training", $v->getTitle());
        $action = $this->assertHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $this->assertHasAction($v, ["getDestinationSceneId", "20000000-0000-0000-0000-000000000001"], "Back");
        $description = explode("\n\n", $v->getDescription());
        $this->assertContains("You approach Mieraband timidly and inquire as to your standing in the class.", $description);
        $this->assertContains("Mieraband states that you will need 100 more experience before you are ready to challenge him in battle.", $description);
    }

    public function testIfMasterTellsExperiencedCharacterThatHeIsReady()
    {
        [$game, $v, $character] = $this->goToYard("10000000-0000-0000-0000-000000000003");
        $action = $this->assertHasAction($v, ["getTitle", "Question Master"], "The Yard");

        // Set experience to 100 and ask the master.
        $character->setProperty(ResFightModule::CharacterPropertyCurrentExperience, 100);
        $game->takeAction($action->getId());
        $this->assertSame("Bluspring's Warrior Training", $v->getTitle());
        $action = $this->assertHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $this->assertHasAction($v, ["getDestinationSceneId", "20000000-0000-0000-0000-000000000001"], "Back");
        $description = explode("\n\n", $v->getDescription());
        $this->assertContains("You approach Mieraband timidly and inquire as to your standing in the class.", $description);
        $this->assertContains("Mieraband says, \"Gee, your muscles are getting bigger than mine...\"", $description);
    }

    public function testIfDeadCharacterCannotChallengeOrQuestionTheMaster()
    {
        [$game, $v, $character] = $this->goToYard(
            "10000000-0000-0000-0000-000000000004",
            function(Game $g, Viewpoint $v, Character $character) {
                $character->setHealth(0);
            }
        );

        $this->assertNotHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $this->assertNotHasAction($v, ["getTitle", "Challenge Master"], "The Yard");
        $this->assertSame("You are dead. How are you going to challenge your master if you cannot even survive killing enemies? Come back tomorrow.", $v->getDescription());
    }

    public function testIfCharacterAbove14CannotChallengeOrQuestionTheMaster()
    {
        [$game, $v, $character] = $this->goToYard("10000000-0000-0000-0000-000000000005");

        $this->assertNotHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $this->assertNotHasAction($v, ["getTitle", "Challenge Master"], "The Yard");
    }

    public function testIfCharacterCannotChallengeOrQuestionMasterIfHeHasAlreadySeenHimToday()
    {
        [$game, $v, $character] = $this->goToYard(
            "10000000-0000-0000-0000-000000000006",
            function(Game $g, Viewpoint $v, Character $c) {
                $c->setProperty(Module::CharacterPropertySeenMaster, true);
            }
        );

        $this->assertNotHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $this->assertNotHasAction($v, ["getTitle", "Challenge Master"], "The Yard");
    }

    public function testIfMasterInstaDefeatsCharacterIfHeHasNotEnoughExperience()
    {
        [$game, $v, $character] = $this->goToYard("10000000-0000-0000-0000-000000000007");

        $this->assertHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $action = $this->assertHasAction($v, ["getTitle", "Challenge Master"], "The Yard");

        $game->takeAction($action->getId());

        $this->assertTrue($character->getProperty(Module::CharacterPropertySeenMaster));
        $this->assertHasAction($v, ["getDestinationSceneId", "20000000-0000-0000-0000-000000000001"], "Back");
    }

    public function testIfCharacterCannotRechallengeMasterIfHeLooses()
    {
        [$game, $v, $character] = $this->goToYard("10000000-0000-0000-0000-000000000008");

        $action = $this->assertHasAction($v, ["getTitle", "Challenge Master"], "The Yard");

        $character->setProperty(ResFightModule::CharacterPropertyCurrentExperience, 100000);

        $game->takeAction($action->getId());
        $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");
        $character->setHealth(0);

        // Attack until someone dies.
        do {
            $game->takeAction($action->getId());

            if ($character->getProperty(ResFightModule::CharacterPropertyBattleState) !== null){
                $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");
            } else {
                break;
            }
        } while (true);

        $descs = explode("\n\n", $v->getDescription());
        $descs = array_map("trim", $descs);
        $this->assertContains("You have been defeated by Mieraband. They stand over your dead body, laughing..", $descs);
        $this->assertTrue($character->getProperty(Module::CharacterPropertySeenMaster));
        $this->assertNotHasAction($v, ["getTitle", "Challenge Master"], "The Yard");
    }

    public function testIfCharacterCanRechallengeMasterIfHeWinsAndIfHeReallyIncreasesHisLevel()
    {
        [$game, $v, $character] = $this->goToYard("10000000-0000-0000-0000-000000000009");

        $action = $this->assertHasAction($v, ["getTitle", "Challenge Master"], "The Yard");

        $character->setProperty(ResFightModule::CharacterPropertyCurrentExperience, 100000);

        $game->takeAction($action->getId());
        $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");

        // Attack until someone dies.
        do {
            $character->setHealth(10); // constantly heal.
            $game->takeAction($action->getId());

            if ($character->getProperty(ResFightModule::CharacterPropertyBattleState) !== null){
                $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");
            } else {
                break;
            }
        } while (true);

        $descs = explode("\n\n", $v->getDescription());
        $descs = array_map("trim", $descs);
        $this->assertContains("You defeated Mieraband. You gain a level!", $descs);

        $this->assertSame(2, $character->getLevel());
        $this->assertFalse($character->getProperty(Module::CharacterPropertySeenMaster));
        $this->assertHasAction($v, ["getTitle", "Challenge Master"], "The Yard");
    }
}
