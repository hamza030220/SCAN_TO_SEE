<?php

namespace App\Command;

use App\Entity\Business;
use App\Entity\Category;
use App\Entity\Item;
use App\Entity\Menu;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:seed-owner', description: 'Seed demo data for an owner account')]
class SeedOwnerCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email',    InputArgument::REQUIRED, 'Owner email')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain-text password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email    = $input->getArgument('email');
        $password = $input->getArgument('password');

        // ── Get or create the owner user ─────────────────────────────────────
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $user->setFullName('Zuss Limani');
            $user->setRole('owner');
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->em->persist($user);
            $io->text("Created user: {$email}");
        } else {
            $io->text("Found existing user: {$email}");
        }

        $this->em->flush();

        // ── Seed data ────────────────────────────────────────────────────────
        $catalog = [
            'Blue Bean Café' => [
                'Lunch Menu' => [
                    'Hot Drinks'  => [
                        ['Espresso',       'Double shot',               '3.50'],
                        ['Cappuccino',     'With steamed milk',         '4.50'],
                        ['Café Latte',     'Oat milk option available', '5.00'],
                        ['Americano',      null,                        '3.00'],
                    ],
                    'Cold Drinks' => [
                        ['Iced Latte',     'Served over ice',           '5.50'],
                        ['Cold Brew',      '12-hour steep',             '6.00'],
                        ['Fresh Juice',    'Orange or carrot',          '4.00'],
                    ],
                    'Food'        => [
                        ['Avocado Toast',  'Sourdough, poached egg',    '12.00'],
                        ['Seasonal Tart',  'Limited availability',      '7.00'],
                        ['Granola Bowl',   'With yoghurt and honey',    '9.00'],
                    ],
                ],
                'Brunch Weekend' => [
                    'Mains'   => [
                        ['Full Breakfast', 'Eggs, bacon, sausage, toast', '16.00'],
                        ['Eggs Benedict',  'Hollandaise, ham',            '14.00'],
                        ['Pancake Stack',  'Maple syrup and berries',     '11.00'],
                    ],
                    'Desserts' => [
                        ['Crème Brûlée',   'Classic vanilla',             '8.00'],
                        ['Chocolate Tart', 'Served warm',                 '9.00'],
                    ],
                ],
            ],
            'Aura Wellness Spa' => [
                'Massage Treatments' => [
                    'Classic'   => [
                        ['Swedish Massage',    '60 min relaxation',         '90.00'],
                        ['Deep Tissue',        '90 min, sports recovery',   '130.00'],
                        ['Hot Stone',          'Volcanic stones + oil',     '120.00'],
                    ],
                    'Signature' => [
                        ['Aura Full Body',     '120 min premium ritual',    '180.00'],
                        ['Couples Massage',    '60 min side by side',       '160.00'],
                    ],
                ],
                'Facial Menu' => [
                    'Express'   => [
                        ['Glow Facial',        '30 min brightening',        '55.00'],
                        ['Hydration Boost',    '45 min moisture therapy',   '75.00'],
                    ],
                    'Advanced'  => [
                        ['Anti-Ageing Ritual', '75 min collagen lift',      '110.00'],
                        ['LED Light Therapy',  '60 min rejuvenation',       '95.00'],
                    ],
                ],
            ],
            'Neon Cuts Studio' => [
                'Hair Services' => [
                    'Men'   => [
                        ['Classic Haircut',    'Scissors or clippers',      '25.00'],
                        ['Skin Fade',          'Taper + line up',           '30.00'],
                        ['Beard Trim',         'Shape and define',          '15.00'],
                        ['Hair + Beard',       'Full service combo',        '40.00'],
                    ],
                    'Women' => [
                        ['Blow Dry & Style',   'Wash, blow, style',         '35.00'],
                        ['Haircut & Style',    'Cut, wash, dry',            '55.00'],
                        ['Balayage',           'Hand-painted highlights',   '120.00'],
                    ],
                ],
            ],
        ];

        foreach ($catalog as $businessName => $menus) {
            $business = new Business();
            $business->setOwner($user);
            $business->setName($businessName);
            $this->em->persist($business);

            foreach ($menus as $menuName => $categories) {
                $menu = new Menu();
                $menu->setBusiness($business);
                $menu->setName($menuName);
                $menu->setSlug($this->makeSlug($menuName));
                $menu->setStatus('published');
                $menu->setCurrency('TND');
                $menu->setThemePreset('modern');
                $this->em->persist($menu);

                $catSort = 0;
                foreach ($categories as $catName => $items) {
                    $category = new Category();
                    $category->setMenu($menu);
                    $category->setName($catName);
                    $category->setIsVisible(true);
                    $category->setSortOrder($catSort++);
                    $this->em->persist($category);

                    $itemSort = 0;
                    foreach ($items as [$itemName, $desc, $price]) {
                        $item = new Item();
                        $item->setCategory($category);
                        $item->setName($itemName);
                        $item->setShortDescription($desc);
                        $item->setPrice($price);
                        $item->setIsAvailable(true);
                        $item->setSortOrder($itemSort++);
                        $this->em->persist($item);
                    }
                }
            }
        }

        $this->em->flush();

        $io->success("Seeded " . count($catalog) . " businesses with menus, categories, and items for: {$email}");
        return Command::SUCCESS;
    }

    private function makeSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'menu';
        return $base . '-' . bin2hex(random_bytes(3));
    }
}
