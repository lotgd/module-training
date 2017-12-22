<?php
declare(strict_types=1);

namespace LotGD\Module\Training\Managers;

use Doctrine\Common\Collections\Criteria;
use LotGD\Core\Game;
use LotGD\Module\Training\Models\Master;

/**
 * Class MasterManager. This class offers an easy method to get a master matching a certain level.
 * @package LotGD\Module\Academy\Managers
 */
class MasterManager
{
    private $game;

    public function __construct(Game $game)
    {
        $this->game = $game;
    }

    /**
     * Returns a master of a level matching $level. If none is found, it returns the next weaker master. If absolutely no
     * master is found, it creates a dummy master as a final fall back.
     * @param int $level
     * @return Master
     */
    public function getMaster(int $level): Master
    {
        $criteria = new Criteria();
        $criteria
            ->where($criteria->expr()->lte('level', $level))
            ->orderBy(["level" => "DESC"]);

        $masters = $this->game->getEntityManager()->getRepository(Master::class)->matching($criteria);

        if (count($masters) === 0) {
            $character = $this->game->getCharacter();
            $master = new Master();
            $master->setName(sprintf("Scarecrow"));
            $master->setWeapon("Straw raven");
            $master->setAttack($character->getAttack($this->game));
            $master->setDefense($character->getDefense($this->game));
            $master->setLevel($character->getLevel());
            $master->setMaxHealth($character->getMaxHealth());
            $master->setHealth($master->getMaxHealth());

            // No detaching needed since it is new.
        } else {
            $master = $masters[0];

            // Detach the creature: User is fighting against a clone of the creature with it's own health pool, possibly upscaled
            $this->game->getEntityManager()->detach($master);
        }

        return $master;
    }
}