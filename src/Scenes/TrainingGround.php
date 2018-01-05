<?php
declare(strict_types=1);

namespace LotGD\Module\Training\Scenes;

use Composer\Script\Event;
use LotGD\Core\Action;
use LotGD\Core\ActionGroup;
use LotGD\Core\Battle;
use LotGD\Core\Events\EventContext;
use LotGD\Core\Game;
use LotGD\Core\Models\Character;
use LotGD\Core\Models\FighterInterface;
use LotGD\Core\Models\Scene;
use LotGD\Core\Models\SceneConnectable;
use LotGD\Core\Models\SceneConnection;
use LotGD\Core\Models\SceneConnectionGroup;
use LotGD\Core\Models\Viewpoint;
use LotGD\Module\Res\Fight\Fight;
use LotGD\Module\Res\Fight\Models\CharacterResFightExtension;
use LotGD\Module\Res\Fight\Module as ResFightModule;

use LotGD\Module\Training\Managers\MasterManager;
use LotGD\Module\Training\Models\Master;
use LotGD\Module\Training\Module as TrainingModule;
use LotGD\Module\Training\Module;

/**
 * Class TrainingGround
 * @package LotGD\Module\Academy\Scene
 */
class TrainingGround
{
    const Template = "lotgd/module-training/training";
    const ActionGroups = [
        "trainyard" => ["lotgd/module-training/training/trainyard", "The Yard"],
        "back" => ["lotgd/module-training/forest/back", "Back"],
    ];
    const ActionQuestion = "question";
    const ActionChallenge = "challenge";

    /**
     * Creates the scene template
     * @return Scene
     */
    public static function create(): Scene
    {
        $training = Scene::create([
            "template" => self::Template,
            "title" => "Bluspring's Warrior Training",
            "description" => "You stroll into the battle grounds. Younger warriors huddle
    together and point as you pass by. You know this place well. Bluspring hails you, and 
    you grasp her hand firmly. There is nothing left for you here but memories. You remain
    a moment longer, and look at the warriors in training before you turn to return to the
    village.",
            ]
        );

        foreach (self::ActionGroups as $key => $val) {
            $training->addConnectionGroup(new SceneConnectionGroup($val[0], $val[1]));
        }


        return $training;
    }

    /**
     * Handles the navigation-to forest event
     * @param Game $g
     * @param EventContext $context
     * @return EventContext
     */
    public static function handleEvent(Game $g, EventContext $context): EventContext
    {
        /** @var array $parameters */
        $parameters = $context->getDataField("parameters");
        $viewpoint = $context->getDataField("viewpoint");

        if (isset($parameters["action"])) {
            if ($parameters["action"] == self::ActionChallenge) {
                return self::handleActionChallenge($g, $context);

            } elseif ($parameters["action"] == self::ActionQuestion) {
                return self::handleActionQuestion($g, $context);
            }
        }

        return self::handleMainYard($g, $context, $viewpoint->getScene()->getId());
    }

    /**
     * Adds additional forest actions, such as options to search for a fight.
     * @param Game $g
     * @param EventContext $context
     * @param int $trainingId
     * @return EventContext
     */
    public static function handleMainYard(Game $g, EventContext $context, int $trainingId): EventContext
    {
        /** @var Viewpoint $v */
        $v = $context->getDataField("viewpoint");
        /** @var Character $c */
        $c = $g->getCharacter();

        if ($c->isAlive() === false) {
            # You are dead.
            $v->setDescription(
                "You are dead. How are you going to challenge your master if you cannot even survive killing enemies? Come back tomorrow."
            );
        } elseif ($c->getLevel() >= 15) {
            # You are level 15, no master.
        } elseif ($c->getProperty(TrainingModule::CharacterPropertySeenMaster) > 0) {
            # You are fit for challenging you master, but did it already today.
            $v->setDescription(
                "The sound of conflict surrounds you.  The clang of weapons in grisly battle 
                    inspires your warrior heart.
                    
                    You already challenged your master today. Is one embarassment per day not enough?"
            );
        } else {
            # Fit for challenging the master.
            /** @var Master $m */
            $m = (new MasterManager($g))->getMaster($c->getLevel());

            self::addYardActions($v);

            $v->setDescription(sprintf(
                "The sound of conflict surrounds you.  The clang of weapons in grisly battle 
                    inspires your warrior heart.
                    
                    %s stands ready to evaluate you.",
                $m->getDisplayName()
            ));
        }

        return $context;
    }

    /**
     * Helper method to add standard actions to the yard
     * @param Viewpoint $v
     */
    protected static function addYardActions(Viewpoint $v): void
    {
        $trainingId = $v->getScene()->getId();

        $actions = [new Action($trainingId, "Question Master", ["action" => self::ActionQuestion])];
        $actions[] = new Action($trainingId, "Challenge Master", ["action" => self::ActionChallenge]);

        if ($v->hasActionGroup(self::ActionGroups["trainyard"][0])) {
            foreach ($actions as $action) {
                $v->addActionToGroupId($action, self::ActionGroups["trainyard"][0]);
            }
        } else {
            $group = new ActionGroup(self::ActionGroups["trainyard"][0], self::ActionGroups["trainyard"][1], 0);
            $group->setActions($actions);
            $v->addActionGroup($group);
        }
    }

