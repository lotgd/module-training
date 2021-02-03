<?php
declare(strict_types=1);

namespace LotGD\Module\Training\Tests;

use Doctrine\Common\Annotations\AnnotationRegistry;
use LotGD\Core\Action;
use LotGD\Core\Exceptions\ArgumentException;
use LotGD\Core\GameBuilder;
use LotGD\Core\LibraryConfigurationManager;
use LotGD\Core\ModelExtender;
use LotGD\Core\Models\Viewpoint;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

use LotGD\Core\Configuration;
use LotGD\Core\Game;
use LotGD\Core\Tests\ModelTestCase;
use LotGD\Core\Models\Module as ModuleModel;

use LotGD\Module\Training\Module;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Yaml\Yaml;

class ModuleTestCase extends ModelTestCase
{
    const Library = 'lotgd/module-training';
    const RootNamespace = "LotGD\\Module\\Training\\";

    public $g;
    protected $moduleModel;

    public function getDataSet(): array
    {
        return Yaml::parseFile(implode(DIRECTORY_SEPARATOR, [__DIR__, 'datasets', 'module.yml']));
    }

    public function getCwd(): string
    {
        return implode(DIRECTORY_SEPARATOR, [__DIR__, '..']);
    }

    public function setUp(): void
    {
        parent::setUp();

        // Register and unregister before/after each test, since
        // handleEvent() calls may expect the module be registered (for example,
        // if they read properties from the model).
        $this->moduleModel = new ModuleModel(self::Library);
        $this->moduleModel->save($this->getEntityManager());
        Module::onRegister($this->g, $this->moduleModel);

        $this->g->getEntityManager()->flush();
        $this->g->getEntityManager()->clear();
    }

    public function tearDown(): void
    {
        $this->g->getEntityManager()->flush();
        $this->g->getEntityManager()->clear();

        parent::tearDown();

        Module::onUnregister($this->g, $this->moduleModel);
        $m = $this->getEntityManager()->getRepository(ModuleModel::class)->find(self::Library);
        if ($m) {
            $m->delete($this->getEntityManager());
        }
    }

    protected function searchAction(Viewpoint $viewpoint, array $actionParams, ?string $groupTitle = null): ?Action
    {
        if (count($actionParams) != 2) {
            throw new ArgumentException("$actionParams is expected to be an array of exactly 2 items.");
        }

        if (is_string($actionParams[0]) === false) {
            throw new ArgumentException("$actionParams[0] is expected to be a method.");
        }

        $methodToCheck = $actionParams[0];
        $valueToHave = $actionParams[1];
        $checkedOnce = false;


        $groups = $viewpoint->getActionGroups();
        $found = null;

        foreach ($groups as $group) {
            $actions = $group->getActions();
            foreach ($actions as $action) {
                if ($checkedOnce === false and method_exists($action, $methodToCheck) === false) {
                    throw new ArgumentException("$actionParams[0] must be a valid method of " . Action::class . ".");
                } else {
                    $checkedOnce = True;
                }

                # Using KNF, !A or B is only false if A is true and B is not.
                if ($action->$methodToCheck() == $valueToHave and (!is_null($groupTitle) or $group->getTitle() === $groupTitle)) {
                    $found = $action;
                }
            }
        }

        return $found;
    }
}