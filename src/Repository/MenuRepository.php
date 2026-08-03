<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Menu;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MenuRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Menu::class);
    }

    public function findPublicWithContent(Business $business, string $slug, bool $includeDraft = false): ?Menu
    {
        $query = $this->createQueryBuilder('menu')
            ->addSelect('category', 'item')
            ->leftJoin('menu.categories', 'category', 'WITH', 'category.isVisible = true')
            ->leftJoin('category.items', 'item', 'WITH', 'item.isAvailable = true')
            ->andWhere('menu.business = :business')
            ->andWhere('menu.slug = :slug')
            ->setParameter('business', $business)
            ->setParameter('slug', $slug)
            ->addOrderBy('category.sortOrder', 'ASC')
            ->addOrderBy('category.id', 'ASC')
            ->addOrderBy('item.sortOrder', 'ASC')
            ->addOrderBy('item.id', 'ASC');

        if (!$includeDraft) {
            $query->andWhere('menu.status = :status')->setParameter('status', Menu::STATUS_PUBLISHED);
        }

        return $query->getQuery()->getOneOrNullResult();
    }

    public function hasOtherPublishedMenu(Business $business, int $menuId): bool
    {
        return (int) $this->createQueryBuilder('menu')
            ->select('COUNT(menu.id)')
            ->andWhere('menu.business = :business')
            ->andWhere('menu.status = :status')
            ->andWhere('menu.id != :menuId')
            ->setParameter('business', $business)
            ->setParameter('status', Menu::STATUS_PUBLISHED)
            ->setParameter('menuId', $menuId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