    /**
     * Handles if the character wants to ask the master if he is ready.
     * @param Game $g
     * @param EventContext $context
     * @return EventContext
     */
    public static function handleActionQuestion(Game $g, EventContext $context): EventContext
    {
        /** @var Viewpoint $v */
        $v = $context->getDataField("viewpoint");
        /** @var Character $c */
        $c = $g->getCharacter();
        /** @var Master $m */
        $m = (new MasterManager($g))->getMaster($c->getLevel());

        $v->setDescription(sprintf(
           "You approach %s timidly and inquire as to your standing in the class.",
            $m->getDisplayName()
        ));

        if (CharacterResFightExtension::characterHasRequiredExperience($c, $g)) {
            $v->addDescriptionParagraph(sprintf(
                "%s says, \"Gee, your muscles are getting bigger than mine...\"",
                $m->getDisplayName()
            ));
        } else {
            $experienceMax = CharacterResFightExtension::getRequiredExperienceForCharacter($c, $g);
            $experienceNeeded = $experienceMax - CharacterResFightExtension::getCurrentExperienceForCharacter($c);

            $v->addDescriptionParagraph(sprintf(
                "%s states that you will need %s more experience before you are ready to challenge him in battle.",
                $m->getDisplayName(), $experienceNeeded
            ));
        }

        self::addYardActions($v);

        return $context;
    }

    /**
     * Handles the search action.
     * @param Game $g
     * @param EventContext $context
     * @return EventContext
     */
    public static function handleActionChallenge(Game $g, EventContext $context): EventContext
    {
        /** @var Viewpoint $v */
        $v = $context->getDataField("viewpoint");
        /** @var Character $c */
        $c = $g->getCharacter();
        /** @var Master $m */
        $m = (new MasterManager($g))->getMaster($c->getLevel());

        $v->setTitle("A fight against your master!");
        $c->setProperty(Module::CharacterPropertySeenMaster, true);

        // Character has not enough experience
        if (CharacterResFightExtension::characterHasRequiredExperience($c, $g) == false) {
            $v->setDescription(sprintf(
                "You ready your weapon and approach your master, %1\$s.
                
                A small crowd of onlookers has gathered, and y ou briefly notice the smiles in their faces, but you feel confident.
                You bow before %1\$s, and execute a perfect spin-attack, only to realize that you are holding NOTHING! %1\$s stands
                beore you holding your weapon. Meekly you retrieve your weapon, and slink out of the training grounds to the sound
                of boisterous guffaws.",
                $m->getDisplayName()
            ));

            return $context;
        }

        $v->setDescription(sprintf(
            "Your master quickly spins around you and taunts you to attack first, being sure that you'll never
            be victorious."
        ));

        $fight = Fight::start($g, $m, $v->getScene(), TrainingModule::BattleContext);
        $fight->showFightActions();
        $fight->suspend();

        return $context;
    }

    /**
     * Handles the BattleOver event.
     * @param Game $g
     * @param EventContext $context
     * @return EventContext
     */
    public static function handleBattleOverEvent(Game $g, EventContext $context): EventContext
    {
        $battleIdentifier = $context->getDataField("battleIdentifier");

        if ($battleIdentifier == TrainingModule::BattleContext) {
            $battle = $context->getDataField("battle");
            $viewpoint = $context->getDataField("viewpoint");
            $referrerSceneId = $context->getDataField("referrerSceneId");
            $character = $g->getCharacter();

            // Display normal actions (need API later for this, from core)
            $scene = $g->getEntityManager()->getRepository(Scene::class)->find($referrerSceneId);

            $actionGroups = [
                ActionGroup::DefaultGroup => new ActionGroup(ActionGroup::DefaultGroup, '', 0),
            ];
            $scene->getConnections()->map(function(SceneConnection $connection) use ($scene, &$actionGroups) {
                if ($connection->getOutgoingScene() === $scene) {
                    // current scene is outgoing, use incoming.
                    $connectedScene = $connection->getIncomingScene();
                    $connectionGroupName = $connection->getOutgoingConnectionGroupName();
                } else {
                    // current scene is not outgoing, thus incoming, use outgoing.
                    $connectedScene = $connection->getOutgoingScene();
                    $connectionGroupName = $connection->getIncomingConnectionGroupName();

                    // Check if the connection is unidirectional - if yes, the current scene (incoming in this branch) cannot
                    // connect to the outgoing scene.
                    if ($connection->isDirectionality(SceneConnectable::Unidirectional)) {
                        return;
                    }
                }

                $action = new Action($connectedScene->getId());

                if ($connectionGroupName === null) {
                    $actionGroups[ActionGroup::DefaultGroup]->addAction($action);
                } else {
                    if (isset($actionGroups[$connectionGroupName])) {
                        $actionGroups[$connectionGroupName]->addAction($action);
                    } else {
                        $connectionGroup = $scene->getConnectionGroup($connectionGroupName);
                        $actionGroup = new ActionGroup($connectionGroupName, $connectionGroup->getTitle(), 0);
                        $actionGroup->addAction($action);

                        $actionGroups[$connectionGroupName] = $actionGroup;
                    }
                }
            });

            $viewpoint->setActionGroups($actionGroups);

            if ($battle->getWinner() === $character) {
                $viewpoint->setTitle("You won!");

                $viewpoint->addDescriptionParagraph(sprintf(
                    "You defeated %s. You gain a level!",
                    $battle->getLoser()->getDisplayName()
                ));

                $character->setProperty(Module::CharacterPropertySeenMaster, false);
                CharacterResFightExtension::levelUpCharacter($character, $g);

                self::addYardActions($viewpoint);
            } else {
                $viewpoint->setTitle("You lost!");

                $viewpoint->addDescriptionParagraph(sprintf(
                    "You have been defeated by %s. They stand over your dead body, laughting..",
                    $battle->getWinner()->getDisplayName()
                ));
            }
        }

        return $context;
    }
}