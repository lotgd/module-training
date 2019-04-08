<?php
declare(strict_types=1);

namespace LotGD\Module\Training;

use SplFileObject;
use LotGD\Core\Game;
use LotGD\Core\Events\EventContext;
use LotGD\Core\Module as ModuleInterface;
use LotGD\Core\Models\Module as ModuleModel;
use LotGD\Core\Models\Scene;

use LotGD\Module\NewDay\Module as NewDayModule;
use LotGD\Module\Res\Fight\Module as FightModule;
use LotGD\Module\Training\Models\Master;
use LotGD\Module\Training\Scenes\TrainingGround;
use LotGD\Module\Village\Module as VillageModule;

class Module implements ModuleInterface {
    const ModuleIdentifier = "lotgd/module-training";
    const CharacterPropertySeenMaster = self::ModuleIdentifier . "/seenMaster";
    const BattleContext = self::ModuleIdentifier . "/battle";
    const GeneratedSceneProperty = "generatedScenes";

    public static function handleEvent(Game $g, EventContext $context): EventContext
    {
        $event = $context->getEvent();

        switch($event) {
            case "h/lotgd/core/navigate-to/" . TrainingGround::Template:
                $context = TrainingGround::handleEvent($g, $context);
                break;

            case FightModule::HookBattleOver:
                $context = TrainingGround::handleBattleOverEvent($g, $context);
                break;

            case NewDayModule::HookAfterNewDay:
                $context = self::handleAfterNewDayEvent($g, $context);
                break;
        }
        
        return $context;
    }

    /**
     * Sets back if the character has seen his master today.
     * @param Game $g
     * @param EventContext $context
     * @return EventContext
     */
    protected static function handleAfterNewDayEvent(Game $g, EventContext $context): EventContext
    {
        $g->getCharacter()->setProperty(self::CharacterPropertySeenMaster, false);
        return $context;
    }
    
    public static function onRegister(Game $g, ModuleModel $module)
    {
        $villageScenes = $g->getEntityManager()->getRepository(Scene::class)
            ->findBy(["template" => VillageModule::VillageScene]);

        $generatedScenes = ["yard" => []];

        foreach ($villageScenes as $villageScene) {
            $trainingScene = TrainingGround::create();

            // Connect training ground to the village
            if ($villageScene->hasConnectionGroup(VillageModule::Groups[0])) {
                $villageScene
                    ->getConnectionGroup(VillageModule::Groups[0])
                    ->connect($trainingScene->getConnectionGroup(TrainingGround::ActionGroups["back"][0]));
            } else {
                $villageScene->connect($trainingScene->getConnectionGroup(TrainingGround::ActionGroups["back"][0]));
            }

            $g->getEntityManager()->persist($trainingScene);

            $generatedScenes["yard"][] = $trainingScene->getId();
        }

        $module->setProperty(self::GeneratedSceneProperty, $generatedScenes);

        // Read in masters.
        $file = new SplFileObject(__DIR__ . "/../res/masters.tsv");
        $titles = $file->fgetc("\t"); // must fetch title line first
        while (!$file->eof()) {
            $data = $file->fgetcsv("\t");
            $data = [
                "name" => $data[0],
                "weapon" => $data[1],
                "level" => intval($data[2]),
                "attack" => intval($data[3]),
                "defense" => intval($data[4]),
                "maxHealth" => intval($data[5]),
            ];

            $creature = call_user_func([Master::class, "create"], $data);
            $g->getEntityManager()->persist($creature);
        }
    }

    public static function onUnregister(Game $g, ModuleModel $module)
    {
        $em = $g->getEntityManager();

        // delete training grounds
        $scenes = $g->getEntityManager()->getRepository(Scene::class)
            ->findBy(["template" => TrainingGround::Template]);
        foreach($scenes as $scene) {
            $g->getEntityManager()->remove($scene);
        }

        // empty masters
        // @ToDo: Put this into a method.
        $cmd = $em->getClassMetadata(Master::class);
        $connection = $em->getConnection();
        $dbPlatform = $connection->getDatabasePlatform();
        $connection->beginTransaction();
        $q = $dbPlatform->getTruncateTableSql($cmd->getTableName());
        $connection->executeUpdate($q);
        $connection->commit();

        $g->getEntityManager()->flush();
    }
}
